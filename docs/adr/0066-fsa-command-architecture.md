# 0066: fsa command architecture: gateway, state, lock, journal, envelope
- Status: accepted; amended 2026-10-18 at the M9 gate (journal closing, any-exception supervisor, `changed` on
  failures, journal history cap, personal install inputs as digests); amended 2026-10-18 at the M10 gate by ADR 0078
  (progress event fields, foreign-journal check on resume)
- Date: 2026-10-18

## Context
form-schema-app 2.0 manages an instance's whole lifecycle through `fsa` commands run in a throwaway ops
container that holds the Docker socket (BUILD_PROMPT_V2 §6.2, §7; plan KTD1–KTD5, KTD24, KTD25, R49, R50, R54,
R55, R63, R69). Every later unit (install, doctor, upgrade, backup, restore, adopt, reset, tunnel) needs the
same base: one way to reach Docker, an instance state that two versions can read, a lock and journal that
survive crashes, and a result contract agents can rely on. Plan unit U2 builds that base in
`app/Domain/Instance`.

## Decision
**Docker gateway (KTD1).** `Docker\DockerGateway` is the only route to Docker. `CliDockerGateway` drives the
docker CLI and Compose plugin through Symfony Process behind a `CommandRunner` seam (`#[Bind]`), without a shell.
Compose calls always pass `--project-name`, `--file` and `--project-directory`, clear the caller's `COMPOSE_*`
variables and set `COMPOSE_DISABLE_ENV_FILE=1`, so a dev `COMPOSE_FILE` or a stray `.env` cannot change what
runs. One-off and `docker run` environment values travel by name (`--env NAME`) in the CLI's environment, never
in argv. A missing CLI or an unreachable daemon is exit 10. The gateway resolves only after an `fsa:*` command
has opened a marked instance directory; elsewhere resolving it throws `OpsContextRequired`.
`InstanceServiceProvider` only registers bindings: no database or cache access at boot.

**Instance directory and state (KTD6, KTD7).** The directory carries a marker file `.fsa-instance` (JSON with
`schema_version`, or empty when written by hand). Every command except `fsa version` refuses to run without it
(exit 2). All file access goes through `State\SafeFiles`: plain relative paths only, temp file plus `fsync` plus
rename, modes set explicitly, and a symlink as the target or a parent that resolves outside the directory is
refused (exit 3). `State\InstanceState` is an immutable, versioned JSON document (`schema_version` "major.minor").
A newer minor's unknown keys are kept and its version written back; a higher major exits 7 and the file is never
rewritten. The state holds no secrets: secret-looking setting keys are refused, and secrets live only in
`env/<service>.env` (0600 in a 0700 directory), written in Compose env-file syntax (single-quoted literals,
double quotes with `\\ \" \$` escapes when the value holds a single quote).
(Amended 2026-10-18: the owner's e-mail and name are personal, not secret, but the state history is never
pruned and archived journals stay on the host, so both hold only keyed digests (`Install\RecordDigest`,
`hmac-sha256:` with a key derived from the instance's APP_KEY; keys in APP_PREVIOUS_KEYS still match). Only the
open journal keeps the values, because a resume needs them; before the secrets phase there is no key yet and
the archive drops them.)

**Lock (KTD4).** `Operations\InstanceLock` is a directory created with `mkdir` (no `flock` over virtiofs)
holding the owner record: lock ID, owner, operation, holder container ID and its `StartedAt`, PID, host UID, and
a heartbeat file. A lock is stale only when Docker reports the holder container gone, stopped, or started at a
different time; heartbeat age alone never is, and a holder outside a container is never stale. Breaking a stale
lock happens under a short-lived takeover marker and re-checks the lock ID. The backup daemon acquires with
`mayTakeOver: false` and calls `releaseOwnStale()` on start, which frees only a lock its own container took
before a restart. `ContainerIdentity` finds the holder's container ID from `/proc/self/mountinfo` (hostname as
fallback). A second acquire while the lock is held exits 9 and names the holder.

**Journal and supervisor (R54, R69, KTD5).** `Operations\OperationJournal` writes the whole journal document
before each phase starts, so a killed process leaves the last started phase and its resume data; it records the
caller's host UID. A finished operation moves to `operations/history/`. `Operations\Supervisor::run()` takes
the lock, journals a takeover (`lock_takeover`), refuses a different operation while one is unfinished (exit 9)
and resumes the same one when asked; completed phases are skipped. A failure before any changing phase exits 9
(unless it already carries a pre-change code: 2, 3, 6, 7, 10) and archives the journal; after a change the
journal stays open for resume unless the outcome is ok or rolled back. `launchDetached()` starts the
one-per-instance supervisor container (`--rm`, `restart: no`, labelled `app.form-schema.role=supervisor`).
(Amended 2026-10-18: an aborted run that changed something keeps its journal open as well; only ok and rolled
back close it (`ResultStatus::closesOperation()`). The supervisor catches any `Throwable`, not only
`InstanceException`: an unexpected exception before any change exits 9 and archives the journal (no stray
`current.json`), after a change it exits 1 (`internal_error`) with the journal left open for a resume. The
history keeps the newest 20 archived journals (`OperationJournal::HISTORY_LIMIT`); older ones are deleted.)
(Amended 2026-10-18 by ADR 0078: `Supervisor::run(accepts:)` lets the caller check the unfinished journal it finds
under the lock (`config set`: `ConfigRequest::sameAs`); a journal that is not its own is exit 9
`operation_unfinished`, nothing is written to it and no `resumed` event is recorded.)

**Result envelope and console base (KTD3, R49, R50).** `Output\CommandResult` renders
`{schema_version: 1, command, status, changed, data, secrets, warnings, errors}`; `status` follows the exit code.
(Amended 2026-10-18: `InstanceException` carries `changed`, and `CommandResult::fromException()` reports
`changed: true` when either the exception or the supervisor says something changed, e.g. a partial uninstall.)
With `--json` stdout carries exactly that document and stderr carries NDJSON `{ts, phase, level, message}`.
(Amended 2026-10-18 by ADR 0078: an event may carry numeric fields after `message`: `max_seconds` on a phase start
whose limit is known, `elapsed_seconds` on "Completed <phase> in …" and "<phase> failed after …", and `attempt`,
`attempts` (with `elapsed_seconds`, `max_seconds`) on each certificate-wait attempt. Readers ignore unknown keys.)
Secret values appear only under `secrets`, or in `--secrets-file` (0600), where `secrets` then names the file.
A per-command `SecretRedactor` scrubs one-time secrets and the secret values of the instance's env files from
data, warnings, errors and progress. `Console\FsaCommand` adds `--json`, `--yes`, `--dir`, `--secrets-file`;
rejects any secret-looking flag (`--smtp-password`, `--app-key` …; `-file`/`-stdin`/`-fd` variants are allowed)
before parsing, with exit 2 and a stdin/file hint, and maps all other usage errors to exit 2 in the envelope.
Destructive commands (`confirmationPrompt()`) exit 6 without a TTY or with `--json` unless `--yes` is given,
because a prompt would corrupt the single JSON document.

**Exit codes.** BUILD_PROMPT_V2 §7 (0, 2, 3, 4, 5, 6, 7, 10) plus 8 (rollback failed, instance stopped) and 9
(aborted before any change), as the plan decided, and **1 for unexpected internal errors**: an uncaught
exception exits 1 in any case, so the envelope names it `internal_error` instead of reusing a code whose
meaning an agent would act on.

**Boundary (KTD24, KTD25).** `tests/Arch/InstanceBoundaryTest.php` fails when `App\Domain\Instance\Runtime`
uses an ops namespace or Symfony Process, and when any namespace outside `App\Domain\Instance` uses an ops
namespace, the Docker gateway included. Host facts come from the shim as `FSA_TTY`, `FSA_HOST_UID`,
`FSA_HOST_GID`, `FSA_HOST_OS` and `FSA_INSTANCE_DIR` (`HostFacts`).

## Consequences
- U3 renders compose, env files and the runtime directory from `InstanceState` through `InstanceDirectory`
  and `EnvFiles`; U5 builds its commands on `FsaCommand` and `Supervisor`; U18 uses `launchDetached()` and the
  journal's resume data.
- `composeConfig()` returns the normalized model including env-file values: never put it into `data`.
- The arch test must be part of a PHPUnit test suite to run in CI (`tests/Arch` in `phpunit.xml`).
- Rejected: binding the gateway from a `CommandStarting` listener (Laravel does not dispatch it under unit
  tests), and a separate `bin/fsa` kernel (duplicated console and test helpers, see plan KTD1).

## Amendment 2026-10-19: env-file values with 0x85 or a trailing backslash (M11 gate)
- `EnvFiles::decode()` splits lines on LF (CRLF read too) instead of PCRE `\R`, which in byte mode also matched 0x0B,
  0x0C and 0x85: the UTF-8 letters Å (C3 85), ą (C4 85) and х (D1 85) cut an SMTP password short when `InstanceSecrets`
  read it back for the next render (`config set port`, `tunnel start|stop`).
- `EnvFiles::encode()` double-quotes a value that ends in a backslash as well: Compose reads `\'` inside single quotes as
  an escaped quote, so `A='x\'` ran on into the next line and the whole file failed to parse (verified 2026-10-19 with
  Compose 5.0.2; every other byte except CR, LF and NUL, control characters included, passes through literally, so the
  SMTP grammar needs no further refusals). Tests: `EnvFilesTest`, `MailConfigSafetyTest`.

## Amendment 2026-10-19: the backup service's lock and files (U16, ADR 0084)
- The backup service has no socket, so it cannot learn Docker's start time for its lock record. It records its init
  process's start as `proc:<clock ticks>` (`LockRecord::PROCESS_START_PREFIX`, `Backup\ServiceIdentity`); such a lock is
  stale only when Docker reports the container gone or stopped, never because the start times differ. It still acquires
  with `mayTakeOver: false` and releases only its own lock from before a restart.
- `SafeFiles` (and `InstanceDirectory::open()`) take an optional owner UID:GID: a root process hands every file and
  directory it creates to that owner before it becomes visible (temp file chowned before the rename), so the lock
  records and backups the service writes as container root stay readable and removable for the owner and fsa.
