# 0087: `fsa adopt`: a 1.0 install becomes an instance by copying its data
- Status: accepted
- Date: 2026-10-20
- Refines: ADR 0084 (safety backups, the `safety-adopt` kind), ADR 0085 (the 1.0 reader and `.env` allow-list, whose
  backups an in-place restore takes), ADR 0066 (exit codes, journal, gateway), ADR 0070 (launchers), ADR 0072/0073
  (uninstall and its final backup), ADR 0080 (capture mail)

## Context
Plan unit U19 (PLAN M12-T04): BUILD_PROMPT_V2 §7 (`fsa adopt <path>`, exit codes), R46 as changed by plan R60 ("adopt copies
the 1.0 volumes into new labelled volumes and leaves 1.0 restartable"), F7, J9, KTD20 (order, Postgres on Alpine, forced
values, the way back) and KTD26. The 1.0 install is the tag `v1.0.0`: `compose.yaml` (project `form-schema-app`, services
app/queue/scheduler/postgres/redis/gotenberg, volumes `pg-data` and `app-storage`, the app published on
`127.0.0.1:${APP_PORT}`, `DB_HOST: postgres` set by Compose), the production template `.env.production.example`
(`COMPOSE_FILE=compose.yaml`, `APP_URL`, `APP_PORT`, `DB_*`, `MAIL_*`, `SSO_*`, `TRUSTED_PROXIES`) and `scripts/backup.sh`.
Facts this relies on, checked 2026-10-19: the official Postgres image's `pg_hba.conf` trusts `127.0.0.1/32` (initdb's
default for local connections; the dev stack's `postgres:16-alpine` prints it), so `psql`/`pg_dump` in a one-off that
shares the Postgres container's network namespace (`--network container:<id>`) need no password; `CREATE EXTENSION` is
transactional, so amcheck can run inside a transaction that is rolled back (checked on a scratch database: the extension
is gone afterwards); `bt_index_check(index, true)` reports through `RAISE NOTICE` per index in a `DO` block with an
exception handler; Compose labels every container with `com.docker.compose.project.working_dir` and `…config_files`
(`docker ps` joins labels with commas, `docker inspect` returns them exactly); Compose's project name precedence is `-p`,
then `COMPOSE_PROJECT_NAME` (also from `.env`), then `name:`.

## Decision
**Command and launch.** `fsa adopt <checkout>` (`fsa:adopt`, a visible command, in the image wrapper's list) asks first
(exit 6 without `--yes` and a terminal) and runs in a new instance directory. install.sh takes `--adopt <checkout>` and
hands over `fsa adopt <checkout>` instead of `fsa install` (it creates the directory, the marker, the verified release and
the shim as for an install); the shim mounts the first argument of `adopt` read-only at its own physical path (a
resumed `fsa adopt` through the shim), and the command refuses a checkout no launcher mounted (exit 2
`path_not_mounted`). The image wrapper maps `--version` to `--release` for `adopt` as for `install` (Symfony answers a
literal `--version` itself). Flags: `--release`, `--drop-jobs`, `--abandon`.

**Reading 1.0 (LegacyCheckout, LegacyInstall).** Of the checkout only `compose.yaml` (`name:`, the `DB_HOST:` lines),
`package.json` (`version`) and `.env` are read, and of `.env` only the allow-list `LegacyEnv::ADOPT`: U17's `ALLOWED`
(APP_KEY, APP_PREVIOUS_KEYS, APP_URL, MAIL_FROM_ADDRESS, MAIL_FROM_NAME, MAIL_HOST, MAIL_PORT) plus APP_PORT, COMPOSE_FILE,
COMPOSE_PROJECT_NAME, DB_DATABASE and DB_USERNAME; never DB_PASSWORD, SMTP credentials, APP_DEBUG, SSO_* or TRUSTED_PROXIES.
Docker is the truth about the running install: the containers of the Compose project (one-offs left out) and the volumes
labelled `pg-data`/`app-storage`. Exit 7 with the reason and a hint, before anything changes:
`compose_file_override` (COMPOSE_FILE other than `compose.yaml`; an override file Compose would merge without
COMPOSE_FILE; containers started with more Compose files than the checkout's compose.yaml), `external_database` (a
`DB_HOST:` other than `postgres` in compose.yaml, no bundled Postgres container or no `pg-data` volume),
`unsupported_version` (not 1.x), `legacy_not_found` (no container of the project), `checkout_mismatch` (started from
another directory), `already_instance` (instance labels), `app_key_missing`, `unsupported_database_name` and
`unsupported_url` (an APP_URL with a path). A path that is no checkout or cannot be read is exit 2.

**Mode and settings (AdoptSettings).** 1.0 publishes its app only on `127.0.0.1:APP_PORT`, so an APP_URL on another host
than the loopback means a reverse proxy on the host: proxy mode `--behind-proxy port` with that URL and the same port
(the proxy keeps working; the default network's gateway becomes the one trusted proxy, ADR 0076); a loopback URL is
local mode on APP_PORT. While 1.0's app holds the port the doctor cannot probe it: the preflight chooses its ports as for
an install and the app port is then set to APP_PORT; after 1.0 stopped, a probe must find it free (exit 4
`port_taken`, which goes back). APP_KEY and APP_PREVIOUS_KEYS stay (`FromBackupKeys::ensureKeys`, the extracted half of
U17's key step), so encrypted data and sessions stay valid; the database and its user keep their 1.0 names
(`db_database`, `db_username`), the sender is kept when the 2.0 grammar takes it, mail starts captured and 1.0's SMTP
server is named with the `fsa config set mail smtp` command (`pending_mail`, warning `mail_captured_after_adopt`).
APP_DEBUG, SSO_ALLOW_INSECURE_IDP and SSO_DNS_DRIVER are what fsa always renders (`false`, `false`, `dns`) and
TRUSTED_PROXIES follows the mode.

**Order (KTD20) and journal (R54).** Journaled operation `adopt` under the instance lock, each phase written before it
starts: `doctor` (release verified, 1.0 found, install's preflight, the space check) → `secrets` → `state` → `pull`
(every image before 1.0 is touched) → `stop_1_0` → `backup_1_0` → `stop_postgres_1_0` → `copy` → `verify` → `up`
(Postgres, Valkey; proxy mode's gateway) → `migrate` (and the backup role) → `start` → `smoke` → `done`.

- *Space.* `du -s -B1` of both 1.0 volumes (a socket-less, network-less one-off of the app image, volumes read-only),
  twice (backup and copy) plus 1 GiB, on the instance directory's file system (the restore check's estimate and test seam,
  ADR 0085); exit 3 `low_disk_space` with 1.0 running. Unknown sizes or free space pass.
- *Stop and drain.* 1.0's containers are stopped and started by ID (`docker container stop/start`, two new gateway
  methods), never through Compose, which would recreate them from a file fsa cannot render as 1.0 did (a new
  `runContainerToFile` streams one-off output into a file). App and scheduler stop first (100 s grace); Horizon then
  works while a one-off of the manifest's Valkey image on 1.0's default network counts the queue keys with one Lua script
  (any Redis prefix: waiting lists without Laravel's `:notify` lists, `:reserved` and `:delayed` sorted sets), every 3 s for
  at most 120 s; drained means nothing waits and nothing runs. Jobs left mean exit 4 `drain_timeout` (which goes back)
  unless `--drop-jobs` (warning `jobs_left_in_1_0`: they run if 1.0 starts again); delayed jobs never drain and are
  reported (`delayed_jobs_left_in_1_0`); a Valkey that cannot be asked (1.0's compose.yaml sets no password; a custom
  network) means nothing is known about the jobs and is treated like jobs left: exit 4 `drain_unknown` (which goes back)
  unless `--drop-jobs` (then a warning of the same code). Then the queue stops with its 100 s grace.
- *Backup in the 1.0 format (LegacyBackupWriter).* `backups/safety-adopt-<UTC>/` (ADR 0084's reserved kind: a safety
  backup, never pruned, 0700/0600), written as `.partial` and renamed: `database.dump` (`pg_dump --format=custom
  --no-owner --no-privileges` in a one-off of the manifest's Postgres image in 1.0's Postgres container's network
  namespace, streamed to the file; `pg_restore --list` must read it), `storage.tar.gz` (GNU tar of the app image over
  1.0's storage volume mounted read-only, numeric owners, `./` members as 1.0 writes them; tar's exit 1 is a warning),
  `env` with only the restore allow-list's values (no DB_PASSWORD, no SMTP credentials), `manifest.txt` (created_at,
  postgres, migrations, last_migration, the snapshot's counts, storage, env, source `fsa adopt`, version, project) and
  `SHA256SUMS`. fsa writes the format itself rather than running the checkout's `scripts/backup.sh`: that would execute
  checkout code in the container that holds the Docker socket, and it needs 1.0's `docker compose exec` and `.env`.
- *Snapshot (DataSnapshot, DatabaseProbe).* Right after the dump (writers stopped, so both see one state): the counts of
  organizations, users, forms, form_versions, submissions, attachments, renditions, mail_messages and activity_log, the
  head (sealed entry with the highest sequence) of every audit chain as ChainHeads reads it, and the migration set's size
  and last name, as one JSON line from `psql` (the SQL on stdin, never in argv).
- *Clean stop.* 1.0's Postgres stops with a 120 s grace (the image's SIGINT: fast shutdown with checkpoint), then Valkey
  and Gotenberg.
- *Copy (VolumeCopy, R60).* Compose creates the instance's `<project>_pg-data` and `<project>_app-storage` volumes with
  its own and the instance's labels (a `true` one-off of `postgres` and of the app service), then a one-off of the
  manifest's `postgres:16-alpine` (same musl family as 1.0's, so text indexes stay valid, KTD20) copies each 1.0 volume,
  mounted read-only, with `cp -a`, without a network. The copy must report `Database cluster state: shut down`
  (`pg_controldata`), else exit 4 `postgres_not_shut_down`. A resumed copy removes the half-filled volumes first.
  1.0's volumes are never mounted writable (a test checks every mount).
- *Verify.* The instance's Postgres starts alone on the copied cluster; the 1.0 role gets the instance's database
  password as a SCRAM-SHA-256 verifier (`BackupRole::scramVerifier`, on stdin); the copy's snapshot must equal the
  backup's exactly (`copy_mismatch` names the differences), and amcheck (`bt_index_check` with heapallindexed) runs on
  every valid unique B-tree index in `public` with a collatable key column or an expression (emails, slugs) inside a
  transaction that is rolled back, so the database keeps no extension the restore check would refuse (`amcheck_failed`).
- *Smoke.* Install's smoke test (HTTP, a PDF, a test mail). Adopt has no owner e-mail, so the mail goes to the active
  operator; a 1.0 database without one (1.0 granted operators by hand with `app:platform-admin`) skips the mail with the
  warning `test_mail_skipped` and the hint `fsa config set operator-email <email>` (ADR 0072 amendment).
- *Done.* After the smoke test the instance's snapshot is the baseline: `backups/ledger.log` gets the `adopted` line
  (host UID, checkout, project, backup, epoch 1, the backup's heads, the baseline and `baseline_source`: `instance`, or
  `copy` when the instance could not be read and the verified copy stands in); the state gets `installed` (so a
  repeated install changes nothing) and `adopted` (checkout, project, version, backup). Adopt continues the 1.0 audit
  chains: it starts no epoch and writes no audit entry, so heads recorded under 1.0 stay verifiable (KTD26 concerns
  operations that put a database back).

**The way back before 2.0 starts (AdoptRollback).** Any failure from `secrets` to `migrate` goes back: the journal notes
`rollback`; every container, network and volume labelled with the new instance's UUID is removed first (2.0 never served,
so nothing of it is lost, and none of its containers holds a port 1.0 needs); then 1.0 starts again exactly as it ran (the
services that ran when adopt began, their containers looked up again by the project's labels, so one recreated meanwhile
is found; Postgres first and healthy before the app roles, which migrate on start) and fsa checks that they run
(`not_running`, warning `legacy_not_running`); then the generated files (state, compose file, env and runtime
directories) are deleted; the 1.0-format backup, the release and the journal archive stay. Exit 5 `rolled_back` with the
failing step's code (exit 9 when 1.0 had not been stopped yet: `markReverted`, nothing changed); leftovers that could not
be removed are named (`adopt_leftovers`). When 1.0 does not start again it is exit 8 `rollback_failed` with
`cd <checkout> && docker compose up -d`; the journal stays open and the next `fsa adopt` (or `fsa adopt --abandon`)
finishes the way back instead of moving forward.

**From `start` on** the instance runs on its own copy and may take writes: a failure leaves the journal open (exit 4) and
the same command resumes forward (nothing is copied again); `--abandon` is refused (exit 2 `adopt_started`), the way back
is `fsa uninstall --keep-backups` and `docker compose up -d` in the checkout. A finished adopt of the same checkout changes
nothing, another checkout is exit 2 `already_installed`.

**Uninstall of an adopted instance (ADR 0073 amended).** `fsa uninstall` writes the final backup and keeps `backups/`
without `--keep-backups`, unless the new `--no-backup`; the result's `adopted` block names the checkout, the writes since
adopt (`audit_entries`: how far every chain moved on since the baseline; `rows`: the change of every counted table; read
from the running Postgres the final backup started) and `restart_1_0` (`cd <checkout> && docker compose up -d`), with the
warnings `restart_1_0` and, when anything was written, `writes_since_adopt` (`writes_since_adopt_unknown` when they
cannot be counted). With `--no-backup` and without `--keep-backups`, `backups/` goes too, the adopt backup included; with
both, `backups/` stays without a final backup. 1.0's volumes were never the instance's, so
they stay, and `docker compose up -d` in the checkout brings 1.0 back with its pre-adopt data.

**Restoring the adopt backup (ADR 0085).** The backup's `env` carries the instance's own APP_KEY, so an in-place restore
counts it as this instance's (`RestoreChecks::assertSameInstance`). It needs `--accept-unauthenticated` (the 1.0 format
has no HMAC), and while it is older than the migrated instance an in-place restore is exit 7 `upgrade_child_unavailable`
until U18's upgrade child exists; `fsa restore --drill` and `fsa install --from-backup` take it today.

## Alternatives considered
- **Taking over the 1.0 volumes in place (origin R46):** no way back to 1.0 once 2.0's migrations ran on them; R60 replaced
  it with the copy.
- **Restoring the 1.0 backup with `pg_restore` into a fresh cluster (`--from-backup`):** works across libc families, but a
  dump/restore of a large database is slower than a file copy and loses nothing the copy keeps; the copy is verified
  against the backup, and `--from-backup` stays the route for a database that fails amcheck or an external one.
- **Reading DB_PASSWORD to connect:** the loopback trust of the official image reaches the database without any 1.0 secret.
- **Compose against the checkout's file (`docker compose -p … stop/up`):** `up` recreates 1.0's containers when fsa's
  environment interpolates `compose.yaml` differently (COMPOSE_DISABLE_ENV_FILE, defaults), so containers are stopped and
  started by ID.

## Consequences
- Docker's data root may be another file system than the instance directory; the space check then underestimates.
- Jobs waiting in 1.0's Valkey after `--drop-jobs`, and delayed ones, are not carried over: stalled renditions and workflow
  steps are picked up by the scheduler; mails may have to be sent again (the 1.0 restore guide says the same).
- Unsealed audit entries are copied unsealed and sealed by 2.0's scheduler; the chains continue.
- A 1.0 install started with a Compose override, with an external database or with renamed database objects outside the
  identifier grammar is refused; its operator moves the data with a 1.0 backup and `fsa install --from-backup`.
- The adopted instance's Postgres superuser is still the 1.0 role (as for every instance, ADR 0085).
- amcheck covers the unique text indexes (the ones whose corruption breaks uniqueness); non-unique text indexes rely on
  both clusters being Alpine/musl (KTD20), which the copy keeps by using the same image family.
- A one-off that times out (the 6-hour `cp`, `pg_dump` or `tar`) is not killed by the Docker CLI's exit; it carries the
  instance label, so the way back or `fsa uninstall` removes it.
- The checkout is mounted read-only as a whole into the ops container (the shim mounts directories); fsa reads only
  compose.yaml, package.json and the allow-listed lines of `.env` (no symlinks followed).
