package agent

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/ernestdefoe/garrison/internal/driver"
	"github.com/ernestdefoe/garrison/internal/players"
	"github.com/ernestdefoe/garrison/internal/protocol"
	"github.com/ernestdefoe/garrison/internal/settings"
)

// fakeDriver records what it was asked to do, so a test can assert that a
// refused request never reached it.
type fakeDriver struct {
	name    string
	calls   []string
	running bool
	lines   []protocol.Line
	sent    []string
	grace   time.Duration
}

func (f *fakeDriver) Name() string                    { return f.name }
func (f *fakeDriver) Available(context.Context) error { return nil }
func (f *fakeDriver) note(s string)                   { f.calls = append(f.calls, s) }
func (f *fakeDriver) Start(_ context.Context, _ driver.Server) error {
	f.note("start")
	f.running = true
	return nil
}
func (f *fakeDriver) Stop(_ context.Context, _ driver.Server, g time.Duration) error {
	f.note("stop")
	f.grace = g
	if !f.running {
		return protocol.Errf(protocol.CodeNotRunning, "not running")
	}
	f.running = false
	return nil
}
func (f *fakeDriver) Status(_ context.Context, s driver.Server) (protocol.Status, error) {
	f.note("status")
	st := protocol.Status{Server: s.ID, Driver: f.name, State: protocol.StateStopped}
	if f.running {
		st.State = protocol.StateRunning
	}
	return st, nil
}
func (f *fakeDriver) Stats(_ context.Context, s driver.Server) (protocol.Stats, error) {
	f.note("stats")
	return protocol.Stats{Server: s.ID, Source: "fake"}, nil
}
func (f *fakeDriver) Tail(_ context.Context, _ driver.Server, _ int, _ bool, sink func(protocol.Line)) error {
	f.note("tail")
	for _, l := range f.lines {
		sink(l)
	}
	return nil
}
func (f *fakeDriver) Send(_ context.Context, _ driver.Server, line string) error {
	f.note("send")
	f.sent = append(f.sent, line)
	return nil
}

func newTestAgent(t *testing.T) (*Agent, *fakeDriver) {
	t.Helper()
	fd := &fakeDriver{name: "fake"}
	a, _ := New(context.Background(),
		[]driver.Server{{ID: "valheim", Name: "Shattered Pact", Driver: "fake", StopGraceSeconds: 45}},
		driver.Set{"fake": fd})
	return a, fd
}

func handle(t *testing.T, a *Agent, req protocol.Request) protocol.Response {
	t.Helper()
	return a.Handle(context.Background(), req, func(protocol.Event) {})
}

// 🚨 THE security test. Everything else in this package is a feature; this is
// the property the product is sold on — a compromised forum cannot make the
// agent do anything the operator did not already configure.
//
// It asserts two things together, because either alone is weaker than it
// looks: the verb is refused, AND no driver method ran. A refusal that
// happened after a side effect would pass a "did it error" test and still be
// a breach.
func TestUnknownVerbsAreRefusedBeforeAnyDriverIsTouched(t *testing.T) {
	attacks := []protocol.Verb{
		"shell.exec",
		"exec",
		"run",
		"server.exec",
		"console.exec",
		"file.read",
		"file.write",
		"agent.update",
		"docker.run",    // naming a driver does not get you one
		"SERVER.START",  // the set is case-sensitive on purpose
		"server.start ", // trailing space is a different string
		"../server.start",
		"",
	}

	for _, verb := range attacks {
		t.Run(string(verb), func(t *testing.T) {
			a, fd := newTestAgent(t)
			res := handle(t, a, protocol.Request{ID: "x", Verb: verb, Server: "valheim"})

			if res.OK {
				t.Fatalf("verb %q was ACCEPTED; the verb set is not closed", verb)
			}
			if res.Error.Code != protocol.CodeUnknownVerb {
				t.Fatalf("verb %q refused as %q, want %q", verb, res.Error.Code, protocol.CodeUnknownVerb)
			}
			if len(fd.calls) != 0 {
				t.Fatalf("verb %q was refused but the driver was still called: %v", verb, fd.calls)
			}
		})
	}
}

// The other half of the boundary: a known verb aimed at a server this agent
// was never configured with must not reach a driver either.
func TestUnknownServerNeverReachesADriver(t *testing.T) {
	a, fd := newTestAgent(t)
	res := handle(t, a, protocol.Request{ID: "x", Verb: protocol.VerbStart, Server: "not-configured"})

	if res.OK {
		t.Fatal("started a server that is not in the config")
	}
	if res.Error.Code != protocol.CodeUnknownServer {
		t.Fatalf("got %q, want %q", res.Error.Code, protocol.CodeUnknownServer)
	}
	if len(fd.calls) != 0 {
		t.Fatalf("driver was called for an unknown server: %v", fd.calls)
	}
}

// Every verb in the closed set must be implemented. This is the test that
// catches adding a constant and forgetting the dispatch case — which would
// otherwise reach a customer as CodeInternal from a button that looks real.
func TestEveryKnownVerbIsImplemented(t *testing.T) {
	a, _ := newTestAgent(t)

	for _, v := range protocol.Verbs() {
		res := handle(t, a, protocol.Request{ID: "x", Verb: v, Server: "valheim"})
		if res.Error != nil && res.Error.Code == protocol.CodeInternal {
			t.Errorf("verb %q is in the known set but has no dispatch case", v)
		}
	}
}

func TestStopUsesTheServerGraceUnlessOverridden(t *testing.T) {
	a, fd := newTestAgent(t)
	handle(t, a, protocol.Request{ID: "1", Verb: protocol.VerbStart, Server: "valheim"})

	handle(t, a, protocol.Request{ID: "2", Verb: protocol.VerbStop, Server: "valheim"})
	if fd.grace != 45*time.Second {
		t.Fatalf("grace = %s, want the configured 45s", fd.grace)
	}

	handle(t, a, protocol.Request{ID: "3", Verb: protocol.VerbStart, Server: "valheim"})
	params, _ := json.Marshal(protocol.StopParams{GraceSeconds: 5})
	handle(t, a, protocol.Request{ID: "4", Verb: protocol.VerbStop, Server: "valheim", Params: params})
	if fd.grace != 5*time.Second {
		t.Fatalf("grace = %s, want the overridden 5s", fd.grace)
	}
}

// 🚨 There must be no path to a zero grace period. Zero means SIGKILL with no
// warning, and a SIGKILL during a world save is a corrupt world — the single
// most expensive thing this software could do to somebody.
func TestGraceCannotBeDrivenToZero(t *testing.T) {
	a, fd := newTestAgent(t)
	handle(t, a, protocol.Request{ID: "1", Verb: protocol.VerbStart, Server: "valheim"})

	for _, n := range []int{0, -1, -3600} {
		params, _ := json.Marshal(protocol.StopParams{GraceSeconds: n})
		res := handle(t, a, protocol.Request{ID: "2", Verb: protocol.VerbStop, Server: "valheim", Params: params})

		if n < 0 {
			if res.OK {
				t.Fatalf("graceSeconds=%d was accepted", n)
			}
			continue
		}
		// Zero is allowed through as "unset", and must fall back to the
		// configured grace rather than meaning "no grace".
		if fd.grace == 0 {
			t.Fatalf("graceSeconds=0 produced a zero grace period; it must mean 'use the default'")
		}
	}
}

// Restart must leave the server running even when it was stopped to begin
// with. "Restart" is a promise about the end state, not a sequence of two
// commands that can half-fail.
func TestRestartWorksFromStopped(t *testing.T) {
	a, fd := newTestAgent(t)
	if fd.running {
		t.Fatal("precondition: should start stopped")
	}

	res := handle(t, a, protocol.Request{ID: "1", Verb: protocol.VerbRestart, Server: "valheim"})
	if !res.OK {
		t.Fatalf("restart from stopped failed: %v", res.Error)
	}
	if !fd.running {
		t.Fatal("restart finished with the server stopped")
	}
}

func TestPingAndInfoNeedNoServer(t *testing.T) {
	a, _ := newTestAgent(t)
	for _, v := range []protocol.Verb{protocol.VerbPing, protocol.VerbAgentInfo, protocol.VerbServerList} {
		res := handle(t, a, protocol.Request{ID: "x", Verb: v})
		if !res.OK {
			t.Fatalf("%s without a server: %v", v, res.Error)
		}
	}
}

func TestAgentInfoReportsTheWholeVerbSet(t *testing.T) {
	a, _ := newTestAgent(t)
	res := handle(t, a, protocol.Request{ID: "x", Verb: protocol.VerbAgentInfo})

	var info protocol.AgentInfo
	if err := json.Unmarshal(res.Data, &info); err != nil {
		t.Fatal(err)
	}
	if len(info.Verbs) != len(protocol.Verbs()) {
		t.Fatalf("agent.info lists %d verbs, the set has %d", len(info.Verbs), len(protocol.Verbs()))
	}
	// An operator who cannot see what the agent will accept cannot audit it.
	for _, v := range info.Verbs {
		if !protocol.Known(protocol.Verb(v)) {
			t.Errorf("agent.info advertises %q, which is not in the known set", v)
		}
	}
}

// 🚨 One server whose backup directory is unreadable, or which has no backup
// paths at all, must not be able to stop the forum hearing about any OTHER
// server on the host.
//
// Backups ride along with the status report, and status is the thing this whole
// product exists to deliver. Trading it against a convenience panel — one
// misconfigured server silencing five healthy ones — is the worst possible
// exchange, and it is exactly what an error return from backupsFor would buy.
func TestABrokenBackupDirectoryDoesNotBreakStatus(t *testing.T) {
	servers := []driver.Server{
		{ID: "no-backups", Name: "No backups", Driver: "fake"},
		{
			ID: "bad-backups", Name: "Bad backups", Driver: "fake",
			BackupRoot:  filepath.Join(t.TempDir(), "gone"),
			BackupPaths: []string{"world"},
			BackupDir:   filepath.Join(t.TempDir(), "nope", "deeper"),
		},
	}

	a, err := New(context.Background(), servers, driver.Set{"fake": &fakeDriver{name: "fake"}})
	if err != nil {
		t.Fatal(err)
	}

	all := a.StatusAll(context.Background())

	if len(all) != 2 {
		t.Fatalf("got %d statuses, want one per server", len(all))
	}

	for _, st := range all {
		if st.State == "" {
			t.Fatalf("%s reported no state at all", st.Server)
		}
		if len(st.Backups) != 0 {
			t.Fatalf("%s reported backups it cannot have: %v", st.Server, st.Backups)
		}
	}
}

// The other half: a server that DOES have backups ships them, newest first and
// capped, so the panel can render without a round trip.
func TestStatusShipsBackupsNewestFirstAndCapped(t *testing.T) {
	root := t.TempDir()
	dir := filepath.Join(t.TempDir(), "backups")

	if err := os.MkdirAll(dir, 0o750); err != nil {
		t.Fatal(err)
	}

	// More than the cap, with distinct modification times.
	made := MaxBackupsShipped + 5
	for i := 0; i < made; i++ {
		name := fmt.Sprintf("srv-2026091%d-12000%d-a%03d.tar.gz", i%10, i%10, i)
		path := filepath.Join(dir, name)
		if err := os.WriteFile(path, []byte("x"), 0o640); err != nil {
			t.Fatal(err)
		}
		when := time.Now().Add(-time.Duration(made-i) * time.Hour)
		if err := os.Chtimes(path, when, when); err != nil {
			t.Fatal(err)
		}
	}

	a, err := New(context.Background(),
		[]driver.Server{{
			ID: "srv", Name: "Server", Driver: "fake",
			BackupRoot: root, BackupPaths: []string{"world"}, BackupDir: dir,
		}},
		driver.Set{"fake": &fakeDriver{name: "fake"}})
	if err != nil {
		t.Fatal(err)
	}

	got := a.StatusAll(context.Background())[0].Backups

	if len(got) != MaxBackupsShipped {
		t.Fatalf("shipped %d backups, cap is %d", len(got), MaxBackupsShipped)
	}

	for i := 1; i < len(got); i++ {
		if got[i].At.After(got[i-1].At) {
			t.Fatalf("backup %d is newer than the one before it — the list is not newest-first", i)
		}
	}
}

/*
🚨 The config verbs must not have widened the security boundary.

Adding verbs is the moment a closed set stops being closed, and the whole
argument for this design is that a fully compromised forum can only ask for
things on the list. config.set writes a FILE on a game host, which is the most
dangerous thing on that list, so it is worth asserting out loud that the id is
the only way a path is produced and that nothing outside the declared list
resolves.
*/
func TestConfigVerbsCannotReachAnUndeclaredFile(t *testing.T) {
	dir := t.TempDir()
	declared := filepath.Join(dir, "server.properties")

	if err := os.WriteFile(declared, []byte("motd=hello\n"), 0o640); err != nil {
		t.Fatal(err)
	}

	victim := filepath.Join(dir, "victim.conf")

	if err := os.WriteFile(victim, []byte("untouched=yes\n"), 0o640); err != nil {
		t.Fatal(err)
	}

	a, err := New(context.Background(),
		[]driver.Server{{
			ID: "srv", Name: "Server", Driver: "fake",
			Config: []settings.File{{ID: "props", Path: declared, Format: settings.FormatProperties}},
		}},
		driver.Set{"fake": &fakeDriver{name: "fake"}})
	if err != nil {
		t.Fatal(err)
	}

	for _, file := range []string{victim, "victim.conf", "../victim.conf", "props/../victim.conf", ""} {
		params, _ := json.Marshal(protocol.ConfigParams{File: file, Key: "untouched", Value: "owned"})

		res := a.Handle(context.Background(), protocol.Request{
			ID: "1", Verb: protocol.VerbConfigSet, Server: "srv", Params: params,
		}, func(protocol.Event) {})

		if res.OK {
			t.Errorf("config.set reached %q", file)
		}
	}

	after, _ := os.ReadFile(victim)

	if string(after) != "untouched=yes\n" {
		t.Fatalf("a file outside the declared list was written: %s", after)
	}
}

// And the declared one does work, so the test above is not passing because
// config.set is broken for everything.
func TestConfigSetWorksOnADeclaredFile(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "server.properties")

	if err := os.WriteFile(path, []byte("# the motd\nmotd=hello\n"), 0o640); err != nil {
		t.Fatal(err)
	}

	a, err := New(context.Background(),
		[]driver.Server{{
			ID: "srv", Name: "Server", Driver: "fake",
			Config: []settings.File{{ID: "props", Path: path, Format: settings.FormatProperties}},
		}},
		driver.Set{"fake": &fakeDriver{name: "fake"}})
	if err != nil {
		t.Fatal(err)
	}

	params, _ := json.Marshal(protocol.ConfigParams{File: "props", Key: "motd", Value: "Shattered Pact"})

	res := a.Handle(context.Background(), protocol.Request{
		ID: "1", Verb: protocol.VerbConfigSet, Server: "srv", Params: params,
	}, func(protocol.Event) {})

	if !res.OK {
		t.Fatalf("config.set on a declared file failed: %+v", res.Error)
	}

	after, _ := os.ReadFile(path)

	if !strings.Contains(string(after), "motd=Shattered Pact") {
		t.Fatalf("the value was not written: %s", after)
	}

	if !strings.Contains(string(after), "# the motd") {
		t.Fatalf("the comment was lost: %s", after)
	}
}

/*
🚨 A crashed server prints no goodbyes.

The player set is built from log lines, so without an explicit reset a crash
leaves everybody who was playing listed as still playing — on the panel somebody
opened precisely because the server went down — and quietly inflates their
recorded playtime for as long as it stays down.
*/
func TestAStoppedServerReportsNobodyPlaying(t *testing.T) {
	d := &fakeDriver{name: "fake", running: true}

	a, err := New(context.Background(),
		[]driver.Server{{
			ID: "srv", Name: "Server", Driver: "fake",
			Players: players.Config{
				Join:  `JOIN (?P<name>.+)$`,
				Leave: `LEAVE (?P<name>.+)$`,
			},
		}},
		driver.Set{"fake": d})
	if err != nil {
		t.Fatal(err)
	}

	a.watcher("srv").Observe("JOIN alice")

	if got := a.StatusAll(context.Background())[0]; len(got.Players) != 1 {
		t.Fatalf("a running server reports %v, want alice", got.Players)
	}

	d.running = false

	got := a.StatusAll(context.Background())[0]

	if len(got.Players) != 0 {
		t.Fatalf("a stopped server still reports %v as playing", got.Players)
	}

	// 🚨 And it must still say it KNOWS, so the panel can tell "nobody is
	// playing" from "this server does not report players" — which look
	// identical in an empty list and mean opposite things.
	if !got.PlayersKnown {
		t.Fatal("a configured server stopped reporting that it knows")
	}
}

// The other half: a server with no player configuration must report that it
// does not know, rather than an empty list that reads as an empty game.
func TestAnUnconfiguredServerDoesNotClaimToKnow(t *testing.T) {
	a, err := New(context.Background(),
		[]driver.Server{{ID: "srv", Name: "Server", Driver: "fake"}},
		driver.Set{"fake": &fakeDriver{name: "fake", running: true}})
	if err != nil {
		t.Fatal(err)
	}

	got := a.StatusAll(context.Background())[0]

	if got.PlayersKnown {
		t.Fatal("a server with no player configuration claimed to know who is playing")
	}
}

// 🚨 A typo in one optional regex must not take a whole host offline. Every
// other verb for that server still works.
func TestABadPlayerPatternDoesNotStopTheAgent(t *testing.T) {
	a, unavailable := New(context.Background(),
		[]driver.Server{
			{ID: "broken", Name: "Broken", Driver: "fake", Players: players.Config{Join: `(?P<name>[`, Leave: `x`}},
			{ID: "fine", Name: "Fine", Driver: "fake"},
		},
		driver.Set{"fake": &fakeDriver{name: "fake", running: true}})

	if a == nil {
		t.Fatal("the agent refused to start")
	}

	if len(a.StatusAll(context.Background())) != 2 {
		t.Fatal("a server went missing")
	}

	var mentioned bool
	for _, u := range unavailable {
		if strings.Contains(u, "players for broken") {
			mentioned = true
		}
	}

	if !mentioned {
		t.Fatalf("the bad pattern was not reported: %v", unavailable)
	}
}
