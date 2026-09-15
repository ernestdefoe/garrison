// Command garrison-agent runs game servers on one host and answers the forum.
//
// It dials OUT to the forum and holds that connection open, so this host needs
// no inbound port, no port forward and no static IP.
package main

import (
	"context"
	"flag"
	"fmt"
	"log/slog"
	"os"
	"os/signal"
	"strings"
	"syscall"

	"github.com/ernestdefoe/garrison/internal/agent"
	"github.com/ernestdefoe/garrison/internal/config"
	"github.com/ernestdefoe/garrison/internal/driver"
)

func main() {
	var (
		cfgPath = flag.String("config", "/etc/garrison/agent.json", "path to the agent config file")
		verbose = flag.Bool("v", false, "debug logging")
		check   = flag.Bool("check", false, "validate the config and report what this host can do, then exit")
	)
	flag.Parse()

	level := slog.LevelInfo
	if *verbose {
		level = slog.LevelDebug
	}
	log := slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: level}))

	cfg, err := config.Load(*cfgPath)
	if err != nil {
		log.Error("config", "err", err)
		os.Exit(2)
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	processDriver := driver.NewProcess()
	candidates := driver.Set{
		"docker":  driver.NewDocker(),
		"process": processDriver,
	}

	ag, unavailable := agent.New(ctx, cfg.Servers, candidates)
	for _, u := range unavailable {
		// Not an error. A host with no Docker is a host Garrison supports; it
		// simply cannot run the servers configured for that driver, and the
		// operator should hear it once at startup rather than at the first
		// click.
		log.Info("driver unavailable on this host", "driver", u)
	}

	if *check {
		fmt.Printf("config OK: %d server(s)\n", len(cfg.Servers))
		fmt.Printf("drivers available: %v\n", ag.Drivers())
		for _, s := range cfg.Servers {
			fmt.Printf("  %-16s driver=%-8s grace=%s\n", s.ID, s.Driver, s.Grace())
		}
		return
	}

	// 🚨 Stopping the agent must not stop the games. Detaching rather than
	// signalling is what makes an agent upgrade a non-event instead of an
	// outage — and an agent that takes the servers down when it restarts is
	// an agent nobody will let auto-update.
	defer processDriver.Shutdown()

	// The scheme picks the transport. Polling is the default because it needs
	// nothing on the forum host but Flarum itself; websocket needs a gateway
	// daemon there and exists as an upgrade, not a requirement.
	var run func(context.Context) error

	switch {
	case strings.HasPrefix(cfg.ForumURL, "ws://"), strings.HasPrefix(cfg.ForumURL, "wss://"):
		run = agent.NewLink(cfg.ForumURL, cfg.Token, ag, log).Run
		log.Info("transport", "kind", "websocket")
	default:
		run = agent.NewHTTPLink(cfg.ForumURL, cfg.Token, ag, log).Run
		log.Info("transport", "kind", "poll")
	}

	log.Info("starting", "version", agent.Version, "forum", cfg.ForumURL, "servers", len(cfg.Servers))

	if err := run(ctx); err != nil && ctx.Err() == nil {
		log.Error("link", "err", err)
		os.Exit(1)
	}
	log.Info("stopped")
}
