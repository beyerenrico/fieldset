# 0066: fsa command architecture: gateway, state, lock, journal, envelope
- Status: accepted
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
journal stays open for resume unless the outcome is ok, aborted or rolled back. `launchDetached()` starts the
one-per-instance supervisor container (`--rm`, `restart: no`, labelled `app.form-schema.role=supervisor`).

**Result envelope and console base (KTD3, R49, R50).** `Output\CommandResult` renders
`{schema_version: 1, command, status, changed, data, secrets, warnings, errors}`; `status` follows the exit code.
With `--json` stdout carries exactly that document and stderr carries NDJSON `{ts, phase, level, message}`.
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
