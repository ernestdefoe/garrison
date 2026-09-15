// Package config loads the agent's own configuration.
//
// 🚨 This file is the ONLY place a command line, a container name or a working
// directory can be written. The forum names a server by ID and nothing more,
// so the set of things this agent can run is fixed by an operator with shell
// access to the host and cannot be widened over the wire.
package config

import (
	"encoding/json"
	"fmt"
	"os"
	"strings"

	"github.com/ernestdefoe/garrison/internal/driver"
)

// Config is the agent's config file.
//
// JSON rather than YAML for the spike, purely to keep the dependency list at
// one. Operators will want YAML and phase 1 should give them it — this shape
// is what gets parsed either way.
type Config struct {
	// ForumURL is the endpoint the agent dials OUT to.
	ForumURL string `json:"forumUrl"`
	// Token authenticates this agent. Issued by the forum at pairing.
	Token string `json:"token"`
	// Servers this agent may operate.
	Servers []driver.Server `json:"servers"`
}

// Load reads and validates a config file.
func Load(path string) (*Config, error) {
	b, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	var c Config
	dec := json.NewDecoder(strings.NewReader(string(b)))
	// 🚨 Refuse unknown fields. A typo in a config key is otherwise silently
	// ignored, and the operator spends an evening wondering why stopGrace
	// (which should be stopGraceSeconds) does nothing.
	dec.DisallowUnknownFields()
	if err := dec.Decode(&c); err != nil {
		return nil, fmt.Errorf("%s: %w", path, err)
	}
	if err := c.Validate(); err != nil {
		return nil, fmt.Errorf("%s: %w", path, err)
	}
	return &c, nil
}

// Validate catches what would otherwise be a confusing runtime failure.
func (c *Config) Validate() error {
	seen := map[string]bool{}
	for i, s := range c.Servers {
		switch {
		case s.ID == "":
			return fmt.Errorf("server %d has no id", i)
		case seen[s.ID]:
			return fmt.Errorf("duplicate server id %q", s.ID)
		}
		seen[s.ID] = true

		switch s.Driver {
		case "docker":
			if s.Container == "" {
				return fmt.Errorf("server %q uses the docker driver but names no container", s.ID)
			}
		case "process":
			if s.Command == "" {
				return fmt.Errorf("server %q uses the process driver but has no command", s.ID)
			}
		case "":
			return fmt.Errorf("server %q has no driver", s.ID)
		default:
			// Naming systemd or service here is not a typo — they are real
			// drivers, just not in this phase. Say which, rather than
			// "unknown driver", so the operator knows to wait rather than to
			// go looking for their mistake.
			return fmt.Errorf("server %q wants the %q driver, which this agent does not implement yet", s.ID, s.Driver)
		}
	}
	return nil
}
