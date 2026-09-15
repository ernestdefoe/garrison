// Package driver is the supervisor abstraction.
//
// 🚨 This interface is the load-bearing decision of the agent. Docker is one
// driver of four — docker, systemd, process and (later) service — and NOTHING
// above this package may name Docker, or Docker's shape leaks into the API and
// the bare-metal path is permanently second-class.
//
// Most game servers in the world are not containerised. They are a folder from
// SteamCMD with a start script, under systemd or screen or nothing at all, and
// the people most likely to buy a forum extension to manage a server are
// exactly the people running one that way.
package driver

import (
	"context"
	"time"

	"github.com/ernestdefoe/garrison/internal/protocol"
)

// Server is one managed game server, as configured on the agent.
//
// 🚨 The agent's config is the ONLY place a command line can be written. The
// forum names a server by ID and nothing more — see protocol.Request — so a
// compromised forum can operate the servers an operator already defined and
// cannot define a new one that runs something else.
type Server struct {
	ID     string `json:"id"`
	Name   string `json:"name"`
	Driver string `json:"driver"` // docker | systemd | process

	// docker
	Container string `json:"container,omitempty"`

	// systemd
	Unit string `json:"unit,omitempty"`

	// process
	Dir     string            `json:"dir,omitempty"`
	Command string            `json:"command,omitempty"`
	Args    []string          `json:"args,omitempty"`
	Env     map[string]string `json:"env,omitempty"`

	// StopGraceSeconds is how long to wait after asking politely before
	// forcing. Zero means DefaultStopGrace, never "kill immediately".
	//
	// 🚨 A container gets a grace period from `docker stop` for free. A bare
	// process gets nothing: without this the first restart during an autosave
	// truncates the world file.
	StopGraceSeconds int `json:"stopGraceSeconds,omitempty"`

	// StopCommand is a console line that asks the game to shut down cleanly
	// (Minecraft's "stop", Factorio's "/quit"). Sent before any signal.
	StopCommand string `json:"stopCommand,omitempty"`
}

// DefaultStopGrace is used when a server does not set one. Valheim's own save
// on shutdown can take a few seconds on a large world; 30s is comfortable
// without making a restart feel broken.
const DefaultStopGrace = 30 * time.Second

// Grace returns the effective stop grace period.
func (s Server) Grace() time.Duration {
	if s.StopGraceSeconds > 0 {
		return time.Duration(s.StopGraceSeconds) * time.Second
	}
	return DefaultStopGrace
}

// Driver supervises servers of one kind.
//
// Every method takes the whole Server rather than a handle, so a driver can
// read whichever of its own fields it needs without the caller knowing which
// those are.
type Driver interface {
	// Name is the driver's key in config: "docker", "systemd", "process".
	Name() string

	// Available reports whether this driver can work on this host at all —
	// the docker CLI is present, systemd is PID 1, and so on. Checked once at
	// startup so the agent can tell the forum what it can actually do rather
	// than failing at the first verb.
	Available(ctx context.Context) error

	Status(ctx context.Context, s Server) (protocol.Status, error)
	Start(ctx context.Context, s Server) error

	// Stop must be graceful: ask, wait up to grace, only then force.
	Stop(ctx context.Context, s Server, grace time.Duration) error

	Stats(ctx context.Context, s Server) (protocol.Stats, error)

	// Tail delivers console output to sink. With follow, it runs until ctx is
	// cancelled. history is how many past lines to deliver first.
	Tail(ctx context.Context, s Server, history int, follow bool, sink func(protocol.Line)) error

	// Send writes one line to the server's console.
	Send(ctx context.Context, s Server, line string) error
}

// Set is the drivers available on this host, keyed by name.
type Set map[string]Driver

// Names returns the available driver names, for agent.info.
func (set Set) Names() []string {
	out := make([]string, 0, len(set))
	for n := range set {
		out = append(out, n)
	}
	return out
}
