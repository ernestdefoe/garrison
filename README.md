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
    internal/backup      archives: create, list, restore, prune, and safety copies
    internal/offsite     S3-compatible copies, signed by hand to keep deps at one
    internal/health      readiness probes — "running" and "joinable" are not the same

## Try it

    go build ./...
    go test ./...

    # cross-compile for a Linux game host
    GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build -o garrison-agent ./cmd/garrison-agent

    # on the game host
    garrison-agent --config /etc/garrison/agent.json --check

`--check` validates the config and reports what the host can actually do,
rather than letting the first click be where you find out Docker is missing.

## Configuring a server on the host

Everything that names a **path, a file or a credential** is configured here, on
the game host, and never in the forum. That is the security boundary, not a
convenience: the forum is a PHP application on the public internet running
third-party extension code, and it is the part of this system most likely to be
compromised. It can ask for a backup of a server it already knows about. It
cannot say what gets archived, where it is written, or where a copy is sent.

```json
{
  "id": "valheim",
  "name": "Shattered Pact",
  "driver": "docker",
  "container": "valheim",
  "game": "valheim",
  "stopGraceSeconds": 120,

  "backupRoot": "/srv/valheim",
  "backupPaths": ["worlds", "server.cfg"],
  "backupDir": "/srv/valheim/garrison-backups",
  "backupKeep": 14,

  "offsite": {
    "endpoint": "https://s3.us-west-002.backblazeb2.com",
    "region": "us-west-002",
    "bucket": "shattered-pact-backups",
    "prefix": "valheim",
    "accessKey": "…",
    "secretKey": "…",
    "pathStyle": true,
    "keep": 30
  }
}
```

### Off-site copies

Any S3-compatible provider: AWS S3, Backblaze B2, Cloudflare R2, Wasabi,
MinIO. A copy is made after each successful backup, and retention runs against
the bucket separately from the local one — `keep` off-site is usually larger
than `backupKeep`, because the whole point of the remote copy is that it
outlives the host.

- **`endpoint` must be `https://`.** Uploads are signed with
  `UNSIGNED-PAYLOAD`, which avoids reading a multi-gigabyte archive twice; that
  trade is only safe under TLS, so a plain `http://` endpoint is refused with an
  error saying why rather than silently accepted.
- **`pathStyle` is what most non-AWS providers need.** AWS serves a bucket as
  `<bucket>.s3.amazonaws.com`; MinIO and, depending on setup, B2 and R2 serve it
  as `<endpoint>/<bucket>`. Getting it wrong produces a DNS failure or a 404,
  which reads as a wrong endpoint and sends you looking in the wrong place.
- **`region`** is required. Providers that do not use regions accept `auto` or
  `us-east-1`.
- Archives over 64 MiB are uploaded in parts, because S3 caps a single PUT at
  5 GiB — without that, off-site backups work for a year and then stop the day
  the world gets big.

A failed upload never fails the backup. A local archive that exists is worth
more than a copy that did not arrive: the common disasters are all recovered
from the local one. The forum's panel says whether copies are landing and shows
the provider's own error when they are not.

## What is deliberately not here

- **No credentials in the forum.** Off-site keys live in this file. Putting
  them in the admin panel would mean storing them in the most attackable part
  of the system and then sending them over the wire to get here.
- **No systemd or Windows driver.** The interface has room for both; adding
  them before the interface was proved would have been guessing.
- **No `exec` verb, and there will not be one.** A fully compromised forum can
  restart a server it already knows about. It cannot ask for a shell, because
  there is no verb through which it could.

## Notes for whoever picks this up

- The agent **dials out**. The forum never connects to the game host, so a
  host needs no inbound port, no port forward and no static IP. That is what
  lets somebody run the Minecraft server on the box under their desk.
- Stopping the agent must never stop the games. `garrison-agent` detaches from
  its children rather than signalling them, or nobody would let it auto-update.
- The first `server.stats` for any server reports `cpuPercent: 0` and this is
  correct: CPU is a rate, and one reading of a counter is not one. The forum
  should sample twice before drawing anything.
