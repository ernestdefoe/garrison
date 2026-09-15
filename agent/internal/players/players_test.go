package players

import (
	"strings"
	"testing"
)

func watcher(t *testing.T, cfg Config) *Watcher {
	t.Helper()

	w, err := New(cfg)
	if err != nil {
		t.Fatal(err)
	}

	if w == nil {
		t.Fatal("no watcher was built")
	}

	return w
}

func feed(w *Watcher, lines ...string) {
	for _, l := range lines {
		w.Observe(l)
	}
}

func TestMinecraftJoinsAndLeaves(t *testing.T) {
	w := watcher(t, Config{Preset: "minecraft"})

	feed(w,
		"[12:00:00] [Server thread/INFO]: alice joined the game",
		"[12:00:05] [Server thread/INFO]: bob joined the game",
		"[12:01:00] [Server thread/INFO]: alice left the game",
	)

	got := strings.Join(w.Online(), ",")

	if got != "bob" {
		t.Fatalf("online is %q, want bob", got)
	}
}

/*
🚨 THE ONE THAT MATTERS MOST IN THIS FILE.

Chat is in the same log as the server's own lines. A loose pattern like
`(.+) joined` matches a PLAYER SAYING "bob joined the game" — which means
anybody in the game can invent a player, and worse, can evict a real one by
typing their name next to the leave wording. Playtime records and "who is
online" both become things any player can forge from chat.
*/
func TestChatCannotForgeAJoinOrALeave(t *testing.T) {
	w := watcher(t, Config{Preset: "minecraft"})

	feed(w, "[12:00:00] [Server thread/INFO]: alice joined the game")

	feed(w,
		"[12:00:10] [Server thread/INFO]: <mallory> bob joined the game",
		"[12:00:11] [Server thread/INFO]: <mallory> alice left the game",
		"[12:00:12] [Server thread/INFO]: [mallory] carol joined the game",
	)

	got := strings.Join(w.Online(), ",")

	if got != "alice" {
		t.Fatalf("online is %q, want just alice — chat forged a change", got)
	}
}

func TestAStoppedServerHasNobodyInIt(t *testing.T) {
	w := watcher(t, Config{Preset: "minecraft"})

	feed(w, "[12:00:00] [Server thread/INFO]: alice joined the game")
	w.Reset()

	if len(w.Online()) != 0 {
		t.Fatalf("online is %v after a reset", w.Online())
	}
}

// The same player joining twice — a reconnect whose leave was never logged —
// must not produce two of them.
func TestTheSetDoesNotDuplicate(t *testing.T) {
	w := watcher(t, Config{Preset: "minecraft"})

	feed(w,
		"[12:00:00] [Server thread/INFO]: alice joined the game",
		"[12:05:00] [Server thread/INFO]: alice joined the game",
	)

	if got := w.Online(); len(got) != 1 {
		t.Fatalf("online is %v, want one alice", got)
	}
}

// A leave for somebody who was never seen joining is not an error, and must not
// make the set negative in any way. Agents start mid-session all the time.
func TestALeaveForAnUnknownPlayerIsHarmless(t *testing.T) {
	w := watcher(t, Config{Preset: "minecraft"})

	feed(w, "[12:00:00] [Server thread/INFO]: ghost left the game")

	if got := w.Online(); len(got) != 0 {
		t.Fatalf("online is %v", got)
	}
}

// 🚨 Sorted, so successive reports compare cleanly and a panel does not
// reshuffle its rows on every fifteen-second poll.
func TestOnlineIsSorted(t *testing.T) {
	w := watcher(t, Config{Preset: "minecraft"})

	feed(w,
		"[12:00:00] [Server thread/INFO]: zoe joined the game",
		"[12:00:01] [Server thread/INFO]: alice joined the game",
		"[12:00:02] [Server thread/INFO]: mike joined the game",
	)

	if got := strings.Join(w.Online(), ","); got != "alice,mike,zoe" {
		t.Fatalf("online is %q, want it sorted", got)
	}
}

/*
🚨 A pattern with no `name` group matches lines and produces nothing, so the
server would report a player called "" joining and leaving for ever. Refusing at
compile time means the operator finds out on their next agent start rather than
from a panel full of blank rows.
*/
func TestAPatternWithoutANameGroupIsRefused(t *testing.T) {
	_, err := New(Config{Join: `(.+) joined`, Leave: `(.+) left`})

	if err == nil {
		t.Fatal("a pattern with no name group was accepted")
	}

	if !strings.Contains(err.Error(), "name") {
		t.Fatalf("refused, but the message does not say why: %v", err)
	}
}

func TestAnInvalidPatternIsRefusedWithTheReason(t *testing.T) {
	_, err := New(Config{Join: `(?P<name>[`, Leave: `(?P<name>x)`})

	if err == nil {
		t.Fatal("an invalid regular expression was accepted")
	}
}

func TestAnUnknownPresetNamesTheOnesThatExist(t *testing.T) {
	_, err := New(Config{Preset: "half-life-but-invented"})

	if err == nil {
		t.Fatal("an unknown preset was accepted")
	}

	if !strings.Contains(err.Error(), "minecraft") {
		t.Fatalf("the error does not list the real presets: %v", err)
	}
}

// An explicit pattern beats the preset: somebody with a modded server has a
// good reason to override one half and keep the other.
func TestAnExplicitPatternOverridesHalfAPreset(t *testing.T) {
	w := watcher(t, Config{
		Preset: "minecraft",
		Join:   `\]: (?P<name>.+) has entered the realm$`,
	})

	feed(w,
		"[12:00:00] [Server thread/INFO]: alice has entered the realm",
		"[12:00:01] [Server thread/INFO]: bob joined the game",
	)

	if got := strings.Join(w.Online(), ","); got != "alice" {
		t.Fatalf("online is %q — the override did not take, or the preset's leave was lost", got)
	}
}

// 🚨 A name is partly player-controlled in many games. An empty or enormous one
// reaching the forum becomes a row in a table and a link on a profile page.
func TestAbsurdNamesAreRefused(t *testing.T) {
	w := watcher(t, Config{
		Join:  `JOIN (?P<name>.*)$`,
		Leave: `LEAVE (?P<name>.*)$`,
	})

	feed(w,
		"JOIN ",
		"JOIN "+strings.Repeat("a", 200),
	)

	if got := w.Online(); len(got) != 0 {
		t.Fatalf("online is %v, want nothing accepted", got)
	}
}

func TestSayRendersTheOperatorsTemplate(t *testing.T) {
	w := watcher(t, Config{Preset: "minecraft"})

	got := w.Say("alice", "Garrison code: 4821")

	if got != "tell alice Garrison code: 4821" {
		t.Fatalf("say rendered %q", got)
	}

	if !w.CanSay() {
		t.Fatal("CanSay is false for a preset that has one")
	}
}

// 🚨 A server with no whisper command cannot verify anybody, and must say so
// rather than silently sending nothing — the forum offers the link flow only
// where it can actually complete.
func TestAServerWithoutAWhisperCommandSaysSo(t *testing.T) {
	w := watcher(t, Config{Preset: "valheim"})

	if w.CanSay() {
		t.Fatal("CanSay is true for a preset with no say template")
	}

	if got := w.Say("alice", "hello"); got != "" {
		t.Fatalf("say rendered %q for a server that cannot whisper", got)
	}
}

func TestNoConfigurationMeansNoWatcher(t *testing.T) {
	w, err := New(Config{})

	if err != nil {
		t.Fatalf("an empty config errored: %v", err)
	}

	if w != nil {
		t.Fatal("a watcher was built from nothing")
	}
}
