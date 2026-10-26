# 0095: WSL 2 parity and the measured owner (release 2.1.1)
- Status: accepted
- Date: 2026-10-26
- Amends: ADR 0051 (WSL 2 tested by hand per release), ADR 0070 (owner by measurement, socket group read in a
  container, the WSL browser opener and the SSH rule, script refresh, new host facts), ADR 0078 (new reason codes, the
  doctor status `unknown`, `fieldset repair-ownership`)
- Plan: `docs/plans/2026-10-26-001-feat-wsl-parity-and-query-tuning-plan.md` (Phase 1, units U1–U8, U14, U15;
  KTD1–KTD16); requirements: `docs/brainstorms/2026-10-26-wsl-parity-and-query-tuning-requirements.md`
- Decision by the agent (no human available, AGENTS.md); the choices and the open Windows facts are listed for review in
  QUESTIONS 81.

## Context
The 2.1.0 one-liner failed in WSL 2 with Docker Desktop: the shim saw `uname` linux plus "Docker Desktop", picked the
runtime `desktop` and expected the instance files to be owned by UID 0 inside containers. Under WSL 2 the distro's
own file system passes user IDs through, so containers saw the user's files as UID 1000 and `doctor` stopped with
"The instance directory belongs to UID 1000, not the instance owner (UID 0 inside containers)". Its hint (chmod, chown
to your user) could not help: the files already belonged to the user. ADR 0051 promised WSL documentation that never
shipped. Only `install.sh` wrote the instance's `fieldset` script, so a shim fix never reached an upgraded instance.

Facts checked while building 2.1.1:
- Docker Desktop for macOS (VirtioFS) reports a bind-mounted host file as owned by whoever reads it: container root
  sees 0, a container running as UID 4242 sees 4242, UID 33 (the image's `www-data`) sees 33, and a file container root
  writes appears on the host as the host user (checked 2026-10-26 on Docker Desktop 29.2.1 for macOS with `alpine:3`).
  So the owner can only be read as container root; as the image's default user it reads 33.
- WSL 2 passes user IDs through: the user's 2.1.0 doctor saw the distro files as UID 1000 inside containers.
- Docker Desktop turns its WSL integration off by default for new distros (4.75 release notes, research report of
  2026-10-26), and wslu (`wslview`) left Ubuntu 26.04 (Launchpad).

## Decision
**WSL detection and refusals (U2, KTD11).** WSL 2 is a kernel release containing "microsoft" plus an interop marker
(`/proc/sys/fs/binfmt_misc/WSLInterop` or `WSLInterop-late`) or `WSL_DISTRO_NAME`; WSL 1 is the capital-M kernel without
"WSL2". A "microsoft" kernel without either marker (some cloud kernels) is not WSL. The shim and install.sh share the rule
(`fieldset_wsl_detect`, `fieldset_install_wsl`) and pass `FIELDSET_WSL` (`2` or `0`) and, on WSL 2,
`FIELDSET_WSL_BACKEND` (`desktop` when `docker info`'s OperatingSystem starts with "Docker Desktop", else `engine`).
- WSL 1 stops with exit 3 `wsl1_unsupported` and the command `wsl --set-version <distro> 2`, before any download.
- An instance directory on a Windows drive stops with exit 2 `path_on_windows_drive` and names `~/fieldset`. The check is
  separate from the reserved-path lists: it resolves the physical path of the existing part (`cd -P`, so a link to
  `/mnt/d` counts) and reads `/proc/mounts`: drvfs, 9p with drvfs options, or any 9p under WSL 2. The reserved lists and
  `LaunchGuard` stay unchanged, so backups and file arguments on `/mnt/c` stay allowed.
- On WSL 2 a missing `docker` (or Docker Desktop's "WSL integration" answer) is exit 10 `docker_missing` naming the
  integration setting for the distro and Docker Engine as the alternative; a dead socket is exit 10
  `docker_unavailable` with "Start Docker Desktop on Windows".

**Owner measured in the read direction (U3, KTD2).** Before the ops container, the shim runs a measuring container:
`docker run --rm --network none --label app.fieldset.role=owner --user 0:0 --entrypoint stat` of the verified app image,
with the instance directory and the socket mounted read-only, reading `%u %g %a` of the nonce file the host user just
wrote and of `/var/run/docker.sock`. Nothing is written, so there is no cleanup and no host-side `stat` (macOS has BSD
`stat`). The owner decides the ops user:

| Owner container root reads | Meaning | Ops container user |
|---|---|---|
| 0 | container root is the host user (Docker Desktop for macOS, rootless) | `0:0` |
| the host UID | user IDs pass through (rootful Linux, WSL 2 with Docker Desktop or Engine) | host UID:GID plus the socket's group |
| anything else | a mapping fieldset does not support (userns-remap) | none: exit 3 `owner_mapping_unknown` naming both UIDs |

**Precedence (KTD3).** The runtime (`rootful`, `rootless`, `desktop`) is still detected per run by product name and
picks the socket path. The owner comes from:
1. a fresh measurement for `install`, `doctor` and `repair-ownership`, and for any command whose state has no measured
   owner or one measured under another runtime (`FIELDSET_OWNER_SOURCE=measured`);
2. otherwise the saved owner (`saved`);
3. when the measurement cannot run (`rules`): pass-through on WSL 2 unless rootless (with a warning naming the failure),
   the host UID on rootful, 0 otherwise.

**Socket group read where it is used (KTD4).** The measuring container reads the socket's group and mode as containers
see them, which can differ from the distro's (under Docker Desktop's WSL integration still to be confirmed by U1).
Pass-through ops containers on WSL 2 get that group with `--group-add` (`FIELDSET_SOCKET_GROUP`); native Linux keeps the
host socket's group (`stat -L -c %g`). A socket whose mode grants neither its group nor others write access stops with
exit 3 `socket_no_group_access`. The Docker socket is a root-equivalent trust boundary: running the ops container as the
host UID adds no isolation and none is claimed, exactly as for rootful Linux since ADR 0070.

**State schema 1.1 (KTD5).** `owner` gains `runtime` (the runtime the owner was measured under), `socket_group` (only
when the ops container needs it) and `measured_at` (UTC). `container_uid` keeps its meaning. A minor bump under
`SchemaVersion`: 2.1.0 reads the file (same major), ignores the new keys and keeps the newer minor when it writes.
`StateValidator` checks their grammar, and the shim reads them with the same awk approach as the image reference.

**First use records the owner (U4, KTD6).** The 2.1.0 upgrade path runs old code, so nothing measures during it. After
a state-changing command or `doctor` under 2.1.1 (`FieldsetCommand::recordsOwner`), `FirstUseRecording` saves this
run's measurement, only when its owner equals the saved `container_uid`, only when state.json lacks it or holds another
runtime or socket group, and under the instance lock taken without waiting. Read-only commands never write state.
`container_uid` changes only through `fieldset repair-ownership`.

**`fieldset repair-ownership` (U4, KTD12).** For a measured owner that differs from the saved one (rootful became
rootless, the directory moved to WSL 2 or another host). It asks first (exit 6 without `--yes`), then under the lock: a
root container of the instance's image runs `find <dir> -xdev ! -type l ( -type d -o -links 1 ) ! -uid <new> -exec
chown -h <new>:<gid>`, so it stays on one file system, never follows or changes a symlink, skips files with a second hard
link and never changes a mode; the env files must still be 0600 (else exit 4 `secrets_exposed`); the stack files are
rendered and the backup service is recreated for the new owner; state.json saves the owner last. Docker volumes are not
touched. Saved and measured owner equal: nothing to do (`changed: false`). Exit 3 `owner_not_measured` when the shim
did not measure in this run; exit 4 `owner_repair_failed` (retryable) when the chown container fails. The leftover
directory of a failed 2.1.0 install (marker, no state) is given back the same way by the shim before `install` runs
(R24).

**Doctor (U4, ADR 0078 amended below).** The `permissions` check compares the saved owner with this run's measurement:
`details` carry `owner_uid`, `saved_owner_uid`, `measured_owner_uid`, `owner_source` and `owner_mismatch`. A mismatch
fails with both UIDs and names `fieldset repair-ownership`, never a chown. A measurement that could not run is the new
status `unknown` with the warning code `owner_not_measured`: never a mismatch, and it counts like `warn` (exit 0, listed
under `warnings`).

**Script refresh (U15, KTD7).** `distribution/shim.sh` declares `fieldset_shim_version=1` and passes it as
`FIELDSET_LAUNCHER_VERSION`; launchers before 2.1.1 pass none. After a command the shim started, `ScriptRefresh`
replaces `<dir>/fieldset` with the image's `distribution/shim.sh` when the launcher's version is lower: under the
instance lock taken without waiting, only while the script on disk is still older and a regular file, as a temporary file
with mode 0700 and the old owner that is synced and renamed over the old one, with one progress line ("the next command
uses it"). A failure keeps the old script and warns; the command's result never changes. install.sh (which writes the
script), `uninstall` and commands not started by the shim change nothing, and neither does a launcher with the same or a
newer version (after a rollback). Every release that changes the shim raises the version: `LauncherVersionTest` pins the
SHA-256 of `distribution/shim.sh` per version, and RELEASING's pre-tag list says so.

**Interop scope and the Windows browser (U5, U6, KTD8).** The ops container cannot reach Windows, so the shim asks.
`powershell.exe` is used only while interop's binfmt entry is enabled, found on PATH or at
`/mnt/c/Windows/System32/WindowsPowerShell/v1.0/powershell.exe` (PATH may lack the Windows directories). One call per
command, bounded to 60 s where `timeout(1)` exists: full facts for `install`, `doctor`, `uninstall` and `config set
phone-https|lan-address`; only the LAN addresses for other commands while phone HTTPS uses the local CA; none otherwise.
Its output loses control characters and a byte order mark, and only `key=value` lines (at most 400) pass as
`FIELDSET_WINDOWS_FACTS`; `WindowsFactsParser` validates every fact against a strict type, and anything missing or
malformed becomes unknown (R30). The Windows LAN addresses replace the distro's own (`FIELDSET_WSL_ADDRESSES` keeps
those, the portproxy target); without interop no address is guessed from WSL's interface, and `lan-address` still wins.
- Browser: on WSL 2 with interop the shim runs `powershell.exe -NoProfile -NonInteractive -Command "Start-Process
  '<url>'"`, also when WSLg sets `DISPLAY`. The URL grammar admits no quote of any kind, so the URL cannot end the
  single-quoted string; the exit code says nothing, so the link also goes to the terminal (without one it stays in the
  0600 hand-over file, as before). WSL without
  interop opens nothing (no WSLg fallback). No `wslview`.
- SSH rule on every platform: no opener runs while `SSH_CONNECTION` or `SSH_TTY` is set (before 2.1.1 only macOS checked
  `SSH_CONNECTION`), since the browser would open on the far side.

**Validated Windows commands (U14, KTD9).** The doctor prints PowerShell and netsh lines built only from validated
values (IPv4 by pattern, ports as integers) and fixed rule names with the instance's short ID:
`fieldset-<first 8 hex of the UUID>-https` (host firewall: inbound TCP 80 and 443, Private profile, `-RemoteAddress
LocalSubnet`) and `…-https-wsl` (Hyper-V firewall, WSL's VM creator ID). No free-form Windows string reaches a line; a
value that does not validate throws. fieldset never runs them.

**Hint precedence (U14, KTD10).** The `windows_network` check (WSL 2 with the local CA on, else skipped) fails first on
another program listening on 80 or 443 where phones connect (IIS and HTTP.sys, PID 4; Docker Desktop's forwarding, WSL's
relay and a serving portproxy do not count), then warns `lan_address_changed` when the certificate's address is none of
Windows' LAN addresses (on WSL this check makes that comparison instead of `lan_address`), then prints the first matching
row under "Run in PowerShell as administrator:" (`details.row`, `details.commands`):

| Row | Setup | Result |
|---|---|---|
| `public_profile` | the network of the address is Public | fail: switch it to Private |
| `desktop_local_only` | Docker Desktop binds published ports to loopback only | fail: change its port binding setting |
| `facts_unknown` | a fact the setup needs is unknown (interop off, a section failed) | `unknown` (`windows_facts_unknown`): manual steps, `lan-address` |
| `host_rule` | Docker Desktop, no inbound rule for 80 and 443 on Private | fail: the host firewall rule |
| `portproxy` | Engine with NAT, portproxy missing or stale, or no host rule | fail: portproxy delete/add lines and the host rule |
| `hyperv_rule` | Engine with mirrored networking, no Hyper-V rule while that firewall filters | fail: the Hyper-V rule |

Install's preflight reports a blocking row as a warning, so the instance installs and its result carries the commands.
Uninstall on WSL 2 with the local CA prints the matching removal lines (`data.windows_cleanup`, warning `windows_rules`).

**Lifecycle (U7).** The `wsl_lifecycle` check (WSL 2 only) warns on Docker Engine when `.wslconfig`'s
`instanceIdleTimeout` lets the distro stop without a terminal or systemd is not PID 1 (`FIELDSET_WSL_SYSTEMD`), with the
exact `.wslconfig` and `/etc/wsl.conf` lines; `unknown` (`wsl_lifecycle_unknown`) when it cannot read them. On Docker
Desktop it passes as information: Desktop starts after the Windows sign-in, and the effect of an idle distro is unknown
until the U1 evidence.

**Advertised and bind address (U6, KTD13).** The advertised address (certificate SAN, phone URL and QR code, doctor,
`lan-address`) is the Windows LAN address. The edge binds it on Docker Desktop and on Engine with mirrored networking.
On Engine with NAT (or an unknown networking mode) the Windows address does not exist in the distro, so the edge binds
every address of the distro (the portproxy's target), recorded in state as the setting `lan_bind: all` where the address
is chosen (install, `config set phone-https`, `config set lan-address`), so later renders need no Windows facts. If U1
shows that Docker Desktop cannot bind a specific Windows address, the fallback is to bind all interfaces, with the
printed firewall rule limiting access to the local subnet.

**Fact-finding as release evidence (U1, KTD1).** `scripts/wsl/facts.sh` is a read-only POSIX script the user runs in the
WSL distro before the first rc check (shellchecked in CI). Its output goes to
`docs/agent/milestones/evidence/R2.1.1/wsl-facts-desktop.txt` and `wsl-facts-engine.txt`. The defaults above do not wait
for it; RELEASING's WSL checklist runs it first, and each unverified fact is confirmed or refuted before the final.

## Consequences
- Every command on macOS and Linux now runs as the saved or measured owner; a wrong read would change the ops user, so
  the precedence, the first-use recording and the journeys (BootstrapJourneyTest derives its expected owner from the
  same measurement) guard it. Instances from 2.1.0 keep their owner; only the measurement is recorded (R4).
- A from-backup install and a restore follow the measured owner of the target host, so backups move between macOS,
  Linux and WSL (R29).
- Agents get new reason codes and two doctor check keys (agent guide); the doctor status `unknown` is additive.
- Windows behaviour is tested by hand per release candidate (RELEASING, "WSL checklist"); CI has no WSL run.
- Open until the user's U1 runs and recorded in QUESTIONS 81: whether Docker Desktop binds a specific Windows address,
  the in-container socket group under Desktop's integration, the effect of an idle distro on Desktop, the PowerShell
  5.1 cmdlet properties the facts script reads, whether `Start-Process` keeps a `#fragment`, virtiofs drive mounts that
  show as 9p, and listener ownership under mirrored networking.

## Alternatives considered
- **A write probe** (a container writes a file, the host reads its owner): needs cleanup, cannot run under a 2.1.0 shim
  and is denied on userns-remap hosts.
- **Reading the owner as the image's default user:** on Docker Desktop for macOS it reads that user (33), never 0.
- **Treating "Docker Desktop on WSL" as rootful by product name:** keeps guessing; a rootless Engine in WSL or a later
  Desktop change would break it again.
- **`wslview` or `xdg-open` under WSLg:** wslu is gone from Ubuntu 26.04; WSLg opens a Linux browser, not the user's.
- **fieldset running the Windows firewall commands itself:** changes Windows settings, which the scope excludes; it
  needs an elevated PowerShell anyway.
- **Folding the Windows-drive check into the reserved-path lists:** would also refuse backups and file arguments on
  `/mnt/c`, which work.
