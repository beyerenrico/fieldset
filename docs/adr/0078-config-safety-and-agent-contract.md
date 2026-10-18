# 0078: Config safety and the agent contract of fsa config
- Status: accepted; amended 2026-10-18 by ADR 0080 (completion step, SMTP credential stash) and ADR 0082 (tunnel
  cleanup before `config set`)
- Date: 2026-10-18
- Amends: ADR 0077 (result of a reverted change, flow, resume), ADR 0066 (supervisor resume check, progress events),
  ADR 0072 (repeated install, ports check), ADR 0074 (runtime writer), ADR 0075 (certificate wait, uninstall warning,
  edge ports), ADR 0076 (proxy address drift)

## Context
The M10 gate review of `fsa config` (ADR 0077) found, by reading the code: a seven-minute certificate check without
any progress, after which a killed foreground process (agent tool timeout, SSH drop) left an unverified configuration
applied with its journal open; every reverted change reported exit 4 `failed` and `retryable: true`; a resume could
adopt another run's journal, repeat a change whose revert had failed, or be blocked by an engine lookup in planning;
queue and scheduler kept sending mail with a URL that was not verified yet; a failed revert always claimed the old
settings were back. Agents also lacked the exact commands to repeat, which keys apply in which mode, and honest hints
for a switch to proxy mode. Facts checked on 2026-10-18: `docker run --rm --entrypoint php form-schema-app:local -m`
lists `pcntl` and `posix`; the shim starts the ops container with `--init`, and SIGTERM, SIGHUP and SIGINT sent to a
`docker run --rm --init -i` client (no TTY, as an agent runs it) reach a PHP handler in the container, the client
waiting for it to finish (tried on Docker Desktop 29.9); the longest job timeout is 90 s (`config/horizon.php`, `exports`);
Horizon and `schedule:work` stop taking work on SIGTERM and exit when running work is done.

## Decision
**Result of a failed change (amends ADR 0077 "Revert" and "Exit codes").** A change that failed and was reverted is
exit 5, status `rolled_back` (§7's code for "failed and rolled back"; the journal is archived with outcome
`rolled_back`), `changed: false`, `reverted: true`, the original error code. `retryable` follows the cause:

| cause | retryable | data |
|---|---|---|
| `docker_unavailable`, `docker_command_failed` | true | `retry_after` 30 / 15 |
| `interrupted` (a signal) | true | `retry_after` 0 |
| everything else: `certificate_failed`, `services_unhealthy`, `smoke_failed`, `url_check_failed`, `internal_error`, … | false | `fix_first`: the hint |

Exit 4 is no longer used by `config set`. A failed revert stays exit 8 `revert_failed`, now with `revert_steps` (which
of `files` and `services` completed), a recovery text that matches them (state.json is called restored only after
`files`), and `stopped_services`: only app, queue, scheduler, edge and mailpit stop; Postgres and Valkey keep running.

**Interruption.** `Operations\Interruption` installs SIGTERM, SIGINT and SIGHUP handlers (pcntl, async signals) around
the supervised run and restores the previous ones afterwards. Armed during snapshot, preflight, apply, verify and the
worker start, a signal throws `OperationInterrupted` (code `interrupted`) into the running step: before any change the
run aborts with exit 9 and nothing changed; during apply, verify or the worker start the normal revert path runs and
journals itself. The revert and the audit run disarmed: a further signal is only recorded, so the revert finishes.
SIGKILL cannot be caught; the journal then stays open and the resume rules below apply. Rejected: running `config set`
in the detached supervisor container (KTD5) with the foreground tailing the journal: a second process model for a
step that takes minutes, while the handlers give the same guarantee for every catchable signal and the resume covers
the rest.

**Progress (amends ADR 0066's NDJSON event).** Events may carry numeric fields after `message`: `max_seconds` on
"Starting <phase> (at most …)" when the phase knows its limit, `elapsed_seconds` on "Completed <phase> in …" and
"<phase> failed after …", and `attempt`/`attempts` (with `elapsed_seconds` and `max_seconds`) on the certificate
wait. Readers ignore unknown keys. The certificate wait (amends ADR 0075 "Smoke test") is a PHP loop: one `curl`
container per attempt (`--max-time 15`), 5 s apart, at most 40 attempts within 420 s, each attempt one event
("Waiting for the certificate for <domain> (attempt 7/40, 35 s of at most 420 s)").

**Writers stopped while unverified (KTD19).** Apply stops queue and scheduler first (`compose stop`, their
`stop_grace_period: 100s` lets running jobs finish), renders the target and starts every other service; verify runs;
the new phase `workers` then brings the whole project up on the verified settings. A revert brings the whole previous
stack back, workers included. Phases: snapshot, preflight, apply, verify, workers, audit (revert on failure).

**Resume (amends ADR 0077 "Resume").**
- A live lock refuses `config set` up front (exit 9 `instance_locked`), journal or not, before the URL-impact
  one-off and the prompt.
- `Supervisor::run(accepts:)`: a run resumes only when it found an unfinished journal itself, and only a journal
  whose request (`ConfigRequest::sameAs`) is its own; a journal another run opened meanwhile is exit 9 and gets no
  `resumed` event.
- The change of a resume comes from the journal (the snapshot's previous state, the preflight's target), never from a
  new plan: planning may ask the engine (`trusted-proxy auto` with the proxy down) and must not block a resume.
- When the journal shows a started or failed revert, the resume only finishes the revert and verifies the old
  settings from the snapshot (no confirmation, no change repeated): exit 5 `rolled_back` with `resumed_revert: true`.
  Running the command again afterwards tries the change anew. No `--revert` flag is needed.

**Agent contract.**
- `data.url` and `data.mode` are always strings (the effective values); the pending change of exit 6 is
  `data.change` (`{key, from, to, url: {from, to}, mode: {from, to}}`).
- Exit 9 `operation_unfinished`, `fsa status` (`data.operation`, warning) and exit 8 name the exact command with its
  options (`ConfigRequest::commandLine()`, `--acme-staging` as typed, the CA root as `<the same file>`) as
  `data.command` and `data.options`. Results carry `data.next_commands` (the instance's launcher path, as install's):
  `fsa status`, after a URL change `fsa login-link <owner-email>`, on Let's Encrypt staging first
  `fsa config set acme-ca production --yes`. Install and `config set` on staging warn `acme_staging`.
- `config show` keys carry `modes`, `settable_now` and `use_instead` (`url`: `port` in local mode, `domain` in domain
  mode; ACME keys outside domain mode: `domain`; `port` in domain mode: `mode`; `mode` in local mode: `domain`; `port`
  on a shared proxy network: `null`).
- `config set <later key>` without a value answers `setting_not_available`; the key is checked before the value.
- A repeated install with different flags (amends ADR 0072) lists `data.flags: [{flag, key|null}]` and says plainly
  that proxy mode cannot be entered or re-wired on an installed instance: `fsa uninstall` and a fresh install, with the
  data carried over only from 2.0.0-rc.1 (`--from-backup`); `fsa uninstall --keep-backups` writes a final backup.
  `--trusted-proxy` matches the trusted proxy by container name, address or ID prefix (one read-only network inspect
  for an ID), so it agrees with `config set trusted-proxy`.
- Proxy lookup failures name `fsa config set trusted-proxy` when they come from `config set`, the install flag
  otherwise, with `data.candidates` (name, address).

**Doctor and status (amends ADR 0076).** The doctor's `proxy` check re-resolves `proxy_container` on the shared
network (the default network's gateway in the `port` setup) and warns `proxy_address_changed` with `fsa config set
trusted-proxy auto` when the trusted address no longer matches (closes QUESTIONS 35). `fsa status` reports
`data.proxy {setup, network, container, trusted, current, moved}` and the same warning when the proxy has a known new
address (a proxy no longer attached warns in the doctor only). Check results may carry their own warning code
(`CheckResult::warningCode()`).

**Runtime writer (amends ADR 0074).** A re-render keeps the gate raised (operation `unknown`) when the current file
exists but cannot be parsed, and refuses (UnsafePath) when it exists but cannot be read; only a missing file starts
lowered. Re-renders and gate changes keep the tunnel block (`hostname`, `expires_at`, `peer`, `admin`).

**Uninstall and ports (amend ADR 0075, ADR 0072).** The duplicate-certificate warning (prompt and
`certificates_removed`) depends on the edge's certificate volume (`EdgeService::volumeName`) being among the labelled
volumes, not on the current domain. `PortProbe` reads a refusal as `privileged_ports` only in RootlessKit's wording or
for a host-wide probe below 1024. The edge's ports check also probes `[::]:80/443` when the host reports an IPv6
address (`details.ipv6`); an engine that cannot bind IPv6 leaves it unprobed. An install on a shared proxy network
chooses only the catcher UI's loopback port.

## Consequences
- Agents branch on exit 5 for "nothing changed, the old settings run" and on `retry_after`/`fix_first` for what to do.
- A `config set` killed with SIGKILL during apply or verify repeats the change on the next run (from the snapshot,
  with the journaled target); one killed during its revert finishes the revert first.
- During apply and verify no queued job runs (up to `ConfigRollout::APPLY_SECONDS` + verify); mail queues up and goes
  out with the verified URL.
- Interrupting a run reverts even a change that was about to pass its checks; that is the price of never leaving an
  unverified configuration behind.
- Unverified until the human checklist: signal delivery through a real SSH session drop on a Linux host (the shim's
  `docker run -it` with a TTY), and IPv6-only listeners on 80/443 on a dual-stack host.

## Amendment 2026-10-18: the completion step and the SMTP credential stash (ADR 0080)
- An applier's `SettingHooks::completed()` runs after `workers` and before `audit`, outside the journal phases: the
  change is verified and running by then, so it never reverts, and a failure becomes the warning `completion_failed`.
  The phases stay snapshot, preflight, apply, verify, workers, audit; verify also runs the applier's `verify()` hook,
  and its progress limit adds the hook's `verifySeconds()`.
- A `config set mail` with new credentials: the first apply keeps the previous SMTP user name and password in
  `env/smtp-previous` (0600; not `*.env`, so no service reads it and renders leave it alone), never in the journal.
  Any revert, also one a resumed run finishes, renders the previous state with them; the file is deleted once the
  change is settled (verified and running, or reverted).

## Amendment 2026-10-18: tunnel cleanup before `config set` (ADR 0082)
Before `config set` (any key but `operator-email`) plans and takes the lock, an expired or stopped quick tunnel that
the state records is removed in its own journaled operation (`tunnel`, action `expire`, `TunnelSweeper`), outside the
`config` journal. The result then carries `tunnel_removed` and `changed: true`, also when the change itself fails; a
failed cleanup is the warning `tunnel_cleanup_failed` and the change still runs. `fsa demo` and `fsa tunnel` sweep
the same way.
