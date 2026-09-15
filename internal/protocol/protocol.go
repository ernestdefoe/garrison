// Package protocol defines the wire contract between the forum and an agent.
//
// 🚨 The verb set in this file is the security boundary of the whole product.
//
// The forum is a PHP application, on the public internet, running third-party
// extension code. It is the thing most likely to be compromised. So the agent
// does not accept commands — it accepts VERBS, from the closed set below, and
// anything else is refused before it reaches a driver. A fully compromised
// forum can ask to restart a server it already knows about. It cannot ask for
// a shell, and there is deliberately no verb through which it could.
//
// That is why there is no "exec", no "run", no "script" and no field anywhere
// in Request that is passed to a shell. If a future feature seems to need one,
// it needs a new named verb with its own validation instead.
package protocol

import (
	"encoding/json"
	"fmt"
	"time"
)

// Verb is one operation the agent will perform. The set is closed: see Known.
type Verb string

const (
	// Agent-level.
	VerbPing       Verb = "agent.ping"
	VerbAgentInfo  Verb = "agent.info"
	VerbServerList Verb = "server.list"

	// Lifecycle.
	VerbStatus  Verb = "server.status"
	VerbStart   Verb = "server.start"
	VerbStop    Verb = "server.stop"
	VerbRestart Verb = "server.restart"

	// Telemetry.
	VerbStats Verb = "server.stats"

	// Console.
	VerbConsoleTail Verb = "console.tail"
	VerbConsoleSend Verb = "console.send"
)

// known is the entire set of verbs this agent will ever dispatch.
//
// 🚨 A map literal, not a switch in the dispatcher and not a naming
// convention. It can be enumerated, which means it can be tested and it can be
// shown to an operator; "everything starting with server." cannot be either.
var known = map[Verb]struct{}{
	VerbPing:        {},
	VerbAgentInfo:   {},
	VerbServerList:  {},
	VerbStatus:      {},
	VerbStart:       {},
	VerbStop:        {},
	VerbRestart:     {},
	VerbStats:       {},
	VerbConsoleTail: {},
	VerbConsoleSend: {},
}

// Known reports whether v is a verb this agent implements. Everything else is
// refused with CodeUnknownVerb without a driver ever being consulted.
func Known(v Verb) bool {
	_, ok := known[v]
	return ok
}

// Verbs returns every known verb, for agent.info and for tests that assert the
// set has not grown by accident.
func Verbs() []Verb {
	out := make([]Verb, 0, len(known))
	for v := range known {
		out = append(out, v)
	}
	return out
}

// NeedsServer reports whether a verb operates on a particular server, and so
// requires Request.Server to name one the agent already knows.
func NeedsServer(v Verb) bool {
	switch v {
	case VerbPing, VerbAgentInfo, VerbServerList:
		return false
	default:
		return true
	}
}

// Error codes. Every failure the forum can see is one of these, so the UI can
// branch on them rather than on message text.
const (
	CodeUnknownVerb    = "unknown_verb"
	CodeUnknownServer  = "unknown_server"
	CodeBadRequest     = "bad_request"
	CodeDriverFailed   = "driver_failed"
	CodeNotSupported   = "not_supported"
	CodeAlreadyRunning = "already_running"
	CodeNotRunning     = "not_running"
	CodeTimeout        = "timeout"
	CodeInternal       = "internal"
)

// Error is a machine-readable failure.
type Error struct {
	Code    string `json:"code"`
	Message string `json:"message"`
}

func (e *Error) Error() string { return e.Code + ": " + e.Message }

// Errf builds an Error with a formatted message.
func Errf(code, format string, args ...any) *Error {
	return &Error{Code: code, Message: fmt.Sprintf(format, args...)}
}

// Request is one instruction from the forum.
//
// 🚨 Note what is absent: no command, no path, no image, no shell. Params is
// per-verb and every verb validates its own, so adding a field cannot
// accidentally widen what the agent will do.
type Request struct {
	ID     string          `json:"id"`
	Verb   Verb            `json:"verb"`
	Server string          `json:"server,omitempty"`
	Params json.RawMessage `json:"params,omitempty"`
}

// Response answers exactly one Request, by ID.
type Response struct {
	ID    string          `json:"id"`
	OK    bool            `json:"ok"`
	Error *Error          `json:"error,omitempty"`
	Data  json.RawMessage `json:"data,omitempty"`
}

// Event is unsolicited: console lines and stats samples that belong to a
// long-running request (Stream carries that request's ID).
type Event struct {
	Stream string          `json:"stream"`
	Kind   string          `json:"kind"`
	At     time.Time       `json:"at"`
	Data   json.RawMessage `json:"data"`
}

// Frame is what actually crosses the wire, in either direction. Exactly one
// field is set.
type Frame struct {
	Request  *Request  `json:"req,omitempty"`
	Response *Response `json:"res,omitempty"`
	Event    *Event    `json:"evt,omitempty"`
}

// ---- verb payloads -------------------------------------------------------

// StopParams is the payload of server.stop and server.restart.
type StopParams struct {
	// GraceSeconds overrides the server's configured grace period. Zero means
	// use the configured one; it never means "kill immediately".
	GraceSeconds int `json:"graceSeconds,omitempty"`
}

// TailParams is the payload of console.tail.
type TailParams struct {
	// History is how many lines of scrollback to send before following.
	History int `json:"history,omitempty"`
	// Follow keeps the stream open and sends new lines as they arrive.
	Follow bool `json:"follow,omitempty"`
}

// SendParams is the payload of console.send.
type SendParams struct {
	Line string `json:"line"`
}

// ---- results -------------------------------------------------------------

// State is the lifecycle state of a server, normalised across drivers.
//
// 🚨 Normalised deliberately. Docker says "exited", systemd says "inactive",
// a bare process says nothing at all. If those differences reach the forum,
// every consumer has to know about every driver.
type State string

const (
	StateRunning  State = "running"
	StateStopped  State = "stopped"
	StateStarting State = "starting"
	StateStopping State = "stopping"
	StateCrashed  State = "crashed"
	StateUnknown  State = "unknown"
)

// Status is what server.status returns.
type Status struct {
	Server  string    `json:"server"`
	Driver  string    `json:"driver"`
	State   State     `json:"state"`
	PID     int       `json:"pid,omitempty"`
	Since   time.Time `json:"since,omitempty"`
	Detail  string    `json:"detail,omitempty"`
	Healthy *bool     `json:"healthy,omitempty"`
}

// Stats is one resource sample.
//
// 🚨 The same struct whatever the driver. A container reads it from the
// Docker API, a bare process from cgroup v2 or by walking /proc — see
// internal/driver. Nothing above the driver interface may care which.
type Stats struct {
	Server      string    `json:"server"`
	At          time.Time `json:"at"`
	CPUPercent  float64   `json:"cpuPercent"`
	MemoryBytes uint64    `json:"memoryBytes"`
	MemoryLimit uint64    `json:"memoryLimit,omitempty"`
	Processes   int       `json:"processes,omitempty"`
	Source      string    `json:"source"` // "docker" | "cgroup2" | "proc" | "ps"
}

// Line is one line of console output.
type Line struct {
	Server string    `json:"server"`
	At     time.Time `json:"at"`
	Text   string    `json:"text"`
	Stderr bool      `json:"stderr,omitempty"`
}

// AgentInfo is what agent.info returns: enough for the forum to decide what to
// offer without guessing.
type AgentInfo struct {
	Version string   `json:"version"`
	OS      string   `json:"os"`
	Arch    string   `json:"arch"`
	Drivers []string `json:"drivers"`
	Verbs   []string `json:"verbs"`
	Servers int      `json:"servers"`
}
