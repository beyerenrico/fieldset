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
| `release`, `doctor`, `pull` | 1, 2: target verified, path, Docker/architecture/registry checks, free space for the larger of 5× the newest backup and 3× the live database and storage volume, + 1 GiB, every digest (app first) | exit 2/3/7/9/10, nothing changed |
| `quiesce` | 3, 4: gate up, scheduler and backup stop, Horizon drains (≤ 300 s), queue stops (100 s grace), requests in flight end (≤ 120 s), `audit:seal` | `revert`: gate down, queue/scheduler/backup start, exit 9 |
| `safety_backup` | 5: SafetyBackup, its name noted in the journal, then verified by the restore's prepare step (checksums, HMAC, `pg_restore --list`, migrations) | `revert` (the backup sealed), exit 9 `safety_backup_unverified` |
| `migrate` | 6: the child (below) | automatic rollback, exit 5 |
| `start` | 7: staged files validated, the live release files snapshotted, the staged ones made live; app and every changed service but queue/scheduler/backup/mailpit/tunnel up behind the gate | rollback, exit 5 `target_unhealthy` with the logs |
| `smoke` | 8: smoke test with a fresh token (through the edge in domain mode); no invalid index | rollback, exit 5 |
| `commit` | 9: state = target version and digests, `previous` = version, digests and compose file, history `upgraded` | — |
| `open` | 10: gate down, queue/scheduler/backup up (orphans removed), superseded `safety-upgrade-*` and every `safety-rollback-*` removed (R57), this one sealed, the snapshot kept as `operations/previous-release`, stage and scratch removed | exit 4 `open_failed`, journal open, never rolled back |

A resumed run (`--resume`; plain `fsa upgrade` takes over a committed journal too) decides from the journal: a rollback
that began is finished; a committed upgrade only opens ("the next fsa call finishes opening"); an upgrade killed after
its safety backup rolls back (exit 5 `upgrade_interrupted`); one killed while quiescing reverts (exit 9).
`fsa status` names the phase and `fsa upgrade --resume` with what it will do (OperationUnfinished).

**The gate and R62 (ADR 0058's open question).** The installed app stays up behind the raised gate through steps 3–6 and
answers every external request with 503 in every mode (only its loopback `/up` and the operation's smoke token pass,
ADR 0074); scheduler, backup and queue stop, so "only the app runs during the gate". Step 7 replaces it with the target's
app, still gated; while Compose recreates it, connections are refused for seconds (clients retry, J7). No separate gate
responder is needed. An automatic rollback keeps it so (review A9): `rollback_stop` replaces the target's app with the
previous release's, behind the gate, before the restore, and the database swap renames while that gated app runs (it
holds no session, as the manual rollback's swap beside the upgraded app already relies on). A host reboot mid-upgrade restarts only the app (`unless-stopped` keeps stopped workers stopped), so
the plan's "queue, scheduler and backup refuse to start behind the gate" is not implemented (ADR 0074 amendment).

**Drain (KTD19 step 4).** `instance:queue-drain` (socket-less one-off) reports pending, reserved and delayed jobs of
every Horizon queue; drained means none pending or reserved: delayed jobs stay in Valkey and run after the upgrade (an
automatic rollback flushes them with Valkey, R35). On a timeout without `--drop-jobs`: exit 9 `drain_timeout`. With it,
once the queue stopped (so no worker still runs an exported job), `--export` reads the raw payloads of the jobs still
pending or reserved, fsa writes them to `operations/dropped-jobs-<id>.json` (0600), and only then `--drop` deletes them
(delayed jobs stay); the count is reported (warning `jobs_dropped`, also by a revert after the drop). The file lies
beside the safety backup, not inside it: the set's `SHA256SUMS` and HMAC are final, and any unlisted file fails its
verification. fsa never deletes it (it holds the only copy of those jobs).

**Requests in flight (review A1).** The gate turns new requests away, but one already inside (an import, a publish, a
PDF) can still commit after the safety backup's snapshot, or, for `fsa rollback`, after the security export, and be
lost. After the workers stopped, InFlightRequests polls `instance:upgrade-db foreign` (the database's other client
sessions: pid, application name, user, state; never query texts) every 2 s until none is left; only then are the audit
entries sealed and the safety backup taken (and a rollback's state exported). The gated app opens no session (classic
FrankenPHP, the gate before any route middleware), so a session that stays 120 s is a stuck request or another client:
exit 9 `requests_in_flight` naming them, and the operation goes back.

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
(`child_timeout`) it removes the container, terminates the session (`instance:upgrade-db terminate`) and rolls back. A
`docker inspect` that fails is retried (5 attempts) before it counts, and a probe that fails is unknown: it neither keeps
the child alive nor ages it (review A6). The child runs on the instance's `<project>_default` network, which is not
internal: like the app it may reach the internet (a target's migrations need no egress, but nothing prevents it).

**No `CONCURRENTLY` (ADR 0058).** A lint (tests/Unit/Instance/UpgradeMigrationLintTest.php) refuses `CONCURRENTLY` and
ConcurrentIndex in every file the child runs: database/migrations, the migration paths packages register (none today)
and the version steps (VersionSteps::STEPS). The four 1.0 index migrations that build concurrently already ran on every
database an instance can hold; they are an exact allowlist by file name, so a new file with a backdated name is linted
(review A12).

**Staged files (step 7, review S2).** The target is a signed release, but its files are started by the socket holder,
so StagedRelease takes only release files (ReleaseFiles: `compose.yaml`, `env/<service>.env` of a staged service,
`edge/<file>`, `backups/.instance.json`; `runtime/` is ignored) and refuses a stage holding anything else (`state.json`,
journals, locks, backups, releases: `staged_invalid`, rollback). The project must pass `docker compose config`, its app
service must run the target's digest, every service an image the release pins, and the installed release's
ComposeGuard, which every renderer output passes too: no Docker socket, no RUN_MIGRATIONS, no reserved labels, no `$`,
bind mounts only of `./…` paths without `..`, no volume driver options, capabilities only those the services add back
(CHOWN, DAC_OVERRIDE, DAC_READ_SEARCH, FOWNER, NET_BIND_SERVICE), no devices, no security option but
`no-new-privileges`, no pid/ipc/uts/userns/cgroup/network_mode, nothing privileged, no build/extends/volumes_from, env
files only from `./env/`, no include/secrets/configs. Before the staged files go live, the live release files are
snapshotted (`operations/files-before-upgrade-<id>`, written under a temporary name and renamed); then the staged ones
are written with fsa's modes (env files 0600 whatever the child left, the edge's 0644), env files of services the target
no longer renders are removed, and the compose file is written last. A target that adds a service or an env key
therefore starts from its own files. A future release that renders another kind of file needs the installed release to
know it (the allowlist is the installed one's).

**Automatic rollback (R35, R55, R68).** UpgradeRollback, journaled: `rollback_stop` (child removed and its session
terminated, queue, scheduler and backup stopped, the snapshot of step 7 put back, so a target that changed the env layout
cannot cost a secret (review A3), the previous release's files rendered again from the uncommitted state by the
installed renderer, gate raised, Postgres and Valkey up with orphans removed (services only the target had, review A4),
then the previous app in place of the target's behind the gate) → `rollback_ledger` (the safety backup's own
`audit-heads.json`: writers were stopped since, so they are the replaced database's heads) → `rollback_prepare` (the
safety backup verified again into the scratch directory) → `rollback_database` → `rollback_storage` → `rollback_finish`
(`instance:restore-finish --reason=rolled_back --no-carry`: new epoch, `instance.rolled_back` first, no R64 carry and no
sequence floors) → `rollback_swap` (databases renamed once: a resumed swap reads the database list first; the state names
the fresh volume; Valkey flushed; once the swap began, a resumed rollback never restores again and only finishes it) →
`rollback_verify` → `rollback_open` (every service but the quick tunnel, orphans removed; gate down) →
`rollback_cleanup` (the migrated database and the superseded volume go, stage, snapshot and scratch removed, the safety
backup sealed, history `upgrade_rolled_back`). Once `rollback_open` completed, a resumed run only finishes the cleanup:
no gate, no smoke test, never exit 8 (review A2). Exit 5 with the cause and its logs. A failure until the gate went down is exit 8
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
`rolled_back`; `previous` cleared, so a second rollback is exit 7; older `safety-rollback-*` sets and the previous
release's files removed). The swap snapshots the live release files (`operations/files-before-rollback-<id>`) before
it writes the previous image's. Before the swap a failure goes back (`abort`: scratch removed, gate down, workers up;
exit 9 when the cause changed nothing, a drain timeout or requests in flight (R69), else exit 5; exit 4
`services_unhealthy` when the workers do not start, with the upgraded app serving and the journal open, review A8).
From the swap until the gate went down the upgraded release returns in journaled steps (review A10): `return_stop` →
`return_database` (renamed back once) → `return_files` (the state before and exactly the snapshot's files: never
rendered again, whichever image runs the resume) → `return_flush` → `return_verify` → `return_open` (orphans removed,
gate down): exit 5; once `return_open` completed a resumed run only finishes. A failed way back is exit 8 (R55: the
instance stopped, `safety_backup` with the pre-rollback set's path, `digests.previous`/`digests.current`, the names) and
`fsa rollback` continues it. Every way back but exit 8 seals the safety backups (review S1).

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
  compose and env files it renders for the current settings, and its `finish` runs in that image. The child reads the
  secrets from env files in the layout the previous release wrote them in: the files the upgrade snapshotted
  (`operations/previous-release`) with the current value of every key both have, mounted read-only over `env/` (a target
  that renamed keys cannot make it regenerate a secret, review A3). Its stage then passes StagedRelease::validate
  against the previous release's digests before the finish step or the swap uses any of it (review S2). This requires a
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
- The security-state export travels on the finish step's stdin, which reads at most 256 MiB: an instance whose carried
  tables (users, tokens, devices, the audit entries since the backup) exceed that fails the rollback's finish (exit 5,
  nothing swapped); not expected below hundreds of thousands of accounts.
- `fsa status` reports no restore scratch directory while any journal is open (an upgrade's, a rollback's, a reset's may
  own one), and counts both rollbacks as the last restore (review A7).
- The in-flight wait (review A1) relies on FrankenPHP's classic mode, in which the gated app holds no database session
  between requests. Worker mode, or a new long-lived database client (a websocket server, an exporter), would keep a
  session through the gate and make every upgrade and rollback exit 9 `requests_in_flight`; such a change must let the
  wait tell its sessions apart (by application name).
- The space check (review A5) sees only the file system that holds the instance directory. Docker's data root (the
  volumes, the scratch database) may be another file system, whose free space it does not measure.
- A version step changes data only, never the schema (VersionStep): the restore's schema audit builds its baseline from
  migrations alone (ADR 0085), so an object a step created would count as unknown and one it dropped as missing.
- J7 builds `2.0.0-rc.1-test` and targets `rc.2-test` (a migration), `rc.3-test` (unhealthy), `rc.4-test` (`fsa`
  crashes) derived from it (ReleaseHarness::publishDerived); `2.0.0-test` is no version ReleaseVersion accepts (its
  pre-release must start with alpha, beta or rc), so the happy target is a release candidate too.

## Review
The unit's own code review (H1, M1–M6) is folded into the decision above. A security and an adversarial review of the
finished unit followed (2026-10-20); the decision text above already states the outcome, this is the record:

| finding | what was wrong | now |
|---|---|---|
| A1 | requests past the gate could commit after the safety backup or the rollback's export | wait until no client session is left (≤ 120 s), then seal, back up, export; exit 9 `requests_in_flight` |
| A2 | a rollback killed in its cleanup raised the gate again on a live instance and could halt it (exit 8) | after `rollback_open` only the cleanup runs |
| A3 | step 7 overwrote the env files; a rollback rendered from the target's layout (lost or regenerated secrets) | release files snapshotted before step 7 and put back first; the previous image renders from its own layout |
| A4 | services only the target had kept running after a rollback | orphans removed when a rollback stops and opens and when an upgrade opens |
| A5 | with no backup yet the space check asked for 1 GiB | the larger of 5× the newest backup and 3× the live database and storage volume |
| A6 | one failed `docker inspect` removed a live child; a failed probe aged it | inspect retried; a failed probe is unknown |
| A7 | `fsa status` called an operation's scratch directory a leftover (`rm -rf`) | no scratch warning while a journal is open; rollbacks count as the last restore |
| A8 | manual rollback: drain timeout exit 5, exit 8 without backup and digests, a failed revert halted the app | exit 9; exit 8 per R55; exit 4 with the upgraded app serving |
| A9 | the app was down (refused, 502) for the whole automatic restore | the previous app answers 503 behind the gate meanwhile |
| A10 | `return` was one phase: a kill repeated stop, flush and gate on a live instance; it rendered the upgraded release with whichever renderer ran | six journaled steps; files from the swap's snapshot, never rendered |
| A11 | "newest within the installed major" is not reachable without `--to` | kept: the verified feed has no release list, only `latest/download` and `download/v<version>`; a listing (the GitHub API) would be unsigned. Exit 7 names `--to` |
| A12 | the lint missed version steps, package migrations and backdated files | all three linted; exact allowlist |
| S1 | safety backups stayed plain after revert, abort, return or a kill before the journal named them; `safety-rollback-*` never went | sealed on every way back but exit 8 (noted name, or found by time); older rollback sets removed |
| S2 | step 7 copied every staged file with the child's modes; the guard allowed host binds, capabilities, host namespaces, unpinned images; the rollback's stage was not validated | allowlist, fsa's modes, pinned images, the stricter ComposeGuard; the rollback's stage validated before its finish step |
| S3 | the schema audit of an unauthenticated archive missed objects it lacked (ADR 0085) | missing objects fail it, against a baseline at the archive's migration set (ADR 0085 amendment) |

Also recorded, without code: the child's network is not internal; the dropped-jobs file is never deleted; the export
size against the 256 MiB stdin cap (Consequences).

## Verification review
A verification review of the fixes (2026-10-20) confirmed A1–A7, A9, A10, A12, S1–S3 and found:
- **V1** (from A8): a rollback that went back with exit 9 left its journal open (`fsa rollback` then resumed it as exit 5
  and other operations were refused). An abort whose cause changed nothing now closes the journal like an upgrade's
  revert.
- **V2**: `rollback_open` and `return_open` lower the gate last; killed in between, a resumed run verified the live
  instance again and a smoke failure halted it (exit 8). Once the open phase started, only the open phase runs again.
- **V3**: staged env files reached ComposeGuard empty (RUN_MIGRATIONS passed) and an external volume could name any
  volume. The guard now reads the staged env files, and an external volume must carry this instance's project prefix and
  UUID label.
- **V4**: the render's merged env directory (current secrets) went only in the render's `finally`; every way out removes
  it now. Found on the way: `fsa rollback` checked the rollback guard before resuming, so a rollback killed after its
  swap (the state names no previous release then) could not continue (exit 7); an interrupted rollback now continues
  without the question.
- **V5**: the lint matches `ConcurrentIndex` as a word (aliases, injection).
- The adopt backup (U19, ADR 0087), a keyless 1.0 archive trusted only as the set the adopt recorded, restores in place
  through the upgrade child with `--accept-unauthenticated` (ADR 0087 amendment).

## Amendment 2026-10-20: safety backups after the M12 gate
- **D1.** The `open` phase removes only older `safety-upgrade-*` sets that are not marked kept; `safety-rollback-*` sets
  are never removed by fsa, and a completed rollback no longer removes older ones (R57, plan line 34: "restore, reset and
  pre-rollback safety backups stay until the operator deletes them"; after a rollback that set can be the only copy of
  the upgraded days). The `open` row of the phase table and the rollback's "older `safety-rollback-*` sets … removed"
  no longer hold, nor review S1's "older rollback sets removed".
- **S4.** Step 5 records the SHA-256 of the verified safety backup's SHA256SUMS (`sums` in the journal's
  `safety_backup` phase, `safety_backup_sums` in the `upgraded` history entry). `fsa rollback` and the upgrade's
  automatic way back refuse another set under that name (exit 2 `safety_backup_changed` for `fsa rollback`; the
  automatic way back halts with exit 8), as adopt does for its backup; a history entry from before carries no digest
  and is checked as before.

## Amendment 2026-10-20: following an upgrade, rollback recovery (M12 gate, fix round B)
- **The result stays (O3).** `operations/supervisor.json` names the last supervisor's container and result file; the
  foreground no longer deletes the result, the next launch does (replaced). `fsa upgrade --follow` (alone; other flags are
  exit 2) attaches to a running supervisor (journal progress, then its envelope) or returns the last result; exit 2
  `nothing_to_follow` when none ran. `fsa status` shows `last_upgrade` (exit code, status, error codes, from, to,
  finished_at, result file). `supervisor_running` (exit 9) names `fsa upgrade --follow` and the container.
- **Resume (O3).** `--resume` with no open journal is exit 2 `nothing_to_resume` in the foreground and in the supervisor,
  never a new upgrade; a resumed run takes `to`, `major` and `drop_jobs` from its journal (UpgradeRequest::resuming).
- **Status (O4).** `operation.state` is `running` while a live holder of that operation holds the lock (or, for an
  upgrade, the supervisor runs), else `interrupted`; `supervisor: {running, container}`; `lock.stale` (null when Docker
  cannot tell). The upgrade hint's "supervisor" refers to that field.
- **Rollback (C2, O2, O8, D7, C3).** A rollback leaves webhooks paused (`webhooks_paused` in its state and runtime, data
  `webhooks: paused`, warning `webhooks_paused`), as a restore (ADR 0085 A15): in-flight runs of the safety backup could
  repeat deliveries. Its journal records the passphrase source; a resume lacking the identity or passphrase exits 2 before
  the lock. It runs under the interruption guard with checkpoints at phase boundaries until the gate; an interrupted run
  goes back like a failed one (exit 9 before the swap, as any rollback abort whose cause changed nothing; exit 5 after it).
  A resumed run that finds writers running takes the ledger and the pre-rollback safety backup again. The cleanup seals
  plaintext `safety-rollback-*` sets made since the journal began, too. An upgrade killed after a resumed kill reverts or
  rolls back as before; writers do not matter there (no forward resume past the quiesce), so D7 does not apply to it.
