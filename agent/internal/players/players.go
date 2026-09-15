// Package players works out who is in a game server, by reading its log.
//
// 🚨 IT REPORTS A SET, NOT EVENTS, AND THAT IS THE WHOLE DESIGN.
//
// The obvious approach is to ship "alice joined" and "alice left" as events and
// let the forum pair them into sessions. It works until something goes wrong,
// and then it never recovers: a dropped poll loses a leave and alice is online
// for ever; an agent restart loses every pairing; a log rotation replays joins
// that already happened. Every one of those leaves a forum confidently showing
// somebody in a game they left last Tuesday, and there is no self-correction
// because the forum only ever hears about changes.
//
// So the agent reports WHO IS ONLINE NOW, every poll, as a set. The forum diffs
// it against what it believed and opens or closes sessions accordingly. A
// missed poll costs precision bounded by the poll interval; an agent restart
// costs the current sessions and nothing else; and the very next report
// silently corrects any disagreement, because the set is authoritative rather
// than a running total nobody can audit.
package players

import (
	"fmt"
	"regexp"
	"sort"
	"strings"
	"sync"
)

// Config is what an operator declared about reading players from a log.
type Config struct {
	/*
	 * Preset names a built-in pattern pair for a known game, so the common
	 * case needs no regular expressions at all.
	 *
	 * 🚨 Presets exist because the audience for this product is somebody who
	 * runs a Minecraft server for their friends, not somebody who enjoys
	 * writing regex against log formats. A feature that only works for people
	 * who can debug a capture group is a feature most buyers will never turn
	 * on.
	 */
	Preset string `json:"preset,omitempty"`

	// Join and Leave override the preset. Each needs one capture group called
	// `name`.
	Join  string `json:"join,omitempty"`
	Leave string `json:"leave,omitempty"`

	/*
	 * Say is the console command that sends a private message to one player,
	 * as a template with {player} and {message}.
	 *
	 * Used to deliver a verification code in-game, which is how the forum
	 * proves somebody actually controls the account they are claiming. Without
	 * it, linking is somebody typing a name they saw on a leaderboard.
	 */
	Say string `json:"say,omitempty"`
}

// Configured reports whether anything can be read at all.
func (c Config) Configured() bool {
	return c.Preset != "" || (c.Join != "" && c.Leave != "")
}

/*
presets are the log lines the common servers actually print.

🚨 Anchored and specific on purpose. A loose pattern like `(.+) joined` also
matches a player SAYING "bob joined the game" in chat, and chat is in the same
log — so anybody could invent a player, or worse, evict a real one by saying
their name next to the leave wording. Every pattern here matches the server's
own line and requires the parts around the name that a chat line cannot have.
*/
var presets = map[string]Config{
	// "[12:00:00] [Server thread/INFO]: alice joined the game"
	// A chat line is "<alice> bob joined the game", so requiring the colon and
	// space at the start of the message, with no "<" after it, excludes chat.
	"minecraft": {
		Join:  `\]: (?P<name>[A-Za-z0-9_]{1,16}) joined the game$`,
		Leave: `\]: (?P<name>[A-Za-z0-9_]{1,16}) left the game$`,
		Say:   `tell {player} {message}`,
	},

	// Valheim writes ZDOID lines when a character spawns and a disconnect line
	// with the Steam id. The name is only on the spawn line.
	"valheim": {
		Join:  `Got character ZDOID from (?P<name>.+) : \d`,
		Leave: `Destroying abandoned non persistent zdo .* owner (?P<name>.+)$`,
	},

	// "2026.09.15_12.00.00: alice joined this ARK!"
	"ark": {
		Join:  `: (?P<name>.+) joined this ARK!`,
		Leave: `: (?P<name>.+) left this ARK!`,
	},

	// Rust / Oxide.
	"rust": {
		Join:  `(?P<name>.+) joined \[`,
		Leave: `(?P<name>.+) disconnecting:`,
	},

	// Terraria.
	"terraria": {
		Join:  `(?P<name>.+) has joined\.$`,
		Leave: `(?P<name>.+) has left\.$`,
		Say:   `say {message}`,
	},

	// Factorio.
	"factorio": {
		Join:  `\[JOIN\] (?P<name>.+) joined the game$`,
		Leave: `\[LEAVE\] (?P<name>.+) left the game$`,
		Say:   `/whisper {player} {message}`,
	},
}

// Presets lists the built-in names, for documentation and for a panel that
// wants to offer them.
func Presets() []string {
	out := make([]string, 0, len(presets))
	for name := range presets {
		out = append(out, name)
	}

	sort.Strings(out)

	return out
}

// Watcher keeps the current set of players for one server.
type Watcher struct {
	mu sync.Mutex

	join  *regexp.Regexp
	leave *regexp.Regexp
	say   string

	online map[string]struct{}
}

// New compiles a watcher, or explains why it cannot.
func New(cfg Config) (*Watcher, error) {
	if !cfg.Configured() {
		return nil, nil
	}

	resolved := cfg

	if cfg.Preset != "" {
		preset, ok := presets[strings.ToLower(cfg.Preset)]
		if !ok {
			return nil, fmt.Errorf("no player preset called %q — try one of %s", cfg.Preset, strings.Join(Presets(), ", "))
		}

		// An explicit pattern always beats the preset: somebody with a modded
		// server has a good reason to override one half and keep the other.
		if resolved.Join == "" {
			resolved.Join = preset.Join
		}
		if resolved.Leave == "" {
			resolved.Leave = preset.Leave
		}
		if resolved.Say == "" {
			resolved.Say = preset.Say
		}
	}

	join, err := compile("join", resolved.Join)
	if err != nil {
		return nil, err
	}

	leave, err := compile("leave", resolved.Leave)
	if err != nil {
		return nil, err
	}

	return &Watcher{join: join, leave: leave, say: resolved.Say, online: map[string]struct{}{}}, nil
}

/*
compile builds one pattern and insists it can name a player.

🚨 A pattern with no `name` group is accepted by the regexp engine and matches
nothing useful — every line would "match" and produce an empty name, so the
server would report a player called "" joining and leaving for ever. Refusing at
compile time means the operator learns on their next agent start rather than
from a panel full of blank rows.

🚨 Go's regexp is RE2, which is linear-time and cannot backtrack. That matters
here specifically: these patterns come from a config file and run against every
line a busy game server prints. In a backtracking engine a careless pattern is a
denial of service against the agent.
*/
func compile(which, pattern string) (*regexp.Regexp, error) {
	if pattern == "" {
		return nil, fmt.Errorf("the %s pattern is empty", which)
	}

	re, err := regexp.Compile(pattern)
	if err != nil {
		return nil, fmt.Errorf("the %s pattern is not a valid regular expression: %w", which, err)
	}

	for _, name := range re.SubexpNames() {
		if name == "name" {
			return re, nil
		}
	}

	return nil, fmt.Errorf("the %s pattern has no (?P<name>…) group, so it cannot say who joined", which)
}

// Observe feeds one console line to the watcher.
func (w *Watcher) Observe(line string) {
	w.mu.Lock()
	defer w.mu.Unlock()

	if name := extract(w.join, line); name != "" {
		w.online[name] = struct{}{}

		return
	}

	if name := extract(w.leave, line); name != "" {
		delete(w.online, name)
	}
}

// Online returns who is in the game, sorted so successive reports compare
// cleanly and a panel does not reshuffle on every poll.
func (w *Watcher) Online() []string {
	w.mu.Lock()
	defer w.mu.Unlock()

	out := make([]string, 0, len(w.online))
	for name := range w.online {
		out = append(out, name)
	}

	sort.Strings(out)

	return out
}

// Reset empties the set. Used when a server stops: a stopped server has nobody
// in it, and leaving the last known players listed would show a panel full of
// people playing a game that is not running.
func (w *Watcher) Reset() {
	w.mu.Lock()
	defer w.mu.Unlock()

	w.online = map[string]struct{}{}
}

// Say renders the whisper command for one player, or "" if the operator did not
// configure one.
func (w *Watcher) Say(player, message string) string {
	if w.say == "" {
		return ""
	}

	r := strings.NewReplacer("{player}", player, "{message}", message)

	return r.Replace(w.say)
}

// CanSay reports whether in-game verification is possible on this server.
func (w *Watcher) CanSay() bool {
	return w != nil && w.say != ""
}

func extract(re *regexp.Regexp, line string) string {
	match := re.FindStringSubmatch(line)
	if match == nil {
		return ""
	}

	for i, name := range re.SubexpNames() {
		if name != "name" {
			continue
		}

		/*
		 * 🚨 Trimmed, and refused if it is empty or absurd.
		 *
		 * A name comes from a log line that a PLAYER partly controls — many
		 * games let people pick a display name with spaces or colour codes in
		 * it. An empty or enormous "name" reaching the forum becomes a row in
		 * a table and a link on a profile page, so the sane bounds are applied
		 * at the point of reading rather than trusted to every consumer.
		 */
		found := strings.TrimSpace(match[i])

		if found == "" || len(found) > 64 {
			return ""
		}

		return found
	}

	return ""
}
