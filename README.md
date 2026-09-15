# Garrison

Run your game servers from the forum the players already live in.

**Status: phase 0, the spike.** Not a product yet. There is no Flarum extension
in this repo — phase 0 exists to answer one question before any PHP is written.

Scope doc: <https://claude.ai/artifact/6hgfYHAbeRuaiMSgJ9Tgig>

## The question this phase answers

> Does a single verb set really cover both Docker and bare metal?

Proving it with one driver would have proved nothing, so the spike ships two
that share no code and agree on nothing except the interface. If the
abstraction were wrong, it would be wrong here, cheaply, rather than after the
forum half was built on top of it.

**Answer: yes.** Run on 2026-09-15 against a real host — a live Valheim
container and a bare process in a folder, same verbs, same agent, same output
shapes:

| verb | `docker` driver (live Valheim) | `process` driver (no Docker) |
|---|---|---|
| `server.status` | `running`, pid 3836544 | `running`, pid 3921899 |
| `server.stats` | 13.26% CPU, 1.47 GB, 86 procs, `source: docker` | 6.1 MB, 5 procs, **`source: cgroup2`** |
| `console.tail` | container logs, timestamps parsed off | captured stdout/stderr |
| `console.send` | *(not exercised — live server)* | line reached stdin, game echoed it |
| `server.stop` | *(not exercised — live server)* | trap ran: "Saving world… World saved." |

`source: cgroup2` is the one worth looking at twice. There is no `docker stats`
for a bare process, and that field is the agent saying which strategy it used
to get an honest number — cgroup v2 where the process has its own slice, a walk
of `/proc` over the whole descendant tree where it does not.

## The security boundary, proved against a running agent

    garrison> raw shell.exec valheim
    REFUSED [unknown_verb] no such verb "shell.exec"
    garrison> raw server.exec valheim
    REFUSED [unknown_verb] no such verb "server.exec"
    garrison> raw agent.update
    REFUSED [unknown_verb] no such verb "agent.update"
    garrison> status nonexistent
    REFUSED [unknown_server] no server "nonexistent" on this agent

The forum is a PHP application on the public internet running third-party
extension code. It is the thing most likely to be compromised. So the agent
accepts **verbs from a closed set**, never commands, and the only place a
command line can be written is the agent's own config file on the game host.
A fully compromised forum can operate the servers an operator already defined.
It cannot define one that runs something else, and there is deliberately no
verb through which it could.

Both boundary tests are proved by reintroducing the bug they exist to catch,
not merely by passing:

- delete the closed-set check → `TestUnknownVerbsAreRefused` fails on all
  thirteen attack verbs;
- delete `Setpgid` → `TestSignalReachesForkedGrandchildren` fails with a live
  grandchild, which in the real world is a forked JVM still holding the game's
  UDP port so the next start fails with "address already in use".

## Layout

    cmd/garrison-agent   the agent: one static binary, dials OUT to the forum
    cmd/garrison-hub     stands in for Flarum so the loop can be driven now
    internal/protocol    the closed verb set and the wire types
    internal/driver      the supervisor abstraction + docker and process drivers
    internal/supervise   process supervision: groups, scrollback, the stop ladder
    internal/config      the agent's config — the only place a command is written

## Try it

    go build ./...
    go test ./...

    # cross-compile for a Linux game host
    GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build -o garrison-agent ./cmd/garrison-agent

    # on the game host
    garrison-agent --config /etc/garrison/agent.json --check

`--check` validates the config and reports what the host can actually do,
rather than letting the first click be where you find out Docker is missing.

## What is deliberately not here

- **No Flarum extension.** Phase 1.
- **No systemd or Windows driver.** The interface has room for both; adding
  them before the interface was proved would have been guessing.
- **No auth beyond a bearer token.** Pairing, rotation and revocation are
  phase 1. The token in the spike config is `spike`.
- **No TLS.** The agent speaks `ws://` to localhost in the spike. Production
  is `wss://` to the forum, which already terminates TLS.

## Notes for whoever picks this up

- The agent **dials out**. The forum never connects to the game host, so a
  host needs no inbound port, no port forward and no static IP. That is what
  lets somebody run the Minecraft server on the box under their desk.
- Stopping the agent must never stop the games. `garrison-agent` detaches from
  its children rather than signalling them, or nobody would let it auto-update.
- The first `server.stats` for any server reports `cpuPercent: 0` and this is
  correct: CPU is a rate, and one reading of a counter is not one. The forum
  should sample twice before drawing anything.
