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
    internal/settings    declared config files — read and rewrite in place
    internal/players     who is in the game, from its log — and in-game proof of identity
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

### Editable settings

🚨 **There is no file manager, and that absence is the feature.** The operator
declares which files may be read and changed; the forum names one by its `id`
and a path never crosses the wire. `keys`, when given, narrows it further — an
operator can let a moderator change the message of the day without that
moderator being three lines away from the RCON password in the same file.

```json
"config": [
  {
    "id": "props",
    "label": "server.properties",
    "path": "/srv/minecraft/server.properties",
    "format": "properties",
    "keys": ["motd", "max-players", "view-distance"]
  },
  {
    "id": "startup",
    "label": "Startup arguments",
    "path": "/srv/minecraft/start.env",
    "format": "properties",
    "readOnly": true
  }
]
```

Formats are `properties` (`key=value`, `#` comments — Minecraft and most Java
servers) and `ini` (the same with `[sections]`). Keys not in `keys` are still
shown, greyed: a setting somebody cannot find is one they go and edit by hand.
Comments in the file become the help text under each field, because the game
already wrote down what its settings do.

Editing rewrites one line in place. Comments, blank lines, ordering and
indentation all survive, and the write goes through a temporary file and a
rename so an interruption cannot leave a half-written config that resets the
server to defaults on next start.

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

## Running the agent under systemd

🚨 **`KillMode=process`, or restarting the agent kills every game on the host.**

systemd's default is `control-group`: stopping a unit kills everything in its
cgroup, and the game servers the agent started are in it. The agent deliberately
detaches from its children rather than signalling them — stopping the agent must
never stop the games, or nobody would let it auto-update — and systemd's default
defeats that from the outside. Found on the dev host, where every restart of the
agent silently took the game down with it.

```ini
[Unit]
Description=Garrison agent
After=network-online.target

[Service]
ExecStart=/usr/local/bin/garrison-agent --config /etc/garrison/agent.json
Restart=always
RestartSec=5

# 🚨 Not the default. See above: without this, `systemctl restart garrison-agent`
# stops every game server on this machine.
KillMode=process

[Install]
WantedBy=multi-user.target
```

## Linking forum accounts to players

A player proves who they are **inside the game**, not on the forum. A form that
asks for an in-game name and believes the answer lets anybody claim the
community's best-known player and inherit their playtime and rank.

```json
"players": {
  "preset": "minecraft",
  "verifyMessage": "Garrison code: {code}"
}
```

Garrison whispers a six-character code to that player using the preset's `say`
template; they read it in the game and type it back on the forum. Presets that
have no whisper command cannot verify, and the forum does not offer the flow
there rather than showing a button that always fails.

🚨 **The forum never composes the console line.** It sends a name and a code; the
agent renders the operator's template and refuses any player it cannot currently
see in the game. That matters because verification is something ordinary members
do — and a game console is where `ban`, `op` and `give` live. A player called
`alice /op mallory` would otherwise turn one command into two.

## Notes for whoever picks this up

- The agent **dials out**. The forum never connects to the game host, so a
  host needs no inbound port, no port forward and no static IP. That is what
  lets somebody run the Minecraft server on the box under their desk.
- Stopping the agent must never stop the games. `garrison-agent` detaches from
  its children rather than signalling them, or nobody would let it auto-update.
- The first `server.stats` for any server reports `cpuPercent: 0` and this is
  correct: CPU is a rate, and one reading of a counter is not one. The forum
  should sample twice before drawing anything.
