# 0086: `fsa upgrade` and `fsa rollback`
- Status: accepted
- Date: 2026-10-20
- Refines: ADR 0058 (health-gated upgrades), ADR 0066 (journal, lock, `launchDetached`, exit codes), ADR 0074 (the gate),
  ADR 0084 (safety backups), ADR 0085 (restore primitives), ADR 0060/0067 (release manifest, harness releases)

## Context
Plan unit U18 (PLAN M12-T03): BUILD_PROMPT_V2 §7 (`fsa upgrade`, `fsa rollback`), R33–R37, plan R54, R55, R57, R62, R64,
R68, R69, AE7, F5, J7; KTD5, KTD19, KTD25, KTD26. U16 (ADR 0084) supplies the safety backup, U17 (ADR 0085) the atomic
restore path, the ledger and the epoch bump. Facts this relies on, checked 2026-10-20: Laravel's health route `/up` is
excepted from maintenance mode (`PreventRequestsDuringMaintenance::except`), so a broken release is simulated by its
front controller, not by `artisan down`; FrankenPHP runs in classic mode, so the gated app holds no database session
between requests and `/up` opens none; libpq reads `PGAPPNAME` and `PGOPTIONS` (`-c lock_timeout=60s`) for PDO's
connections; `RedisQueue::pendingSize/reservedSize/delayedSize` count by state; Docker accepts a read-write bind mount
nested in a read-only one; a container on Docker's default bridge reaches another container's address on that bridge.

## Decision
**Commands (§7, R33, R37).** `fsa upgrade [--to <version>] [--major] [--drop-jobs] [--resume]` and `fsa rollback
[--identity-file … --passphrase-file …|--passphrase-stdin]`. Exit codes stay those of ADR 0066: 0 (also "already at
that version", `changed: false`), 2, 3, 4 (committed, but opening failed), 5, 6 (rollback without confirmation), 7, 8,
9, 10. `fsa upgrade-child` is a hidden image role (docker/app/fsa `roles`), never a user command.

**Detached supervisor (KTD5).** The foreground `fsa upgrade` starts `fsa upgrade --supervise` as a detached container
of the **installed** image (`Supervisor::launchDetached`: `<project>-supervisor`, `--rm`, `restart: no`, labelled
`supervisor`, one per instance, exit 9 `supervisor_running` for a second one) with the socket, the instance directory
and the launcher's file mounts at the same paths, the host facts as FSA_* variables (no nonce: LaunchGuard skips) and
FSA_SUPERVISOR_RESULT naming a 0600 result file in `operations/`. The foreground follows the journal (one progress event
per phase) until the container is gone, then renders the envelope from that file (`CommandResult::fromArray`); without
one it exits 1 `supervisor_lost` naming `fsa status` and `fsa upgrade --resume`. Killing the foreground, or its ops
container, leaves the supervisor running (J7).

**Target release (R33, R58, KTD19 step 1).** The supervisor downloads the target's manifest and bundle (ReleaseFeed)
from FSA_RELEASE_URL (new HostFacts field; https, plain http only in harness builds) or GitHub Releases:
`download/v<version>/` for `--to`, `latest/download/` otherwise; into a fresh `releases/.download-*`, verified with the
pinned cosign image (ManifestVerifier) and only then stored under `releases/<version>/`. A 404 is exit 2
(`no_latest_release` with "name the version", `release_not_found`). UpgradePath then refuses with exit 7 and names the
path: an older `--to` (`downgrade_refused`: `fsa rollback`), a new major without `--major` (`major_upgrade`, with the
notes URL), an installed version below the target's `upgrades_from` (`upgrade_path`: the two-step `--to` commands).

**Journal (KTD19, KTD26, R54).** Operation `upgrade`, each phase written before it starts (UpgradeJournal):

| phase | KTD19 | on failure |
|---|---|---|
| `release`, `doctor`, `pull` | 1, 2: target verified, path, Docker/architecture/registry checks, free space for 5× the newest backup + 1 GiB, every digest (app first) | exit 2/3/7/9/10, nothing changed |
| `quiesce` | 3, 4: gate up, scheduler and backup stop, Horizon drains (≤ 300 s), queue stops (100 s grace), `audit:seal` | `revert`: gate down, queue/scheduler/backup start, exit 9 |
| `safety_backup` | 5: SafetyBackup, then verified by the restore's prepare step (checksums, HMAC, `pg_restore --list`, migrations) | `revert`, exit 9 `safety_backup_unverified` |
| `migrate` | 6: the child (below) | automatic rollback, exit 5 |
| `start` | 7: staged files validated and made live; app and every changed service but queue/scheduler/backup/mailpit/tunnel up behind the gate | rollback, exit 5 `target_unhealthy` with the logs |
| `smoke` | 8: smoke test with a fresh token (through the edge in domain mode); no invalid index | rollback, exit 5 |
| `commit` | 9: state = target version and digests, `previous` = version, digests and compose file, history `upgraded` | — |
| `open` | 10: gate down, queue/scheduler/backup up, superseded `safety-upgrade-*` removed (R57), this one sealed, stage and scratch removed | exit 4 `open_failed`, journal open, never rolled back |

A resumed run (`--resume`; plain `fsa upgrade` takes over a committed journal too) decides from the journal: a rollback
that began is finished; a committed upgrade only opens ("the next fsa call finishes opening"); an upgrade killed after
its safety backup rolls back (exit 5 `upgrade_interrupted`); one killed while quiescing reverts (exit 9).
`fsa status` names the phase and `fsa upgrade --resume` with what it will do (OperationUnfinished).

**The gate and R62 (ADR 0058's open question).** The installed app stays up behind the raised gate through steps 3–6 and
answers every external request with 503 in every mode (only its loopback `/up` and the operation's smoke token pass,
ADR 0074); scheduler, backup and queue stop, so "only the app runs during the gate". Step 7 replaces it with the target's
app, still gated; while Compose recreates it, connections are refused for seconds (clients retry, J7). No separate gate
responder is needed. A host reboot mid-upgrade restarts only the app (`unless-stopped` keeps stopped workers stopped), so
the plan's "queue, scheduler and backup refuse to start behind the gate" is not implemented (ADR 0074 amendment).

**Drain (KTD19 step 4).** `instance:queue-drain` (socket-less one-off) reports pending, reserved and delayed jobs of
every Horizon queue; drained means none pending or reserved: delayed jobs stay in Valkey and run after the upgrade (an
automatic rollback flushes them with Valkey, R35). On a timeout without `--drop-jobs`: exit 9 `drain_timeout`. With it,
once the queue stopped (so no worker still runs an exported job), `--export` reads the raw payloads of the jobs still
pending or reserved, fsa writes them to `operations/dropped-jobs-<id>.json` (0600), and only then `--drop` deletes them
(delayed jobs stay); the count is reported (warning `jobs_dropped`, also by a revert after the drop). The file lies
beside the safety backup, not inside it: the set's `SHA256SUMS` and HMAC are final, and any unlisted file fails its
verification.

**The child (KTD19 step 6, KTD25, R36).** UpgradeChild writes `operations/upgrade-<id>/request.json` (version, digests,
`from`, emulation) and starts the **target** image's `fsa upgrade-child` detached, as the owner, on the instance network,
without the socket, with the instance directory read-only and only the stage writable (FSA_UPGRADE_STAGE). The child
(StageRenderer) renders the target's compose, env and service files into `stage/files` with its own renderer from the
state (target version and digests) and the current env files' secrets, then (ChildMigration) runs `php artisan
instance:upgrade-migrate` with the staged app environment plus `PGAPPNAME=fsa_upgrade_<id>` and `PGOPTIONS=-c
lock_timeout=60s`. The app side (UpgradeMigrator) waits up to 30 s for zero foreign client sessions on the database,
sets `lock_timeout`, runs the pending migrations through Laravel's migrator, the version steps since `--from`
(VersionSteps: idempotent, none in 2.0.0) and refuses invalid indexes. The outcome is `stage/result.json`; none means
the child crashed (exit 5 `child_crashed` with its last log lines, read through the new `DockerGateway::containerLogs`).
Log lines kept in the journal and the result have every secret of the env files masked (UpgradeLogs).
The supervisor polls the container every 5 s and, every 30 s, `instance:upgrade-db activity` (a socket-less one-off on
`pg_stat_activity`): the child is alive while its session was active within 300 s; past that (`child_hung`) or past 6 h
(`child_timeout`) it removes the container, terminates the session (`instance:upgrade-db terminate`) and rolls back.

**No `CONCURRENTLY` (ADR 0058).** A lint (tests/Unit/Instance/UpgradeMigrationLintTest.php) refuses `CONCURRENTLY` and
ConcurrentIndex in every migration after the 1.0.0 baseline (`2026_10_23_100000_…`); the four 1.0 index migrations that
build concurrently already ran on every database an instance can hold.

**Staged files (step 7).** StagedRelease validates the staged project with `docker compose config` and requires its app
service to run the target's digest and pass the installed release's ComposeGuard (no Docker socket, no RUN_MIGRATIONS,
no reserved labels, no `$`) and refuse a privileged service (`staged_invalid`, rollback): the target is a signed release,
but its files are started by the socket holder. Then it copies every staged file but the runtime
directory and the marker into the instance directory, removes env files of services the target no longer renders, and
writes the compose file last. A target that adds a service or an env key therefore starts from its own files.

**Automatic rollback (R35, R55, R68).** UpgradeRollback, journaled: `rollback_stop` (child removed and its session
terminated, every writer stopped, the previous release's files rendered again from the uncommitted state by the
installed renderer, Postgres and Valkey up with them, gate raised) → `rollback_ledger` (the safety backup's own
`audit-heads.json`: writers were stopped since, so they are the replaced database's heads) → `rollback_prepare` (the
safety backup verified again into the scratch directory) → `rollback_database` → `rollback_storage` → `rollback_finish`
(`instance:restore-finish --reason=rolled_back --no-carry`: new epoch, `instance.rolled_back` first, no R64 carry and no
sequence floors) → `rollback_swap` (databases renamed once: a resumed swap reads the database list first; the state names
the fresh volume; Valkey flushed; once the swap began, a resumed rollback never restores again and only finishes it) →
`rollback_verify` → `rollback_open` (every service but the quick tunnel; gate down) →
`rollback_cleanup` (the migrated database and the superseded volume go, stage and scratch removed, the safety backup
sealed, history `upgrade_rolled_back`). Exit 5 with the cause and its logs. A failure until the gate went down is exit 8
(UpgradeHalt): writers stopped, gate up, journal open, data `safety_backup` (path), `digests.previous`/`digests.target`,
the names, and the hint `fsa upgrade --resume` (which repeats the rollback) or `fsa install --from-backup <path>`.
Pre-upgrade safety backups of failed upgrades stay; the next committed upgrade removes them.

**Manual rollback (R37, R64).** RollbackGuard: the state's `previous` and the last `upgraded` entry's safety backup in
`backups/`, else exit 7 (`no_previous_release`, `safety_backup_missing`); exit 7 `restored_since_upgrade` after a
`restored`, `reset`, `installed_from_backup` or `rolled_back` entry since that upgrade. The confirmation names the target
version, the backup and the loss (`instance:rollback-loss`: submissions, audit entries, accounts, forms, mails,
attachments written since the backup). RollbackOperation, journaled: `plan` → `prepare` (the drill's verdict: an older
backup is expected; it must be this instance's; a sealed backup needs the identity) → `pull` → `space` → `quiesce` (like
an upgrade) → `ledger` (the live heads) → `export` → `safety_backup` (`safety-rollback-*`) → `restore_database` →
`restore_storage` → `render` → `finish` → `erasures` → `swap` → `verify` → `open` → `gate` → `cleanup` (history
`rolled_back`; `previous` cleared, so a second rollback is exit 7). Before the swap a failure goes back (`abort`: scratch
removed, gate down, workers up, exit 5); from the swap until the gate went down the upgraded release returns (`return`:
databases renamed back once, the state and compose file of before, exit 5); a failed way back is exit 8 and `fsa
rollback` continues it.

- **The R64 carry across versions.** The older code cannot read the newer schema, so the current image exports what the
  carry reads (`instance:security-export`, SecurityExport: format `fsa-security-state` version 1, per table its columns
  and rows, the audit entries after the backup, the sequences' last values) into a 0600 file of the scratch directory;
  the previous image's `instance:restore-finish` reads it from stdin (`carry`), materialises it in a schema
  `fsa_carry_<hex>` of the restored database (typed like that database's own tables, `CREATE TABLE … AS SELECT … WITH NO
  DATA`; a table it lacks is skipped), runs the unchanged carry and sequence floors against a connection whose
  `search_path` is that schema first, and drops it. An export of another format or a newer version is refused
  (`carry_format`).
- **The previous image renders its own files.** The `render` phase runs the previous image's child render-only
  (`migrate: false`, settings override `storage_volume` = the fresh volume), so the restored release starts from the
  compose and env files it renders for the current settings, and its `finish` runs in that image. This requires a
  previous release that knows the `upgrade-child` role (2.0.0 and later).

**Older backups in `fsa restore` (ADR 0085 amendment).** A backup with fewer migrations is migrated in place by the
installed release's `instance:upgrade-migrate` on the scratch database (OlderBackupMigration, journaled `upgrade_child`
after `restore_database`) instead of exit 7 `upgrade_child_unavailable`.

## Alternatives considered
- **A separate gate responder while the app is stopped (steps 3–6):** a second container in every mode for minutes; the
  installed app behind its own gate already answers 503 and opens no database session.
- **The installed version's renderer for the target (or the previous release's snapshot for a manual rollback):** a
  target that adds a service or an env key would start without it, and a snapshot goes stale after `config set`.
- **Reading the pre-rollback database directly in the rollback's carry (as a restore does):** the older code would read a
  newer schema; a versioned export is the contract between the two releases.
- **Rolling forward after a crash before the commit:** a half-migrated database is not a state anyone tested; the safety
  backup is.
- **Counting delayed jobs as undrained:** a single job delayed by an hour would block every upgrade.

## Consequences
- Without `--to`, the target is GitHub's `latest`: the newest stable release. When that is a new major, `--major` or
  `--to <newest within the major>` is needed (exit 7 names it); a pre-release instance needs `--to`.
- Downtime: from the gate through the safety backup, the migrations and the target's health check; writes get 503
  throughout and a few refused connections while Compose recreates the app.
- The child's liveness is its session's `active` state at a probe every 30 s: a version step doing more than 300 s of
  PHP work between statements counts as hung and rolls back; such a step must touch the database meanwhile.
- Mailpit and the quick tunnel are never recreated by an upgrade; a target that changes Mailpit's image or settings
  applies them with the next `fsa config set`.
- A rollback restores only data written until the safety backup (R37, stated before it starts); delayed jobs are lost
  with an automatic rollback's Valkey flush.
- J7 builds `2.0.0-rc.1-test` and targets `rc.2-test` (a migration), `rc.3-test` (unhealthy), `rc.4-test` (`fsa`
  crashes) derived from it (ReleaseHarness::publishDerived); `2.0.0-test` is no version ReleaseVersion accepts (its
  pre-release must start with alpha, beta or rc), so the happy target is a release candidate too.
