// Package agent dispatches verbs to drivers and holds the link to the forum.
package agent

import (
	"context"
	"encoding/json"
	"fmt"
	"path/filepath"
	"runtime"
	"sync"
	"time"

	"github.com/ernestdefoe/garrison/internal/backup"
	"github.com/ernestdefoe/garrison/internal/driver"
	"github.com/ernestdefoe/garrison/internal/health"
	"github.com/ernestdefoe/garrison/internal/offsite"
	"github.com/ernestdefoe/garrison/internal/protocol"
)

// Version is the agent build. The forum shows it, and an agent several
// versions behind is itself an incident worth surfacing.
const Version = "0.1.0-spike"

// Agent owns the servers, the drivers and the in-flight streams.
type Agent struct {
	servers map[string]driver.Server
	drivers driver.Set

	mu      sync.Mutex
	streams map[string]context.CancelFunc // by request ID

	// The last off-site copy per server, so status can report it without
	// asking the bucket on every poll. See OffsiteStatus for why.
	offsiteMu   sync.Mutex
	offsiteLast map[string]offsiteOutcome
}

// New builds an agent over the servers it is configured with, keeping only
// the drivers that actually work on this host.
func New(ctx context.Context, servers []driver.Server, candidates driver.Set) (*Agent, []string) {
	a := &Agent{
		servers:     make(map[string]driver.Server, len(servers)),
		drivers:     make(driver.Set),
		streams:     make(map[string]context.CancelFunc),
		offsiteLast: make(map[string]offsiteOutcome),
	}
	for _, s := range servers {
		a.servers[s.ID] = s
	}

	var unavailable []string
	for name, d := range candidates {
		if err := d.Available(ctx); err != nil {
			unavailable = append(unavailable, fmt.Sprintf("%s (%v)", name, err))
			continue
		}
		a.drivers[name] = d
	}
	return a, unavailable
}

// Emit is how a long-running verb pushes events back to the forum.
type Emit func(protocol.Event)

// Handle answers one request. It never panics out to the caller and never
// returns a bare error: every failure is a protocol.Error with a code.
//
// 🚨 The first thing it does is check the verb against the closed set. A verb
// the agent does not implement is refused HERE, before any lookup, any driver,
// any side effect — which is the property the whole security argument rests
// on.
func (a *Agent) Handle(ctx context.Context, req protocol.Request, emit Emit) protocol.Response {
	res := protocol.Response{ID: req.ID}

	if !protocol.Known(req.Verb) {
		res.Error = protocol.Errf(protocol.CodeUnknownVerb, "no such verb %q", req.Verb)
		return res
	}

	var (
		srv driver.Server
		drv driver.Driver
	)
	if protocol.NeedsServer(req.Verb) {
		var ok bool
		srv, ok = a.servers[req.Server]
		if !ok {
			res.Error = protocol.Errf(protocol.CodeUnknownServer, "no server %q on this agent", req.Server)
			return res
		}
		drv, ok = a.drivers[srv.Driver]
		if !ok {
			res.Error = protocol.Errf(protocol.CodeNotSupported,
				"server %q needs the %q driver, which is not available on this host", srv.ID, srv.Driver)
			return res
		}
	}

	data, err := a.dispatch(ctx, req, srv, drv, emit)
	if err != nil {
		if pe, ok := err.(*protocol.Error); ok {
			res.Error = pe
		} else {
			res.Error = protocol.Errf(protocol.CodeInternal, "%v", err)
		}
		return res
	}

	if data != nil {
		b, merr := json.Marshal(data)
		if merr != nil {
			res.Error = protocol.Errf(protocol.CodeInternal, "encoding result: %v", merr)
			return res
		}
		res.Data = b
	}
	res.OK = true
	return res
}

func (a *Agent) dispatch(ctx context.Context, req protocol.Request, srv driver.Server, drv driver.Driver, emit Emit) (any, error) {
	switch req.Verb {

	case protocol.VerbPing:
		return map[string]any{"pong": true, "at": time.Now().UTC()}, nil

	case protocol.VerbAgentInfo:
		verbs := make([]string, 0)
		for _, v := range protocol.Verbs() {
			verbs = append(verbs, string(v))
		}
		return protocol.AgentInfo{
			Version: Version,
			OS:      runtime.GOOS,
			Arch:    runtime.GOARCH,
			Drivers: a.drivers.Names(),
			Verbs:   verbs,
			Servers: len(a.servers),
		}, nil

	case protocol.VerbServerList:
		out := make([]map[string]string, 0, len(a.servers))
		for _, s := range a.servers {
			out = append(out, map[string]string{"id": s.ID, "name": s.Name, "driver": s.Driver})
		}
		return out, nil

	case protocol.VerbStatus:
		return drv.Status(ctx, srv)

	case protocol.VerbStart:
		if err := drv.Start(ctx, srv); err != nil {
			return nil, err
		}
		return drv.Status(ctx, srv)

	case protocol.VerbStop:
		grace, err := graceFrom(req, srv)
		if err != nil {
			return nil, err
		}
		if err := drv.Stop(ctx, srv, grace); err != nil {
			return nil, err
		}
		return drv.Status(ctx, srv)

	case protocol.VerbRestart:
		grace, err := graceFrom(req, srv)
		if err != nil {
			return nil, err
		}
		// A stop that fails because it was already stopped must not abort the
		// restart — "restart" means "be running afterwards".
		if err := drv.Stop(ctx, srv, grace); err != nil {
			if pe, ok := err.(*protocol.Error); !ok || pe.Code != protocol.CodeNotRunning {
				return nil, err
			}
		}
		if err := drv.Start(ctx, srv); err != nil {
			return nil, err
		}
		return drv.Status(ctx, srv)

	case protocol.VerbStats:
		return drv.Stats(ctx, srv)

	case protocol.VerbConsoleSend:
		var p protocol.SendParams
		if err := decode(req.Params, &p); err != nil {
			return nil, err
		}
		if p.Line == "" {
			return nil, protocol.Errf(protocol.CodeBadRequest, "console.send needs a line")
		}
		if err := drv.Send(ctx, srv, p.Line); err != nil {
			return nil, err
		}
		return map[string]any{"sent": true}, nil

	case protocol.VerbBackupCreate:
		cfg, err := backupConfig(srv)
		if err != nil {
			return nil, err
		}

		b, err := backup.Create(ctx, cfg, srv.Name, false)
		if err != nil {
			return nil, protocol.Errf(protocol.CodeDriverFailed, "%v", err)
		}

		// Retention runs after a successful create, never before: pruning
		// first would make room by deleting an old backup and then, if the new
		// one failed, leave the operator with fewer than they started with.
		removed, _ := backup.Prune(cfg)

		/*
		 * 🚨 The off-site copy happens AFTER the local one is complete, and its
		 * failure does not fail the backup.
		 *
		 * A local archive that exists is worth more than an upload that
		 * worked: the common disasters — a bad mod, a wiped world, a restore
		 * from the wrong save — are all recovered from the local copy, and the
		 * off-site one is for the rarer case of losing the host. Returning an
		 * error here because a bucket was unreachable would tell an operator
		 * their backup failed when it plainly did not, and the honest version
		 * of that sentence is what `offsite` in the result says instead.
		 */
		result := map[string]any{"backup": b, "pruned": removed}

		if srv.Offsite.Configured() {
			result["offsite"] = a.copyOffsite(ctx, srv, cfg, b)
		}

		return result, nil

	case protocol.VerbBackupList:
		cfg, err := backupConfig(srv)
		if err != nil {
			return nil, err
		}

		list, lerr := backup.List(cfg)
		if lerr != nil {
			return nil, protocol.Errf(protocol.CodeDriverFailed, "%v", lerr)
		}

		return map[string]any{"backups": list}, nil

	case protocol.VerbBackupRestore:
		cfg, err := backupConfig(srv)
		if err != nil {
			return nil, err
		}

		var p protocol.BackupParams
		if derr := decode(req.Params, &p); derr != nil {
			return nil, derr
		}

		st, serr := drv.Status(ctx, srv)
		if serr != nil {
			return nil, protocol.Errf(protocol.CodeDriverFailed, "cannot determine whether the server is running: %v", serr)
		}

		/*
		 * 🚨 The RUNNING state is read here, not taken from the request.
		 * Letting the caller assert "it is stopped" would make the one check
		 * standing between a live server and a corrupted world a claim by
		 * whoever is asking.
		 */
		safety, rerr := backup.Restore(ctx, cfg, p.ID, st.State == protocol.StateRunning)
		if rerr != nil {
			return nil, protocol.Errf(protocol.CodeDriverFailed, "%v", rerr)
		}

		return map[string]any{"restored": p.ID, "safety": safety}, nil

	case protocol.VerbBackupDelete:
		cfg, err := backupConfig(srv)
		if err != nil {
			return nil, err
		}

		var p protocol.BackupParams
		if derr := decode(req.Params, &p); derr != nil {
			return nil, derr
		}

		if derr := backup.Delete(cfg, p.ID); derr != nil {
			return nil, protocol.Errf(protocol.CodeDriverFailed, "%v", derr)
		}

		return map[string]any{"deleted": p.ID}, nil

	case protocol.VerbConsoleTail:
		var p protocol.TailParams
		if err := decode(req.Params, &p); err != nil {
			return nil, err
		}
		if p.History <= 0 {
			p.History = 200
		}
		return a.tail(ctx, req, srv, drv, p, emit)
	}

	// Unreachable: Known() gates every verb before this point. Returning an
	// error rather than panicking means adding a verb to the set and
	// forgetting the case here is a clean refusal, not a dead agent.
	return nil, protocol.Errf(protocol.CodeInternal, "verb %q is known but not implemented", req.Verb)
}

func (a *Agent) tail(ctx context.Context, req protocol.Request, srv driver.Server, drv driver.Driver, p protocol.TailParams, emit Emit) (any, error) {
	if !p.Follow {
		// Bounded: read the scrollback, answer, done.
		var lines []protocol.Line
		if err := drv.Tail(ctx, srv, p.History, false, func(l protocol.Line) {
			lines = append(lines, l)
		}); err != nil {
			return nil, err
		}
		return map[string]any{"lines": lines}, nil
	}

	// Following: answer immediately so the caller is not left waiting, then
	// stream events under this request's ID until it is cancelled.
	streamCtx, cancel := context.WithCancel(context.WithoutCancel(ctx))

	a.mu.Lock()
	if old, ok := a.streams[req.ID]; ok {
		old() // the same ID twice replaces the stream rather than duplicating it
	}
	a.streams[req.ID] = cancel
	a.mu.Unlock()

	go func() {
		defer func() {
			a.mu.Lock()
			delete(a.streams, req.ID)
			a.mu.Unlock()
			cancel()
		}()

		_ = drv.Tail(streamCtx, srv, p.History, true, func(l protocol.Line) {
			b, err := json.Marshal(l)
			if err != nil {
				return
			}
			emit(protocol.Event{Stream: req.ID, Kind: "console", At: l.At, Data: b})
		})
	}()

	return map[string]any{"streaming": true, "stream": req.ID}, nil
}

// CancelStream ends a following tail. The forum calls this when a console tab
// closes; without it an agent accumulates one `docker logs --follow` per tab
// anybody ever opened.
func (a *Agent) CancelStream(id string) bool {
	a.mu.Lock()
	defer a.mu.Unlock()
	cancel, ok := a.streams[id]
	if ok {
		cancel()
		delete(a.streams, id)
	}
	return ok
}

// CancelAll ends every stream, for a clean shutdown.
func (a *Agent) CancelAll() {
	a.mu.Lock()
	defer a.mu.Unlock()
	for id, cancel := range a.streams {
		cancel()
		delete(a.streams, id)
	}
}

// offsiteStatus reports what this agent knows about remote copies for a server.
func (a *Agent) offsiteStatus(s driver.Server) *protocol.OffsiteStatus {
	if !s.Offsite.Configured() {
		return nil
	}

	status := &protocol.OffsiteStatus{Configured: true, Bucket: s.Offsite.Bucket}

	a.offsiteMu.Lock()
	last, ok := a.offsiteLast[s.ID]
	a.offsiteMu.Unlock()

	if ok {
		status.LastAt = &last.at
		status.LastOK = last.ok
		status.LastError = last.err
	}

	return status
}

// rememberOffsite records the outcome of one copy.
type offsiteOutcome struct {
	at  time.Time
	ok  bool
	err string
}

func (a *Agent) rememberOffsite(serverID string, ok bool, errText string) {
	a.offsiteMu.Lock()
	defer a.offsiteMu.Unlock()

	if a.offsiteLast == nil {
		a.offsiteLast = map[string]offsiteOutcome{}
	}

	a.offsiteLast[serverID] = offsiteOutcome{at: time.Now().UTC(), ok: ok, err: errText}
}

// copyOffsite uploads one finished archive and reports how it went.
//
// 🚨 Never returns an error, because none of its callers should fail on one.
// The shape it returns is what the forum shows: "copied", or "not copied and
// here is the provider's own reason". An operator debugging bucket credentials
// needs "SignatureDoesNotMatch" or "NoSuchBucket", not "off-site failed".
func (a *Agent) copyOffsite(ctx context.Context, srv driver.Server, cfg backup.Config, b *backup.Backup) map[string]any {
	store, err := offsite.New(srv.Offsite)
	if err != nil {
		a.rememberOffsite(srv.ID, false, err.Error())

		return map[string]any{"ok": false, "error": err.Error()}
	}

	/*
	 * 🚨 A generous timeout, from a context of its own.
	 *
	 * The caller's context is the command's, and a command is expected to
	 * answer within a poll window — but uploading several gigabytes over a
	 * home connection legitimately takes much longer than that. Inheriting the
	 * command's deadline would make off-site copies work in testing with a
	 * small world and silently stop the moment one got real.
	 *
	 * Bounded rather than unbounded so a hung provider cannot pin a goroutine
	 * and a file handle for ever.
	 */
	uploadCtx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 6*time.Hour)
	defer cancel()

	path := filepath.Join(cfg.Dir, b.ID)

	if err := store.Put(uploadCtx, b.ID, path); err != nil {
		a.rememberOffsite(srv.ID, false, err.Error())

		return map[string]any{"ok": false, "error": err.Error()}
	}

	a.rememberOffsite(srv.ID, true, "")

	out := map[string]any{"ok": true}

	if pruned, perr := store.Prune(uploadCtx); perr != nil {
		// Reported alongside a successful upload, because they are separate
		// facts: the copy is safely off-site AND retention is not working.
		out["pruneError"] = perr.Error()
	} else if len(pruned) > 0 {
		out["pruned"] = pruned
	}

	return out
}

// backupConfig builds a backup config from what the OPERATOR configured.
func backupConfig(srv driver.Server) (backup.Config, error) {
	if srv.BackupRoot == "" || len(srv.BackupPaths) == 0 {
		return backup.Config{}, protocol.Errf(protocol.CodeNotSupported,
			"server %q has no backup paths configured on this host", srv.ID)
	}

	dir := srv.BackupDir
	if dir == "" {
		dir = srv.BackupRoot + "/garrison-backups"
	}

	return backup.Config{
		Dir:   dir,
		Root:  srv.BackupRoot,
		Paths: srv.BackupPaths,
		Keep:  srv.BackupKeep,
	}, nil
}

func graceFrom(req protocol.Request, srv driver.Server) (time.Duration, error) {
	grace := srv.Grace()
	if len(req.Params) == 0 {
		return grace, nil
	}
	var p protocol.StopParams
	if err := decode(req.Params, &p); err != nil {
		return 0, err
	}
	if p.GraceSeconds > 0 {
		grace = time.Duration(p.GraceSeconds) * time.Second
	}
	// 🚨 No path to zero. A caller asking for a grace of 0 wants a kill, and
	// a kill mid-save is a corrupt world. If forcing is ever wanted it needs
	// its own verb, with its own confirmation, not a number that looks like
	// a tuning knob.
	if p.GraceSeconds < 0 {
		return 0, protocol.Errf(protocol.CodeBadRequest, "graceSeconds cannot be negative")
	}
	return grace, nil
}

func decode(raw json.RawMessage, into any) error {
	if len(raw) == 0 {
		return nil
	}
	if err := json.Unmarshal(raw, into); err != nil {
		return protocol.Errf(protocol.CodeBadRequest, "bad params: %v", err)
	}
	return nil
}

// Drivers lists the drivers that worked on this host, for --check and for
// agent.info.
func (a *Agent) Drivers() []string { return a.drivers.Names() }

// StatusAll reports every server this agent knows about.
//
// 🚨 Gathered on every poll, not on request. The forum's status page then
// renders from one cached row per server with no round trip to a host that
// might be asleep — which is what lets ten widgets on a page cost one query
// instead of ten requests.
func (a *Agent) StatusAll(ctx context.Context) []protocol.Status {
	out := make([]protocol.Status, 0, len(a.servers))

	for _, s := range a.servers {
		drv, ok := a.drivers[s.Driver]
		if !ok {
			out = append(out, protocol.Status{
				Server: s.ID, Driver: s.Driver, State: protocol.StateUnknown,
				Detail: "driver not available on this host",
			})
			continue
		}

		st, err := drv.Status(ctx, s)
		st.Game = s.Game
		if err != nil {
			st = protocol.Status{Server: s.ID, Driver: s.Driver, Game: s.Game, State: protocol.StateUnknown}
			if pe, isProto := err.(*protocol.Error); isProto {
				st.Detail = pe.Message
			}
		}

		// Stats ride along with status: a separate verb per server per poll
		// would triple the traffic for a number the page always shows anyway.
		if st.State == protocol.StateRunning {
			if stats, serr := drv.Stats(ctx, s); serr == nil {
				st.Stats = &stats
			}
		}

		/*
		 * 🚨 Health is evaluated on EVERY poll, not on request.
		 *
		 * The failure this exists to catch is silent: a server that is up,
		 * saving its world, refreshing its lobby, and unjoinable. Nobody goes
		 * looking for that — they find out when a player complains, which on
		 * the outage that prompted this was twenty hours later. A check that
		 * has to be asked for is a check nobody runs.
		 */
		st.Health = health.Check(ctx, st.State == protocol.StateRunning, s.Health, func(c context.Context, n int) ([]string, error) {
			var lines []string
			err := drv.Tail(c, s, n, false, func(l protocol.Line) {
				lines = append(lines, l.Text)
			})
			return lines, err
		})

		st.Backups = backupsFor(s)
		st.Offsite = a.offsiteStatus(s)

		out = append(out, st)
	}
	return out
}

// MaxBackupsShipped bounds how many archives ride along with a status report.
//
// 🚨 Bounded for the same reason console output is. A server configured to keep
// every backup forever accumulates thousands of files, and an unbounded list
// would grow the poll body without limit until the request times out — taking
// every OTHER server's status down with it, because they share the poll. The
// most recent handful is what an operator restores from; the rest is history
// they would go to the host for anyway.
const MaxBackupsShipped = 25

// backupsFor lists a server's archives, newest first, for the status report.
//
// 🚨 Every failure here is SILENT ON PURPOSE, and this is the one place in the
// agent where that is right. A server with no backup paths configured is the
// normal case, not an error; an unreadable directory is worth knowing about but
// is not worth failing a status report over. If listing backups could fail a
// poll, one misconfigured server would stop the forum hearing about the state
// or health of every other server on the host — trading the feature this
// product exists for against a convenience panel.
func backupsFor(s driver.Server) []protocol.Backup {
	cfg, err := backupConfig(s)
	if err != nil {
		return nil
	}

	list, err := backup.List(cfg)
	if err != nil || len(list) == 0 {
		return nil
	}

	if len(list) > MaxBackupsShipped {
		list = list[:MaxBackupsShipped]
	}

	out := make([]protocol.Backup, 0, len(list))
	for _, b := range list {
		out = append(out, protocol.Backup{ID: b.ID, Size: b.Size, At: b.At, Safety: b.Safety})
	}

	return out
}
