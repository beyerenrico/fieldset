# 0084: Backups: the backup service, the format, encryption, retention and the run record
- Status: accepted
- Date: 2026-10-19
- Refines: ADR 0059 (scheduled encrypted backups), ADR 0066 (lock staleness), ADR 0069 (backup env, renderer), ADR
  0073 (uninstall's final backup), ADR 0077/0078 (`config set` keys), ADR 0038 (audit chain heads, now with an epoch)

## Context
Plan unit U16 (PLAN M12-T01): BUILD_PROMPT_V2 E13, §6.3, §7 (`fsa backup`, `config set backup-*`), R38–R40, R57, R67,
R68 (heads), KTD4, KTD15–KTD17, KTD25, KTD26. Restore, drill, `--from-backup`, upgrade, rollback, adopt and reset (U17–U19,
U23) build on what this ADR fixes: the format, the HMAC keying, the safety-backup entry point. Facts checked on
2026-10-19 by running them: the app image's age 1.3.2 decrypts a passphrase-protected file with `-j batchpass` and reads
the passphrase from the descriptor `AGE_PASSPHRASE_FD` names, stripping trailing newlines (`age-plugin-batchpass --help`);
`age --identity -` reads the identity from stdin; the image's `pg_dump` is PGDG 16; Postgres 16 accepts a pre-computed
SCRAM-SHA-256 verifier in `CREATE/ALTER ROLE … PASSWORD` (checked with the RFC 7677 vector in `BackupHealthTest` and by
the backup role logging in in J08).

## Decision
**The backup service (KTD15).** Always rendered (`Compose\Services\BackupService`), the app image as `fsa backup-daemon`
(an image role, hidden from `fsa`'s command list): container root with only CHOWN, DAC_OVERRIDE, DAC_READ_SEARCH and
FOWNER, `no-new-privileges`, a read-only root file system (tmpfs `/tmp`), no Docker socket, the default network only.
Mounts: the storage volume read-only; of the instance directory only the marker (read-only), `locks/` and `backups/`,
under `/fsa/instance`. `ComposeRenderer::write()` creates `backups/` and `locks/` (0700, owner) before Compose could
create them as root, and writes `backups/.instance.json` (0600, no secret: the instance's identity and portable
settings), which the service copies into every backup; keeping it out of the env file means a URL or mail change does
not recreate the service. The env file (`ServiceEnvironments::backup()`) holds the backup role's credentials,
`BACKUP_HMAC_KEY`, `BACKUP_APP_FINGERPRINTS`, `BACKUP_AGE_RECIPIENTS`, `BACKUP_SCHEDULE`, `BACKUP_TIME_ZONE`,
`BACKUP_RETENTION` and the in-container owner UID:GID (KTD2: the host user on rootful Docker, 0 elsewhere): a change of
any of them recreates it. Never APP_KEY. Memory slot: 128 MiB on `small` (ADR 0083), measured below. The stack file
(R18) leaves the service out: it needs fsa's instance directory and role.

**Format (KTD16, R38).** `backups/<kind>-<UTC yyyymmddThhmmssZ>/`, kinds `scheduled`, `manual` and
`safety-upgrade|restore|reset|rollback|adopt` (uninstall's `final-…`, ADR 0073, is its own format). Written as
`<name>.partial`, renamed once complete; a name taken within the same second moves on by a second. The plain set:

| file | content |
|---|---|
| `database.dump` | `pg_dump --format=custom --no-owner --no-privileges --snapshot=<id>` as the backup role over the stack network |
| `audit-heads.json` | `{epoch, heads: {chain: {seq, hash}}}` read in the same snapshot (ChainHeads, ChainEpoch) |
| `storage.tar.gz` | `tar -C /var/www/html/storage/app --numeric-owner -czf`; tar's exit 1 ("file changed") is a warning |
| `settings.json` | `.instance.json`: instance identity, mode, URL, recipients and the portable settings (PortableSettings) |
| `secrets.env` | only with `--include-secrets`, only encrypted: APP_KEY, APP_PREVIOUS_KEYS, MAIL_USERNAME, MAIL_PASSWORD; written to the service's tmpfs (`/tmp`), only linked into the partial set and archived through the link (`tar -h`), so it is never plaintext in `backups/`, not even when a run is killed |
| `manifest.json` | format `fsa-backup` 1.0, kind, times, instance, versions (app, Postgres server, pg_dump), the migration set (names, count, last, SHA-256), audit epoch, referenced and missing files (count and the first 100), APP key fingerprints, HMAC keying, `encrypted`, `recipients`, `includes_secrets`, size and SHA-256 of every other file |
| `SHA256SUMS` | sha256sum format over all of the above, sorted (`sha256sum -c` works by hand) |
| `SHA256SUMS.hmac` | hex HMAC-SHA256 of the bytes of `SHA256SUMS` |

Every file 0600, the directory 0700, all owned by the in-container owner (the service chowns as root;
`SafeFiles` hands every file it creates to that owner before it becomes visible, lock files included). An encrypted
backup is one file, `backup.tar.age`: the plain set as a tar piped straight into age (file names after `--`),
encrypted to every recipient; the plain files are removed. A file `keep` inside a backup marks it kept.

**Order and verification (KTD16).** One repeatable-read, read-only transaction exports its snapshot
(`pg_export_snapshot()`); in it the audit heads, the file references (`private/` + path of local attachments,
renditions and document links, as the 1.0 restore drill) and the migration set are read; `pg_dump --snapshot` dumps
exactly that state, and the transaction ends after the dump. Then storage, then `pg_restore --list` must list the dump's
table data, then settings, manifest, and last `SHA256SUMS` and the HMAC under temporary names, renamed at the end.
Referenced files missing from storage are recorded in the manifest (a warning, not a failure: the database may point at
files deleted earlier). All programs of a backup share one six-hour deadline (`BackupTools::DEADLINE_SECONDS`; they write
files, so no idle timeout fits): each gets what is left, and the one-off around a manual or safety backup gets ten
minutes more (`BackupRunner::TIMEOUT`), so a large backup never fails on the outer limit first. A failed step removes
the partial directory: reasons `low_disk_space`, `snapshot_failed`, `pg_dump_failed`, `storage_failed`, `verify_failed`,
`encrypt_failed`, `write_failed`, `timeout`.

**HMAC keying (R67).** `BACKUP_HMAC_KEY` = `base64:` + HKDF-SHA256(raw APP_KEY, 32 bytes, info `form-schema-app backup
hmac v1`), rendered by fsa; the service never sees APP_KEY. A restore recomputes it from the instance's APP keys (or a
key file) outside the archive. `KeyFingerprint` (HKDF with info `form-schema-app app key fingerprint v1`, 64 bits,
`xxxx-xxxx-xxxx-xxxx`) names the key without revealing it; `fsa backup` prints it. Its env key avoids the words the
output redactor treats as secret (`KEY`, `TOKEN` …, EnvFiles::secretValues), so the fingerprint is never redacted. Only the current key's fingerprint
is rendered (APP_PREVIOUS_KEYS are not in InstanceSecrets yet; U17 adds them when it reads them).

**Encryption (KTD17, R40).** `fsa config set backup-encryption on --identity-out <path> --passphrase-file <file>` (or
`--passphrase-stdin`: a hidden prompt, entered twice, in a terminal), also reachable as `fsa backup --encrypt`. The
preflight (ops container) generates an X25519 key pair (`age-keygen`), refuses an identity path inside the instance
directory (by path, real path, and by device and inode of each ancestor of the identity's directory: the ops container
mounts the instance at the host's working directory, which may go through a symlink, and the identity's directory at
its real path, so one directory can have two unrelated paths there), a relative one or an existing file (exit 2: an
identity is never overwritten), writes the identity encrypted
with the passphrase (`age -e -j batchpass`, the passphrase on file descriptor 3 only: never an argument, an environment
variable or the journal; at least 12 characters; age, its plugin and tar get only PATH, HOME and TMPDIR from the
environment), mode 0600, and decrypt-tests it (a random challenge encrypted to the
new recipient must come back through the identity file and the passphrase; a failure removes the file, exit 3). The
target state records only the recipient; apply recreates the backup service with it. `on` when already on changes
nothing; `off` removes the recipient (existing encrypted backups stay). A change reverted after the preflight leaves the
identity file (it decrypts nothing). Rotation (a new recipient, older public keys kept listed for old backups; audit
`backup_key_rotated`) is not built yet.

**Safety backups (R57).** `Backup\SafetyBackup::take($state, $operation, $lock)` runs the service's entry point as a
one-off under the caller's lock: unencrypted even when the instance has recipients (a rollback restores it without the
owner's identity; `BackupWriter::encrypts()`), verified, 0600, never pruned; a failure is exit 9 `safety_backup_failed`
(nothing changed yet). `seal()` encrypts it in place once the operation completed, when there are recipients
(`fsa backup-daemon --seal`). Its manifest says `encrypted: false`: it describes the set inside the age file.

**Manual backups and the one-off.** `fsa backup` takes the instance lock (as any operation, stale locks taken over),
starts Postgres if it is stopped, sets up the backup role (`instance:backup-role` in a socket-less one-off of the app
service, the password by name in the environment), then runs `fsa backup-daemon --once --kind=manual --lock-id=<id>` as
a one-off of the backup service, which refuses unless that lock ID holds the lock; `--include-secrets` passes
`secrets.env` on the one-off's stdin. Exit codes: 0; 2 (`--include-secrets` without encryption, flags); 3
(`low_disk_space`, `backup_service_missing`); 4 (a failed step, retryable; `backup_role_failed`); 9 (lock held).

**The backup role (KTD7).** `fsa_backup`, created by `App\Domain\Platform\Instance\BackupRole` after install's
migrations and before every manual backup: LOGIN, no superuser/createdb/createrole/replication, member of
`pg_read_all_data`, CONNECT, and INSERT on `backup_runs` only. For an existing role, `ensure()` first takes back what it
may have gained by hand (other role memberships, rights on the tables, sequences and schema `public`, per-role
settings). The password reaches Postgres only as a SCRAM-SHA-256 verifier computed in PHP, because Postgres logs a
failing statement's text; a failure is reported without the statement.

**Run record (R39).** Every run (succeeded, failed, skipped) goes into the `backup_runs` table (uuid default
`gen_random_uuid()`, so the role needs no sequence rights; kind, status, backup, started/finished, bytes, encrypted,
reason, message, free bytes, missing files, schedule, time zone) and into the append-only ledger `backups/runs.log` (one
JSON line without the result details; also the runs the table could not take, `recorded: false`; the newest MiB is
read, a damaged line is skipped). The service records its own start there too (kind `service`, status `started`, with
the schedule and time zone it runs), and a start that fails on its configuration (status `failed`, the reason; the
same failure again after Docker's restart adds nothing). `fsa status` reads the ledger (no database needed):
`last_backup` (the newest success) and `backup` (last run, schedule and next run, encryption, count and size, safety
backups with age and size, `.partial` directories left over, alerts). `/platform` reads the table:
`App\Domain\Platform\Instance\BackupRuns` (the service's start records apart from the runs, so hourly skips never push
them out) and the health page's `BackupCheck` (the instance page of U20 builds on it), which says truthfully what the
last run was (a success, a skip being retried, none yet). One rule set, `BackupAlerts`:

- `backup_failed`: the newest run that was not skipped failed, until a later success;
- `backup_disk_space`: the newest scheduled run since the last success was skipped for lack of disk space, at once;
- `backup_skipped`: a scheduled skip because an operation held the lock, older than 24 hours, without a later success;
- `backup_service_failed`: the service's newest start failed;
- `backup_overdue` (schedule on): two hours after the first slot following the last success, or, without one,
  following the service's first start with the schedule on (`scheduledSince`: the oldest start since it last started
  with the schedule off; restarts do not move it, switching the schedule on does; `fsa status` falls back to the
  install when the service never reported), no run succeeded. An upgraded or fresh instance gets its first slot first,
  as the daemon waits for it; a service that is not running, or never completes a backup, turns overdue on both
  `fsa status` and `/platform` (fail). A `.partial` directory outside a lock warns in `fsa status`
  (`backup_partial_left`: it may hold unencrypted data); an fsa instance whose service never reported warns on
  `/platform`.

**Schedule and lock (KTD4, KTD15).** Daily at `backup_schedule` (default 03:30, or `off`) in `time_zone`, which install
records from the host (FSA_TZ) and `config set backup-schedule` records when it is missing; UTC without one. One slot
per calendar day of the zone (`BackupSlot`, parsed from the local date and time): when the clock falls back, an
ambiguous time is its second occurrence (02:30 CET, not CEST: one run, where computing it from the current moment's
offset gave two); when it springs forward, a missing time is the moment after the gap. On start the daemon releases a
lock its own container took before a restart, removes leftover `.partial` and tombstone directories under the lock when
it is free, and records its start. It computes the next run from the ledger's last scheduled success, or without one
from its first start with this schedule: a slot missed while it was down runs at once, otherwise the next slot; after a skip because of the lock it retries every minute, after a failure or a skip for disk space every
hour. It also keeps its own last run in memory, so a ledger it cannot write never makes it run back to back. Each run takes
the instance lock with `mayTakeOver: false`; a held lock makes the run a skip, and of a series of such skips one an hour is
recorded (a lock a crashed operation left behind keeps showing up; the minute retries in between are not recorded). The service has
no socket and cannot learn Docker's start time, so its lock records `proc:<boot ID>:<init start ticks>` (ServiceIdentity;
the kernel's boot ID makes it unique across host reboots, where the ticks alone could repeat) instead:
fsa treats such a lock as stale only when Docker reports the container gone or stopped (InstanceLock::isStale), and the
daemon releases a lock its own container took before a restart (`releaseOwnStale`). It sleeps in steps of at most 15 s
and stops on SIGTERM, SIGINT or SIGHUP; a run cut off by the 30 s stop grace leaves a `.partial` directory, which the
next run removes under the lock.

**Retention (E13, R57).** After a successful scheduled run only: keep the newest `backup_retention` (default 7, 1–365)
scheduled backups; never a safety, manual, `final-…` or kept backup, never the newest backup of any kind. Before every
run: the free space after the projected size (the newest backup's size plus a quarter, or without any backup the
database's `pg_database_size` plus the storage volume's size plus a quarter; twice that when encrypting, which keeps
the plain set and its age file side by side for a moment) must stay above `BACKUP_MIN_FREE_MIB` (1024): a scheduled run
is skipped (`low_disk_space`, recorded and warned at once), a manual or safety run fails (exit 3). Pruning renames a
backup to a hidden tombstone (`.removing-<name>-<hex>`, never listed) before deleting it, and records only backups that
are really gone; the next run (or the daemon's start) removes a tombstone an interrupted prune left.

**`config set` keys (R32, ADR 0077/0078).** `backup-schedule <HH:MM|off>`, `backup-retention <1–365>` and
`backup-encryption on|off` in every mode, through the usual plan → preflight → apply → verify → workers → audit flow with
revert; they recreate only the backup service. `ConfigChange::unchanged()` now also compares the recipients and treats
the backup defaults like the default ports.

**Chain epoch (KTD26, R68; amends ADR 0038).** `audit_chain_epochs` (append-only by trigger; no row = epoch 1):
`ChainEpoch::current()` and `ChainEpoch::begin($reason, $seed, $previousHeads)` (restored, rolled_back, reset; one above
the current epoch and the ledger's seed). `audit:verify --heads` prints `{verified_at, epoch, heads}`. `ChainHeads` reads
the heads without walking the chains (newest sealed entry per chain, else its retention anchor); for intact chains they
equal ChainVerifier's.

**Memory (ADR 0083).** J08 measures the backup service on its 128 MiB `small` slot (native arm64, Docker Desktop, demo
data; `J08-backup-memory.json`): the container's `memory.peak` over its whole life (the daemon idle, then the scheduled,
encrypted run: PHP, pg_dump, tar/gzip, age) is 68 MiB; unreclaimable memory (anon + shmem, sampled every second) peaks at
59.6 MiB, about 59 MiB of it the idle daemon (PHP CLI with Laravel booted, no OPcache, like a Horizon process at rest in
ADR 0083). No limit hit (`memory.events` max 0), no OOM kill. 68 + 30 = 98 ≤ 128: the slot and the budget table stay as
they are, and J10 needs no new run for it. Not measured: the 1.0 test volume. pg_dump and tar stream; what grows with the
data is the set of file references (inferred: about 13 MiB per 100,000 paths), so a backup of the J10 data set would stay
well inside the slot; the gate's J10 run can record it.

## Alternatives considered
- **Backups from the ops container through `compose run` one-offs** (as uninstall's final backup): the snapshot must
  stay open in one session while pg_dump runs, so the whole writer runs in one socket-less container; the backup
  service's own entry point as a one-off gives manual and safety backups the same code, mounts and capabilities.
- **Settings in the backup env file:** every URL, mode or mail change would recreate the service; `.instance.json` is
  rewritten by every render instead.
- **Recording runs only in the table:** a failure while Postgres is down could not be recorded, and `fsa status` would
  need a database one-off; the ledger covers both, the table serves `/platform`.
- **A plaintext password in `CREATE ROLE`:** Postgres logs failing statements; the SCRAM verifier carries no reusable
  login secret.
- **Recording every skip:** a long operation would add a row a minute.

## Consequences
- Restore (U17) reads this format: decrypt first (identity and passphrase), then `BackupChecksums::verify()` with the
  HMAC key recomputed from the instance's or a supplied APP_KEY, then the manifest's migration set and fingerprints.
  Left for U17 (review 2026-10-19): the dump is taken with `--no-privileges`, so the `REVOKE … FROM PUBLIC` on the
  audit functions must be re-applied after a restore (by migrations or the restore itself); `verify()` must refuse
  file names in `SHA256SUMS` with `/` or `..` before it hashes them; stray files a user or the sealer's temporary file
  leaves (dotfiles) must not fail verification.
- Upgrade, restore, reset, rollback and adopt (U17–U19, U23) call `SafetyBackup::take()` under their lock and `seal()`
  once they completed, and `ChainEpoch::begin()` in the restored or new database.
- Operator notifications for failed backups (R39 "notify operators") are not built: `fsa status` warns and the
  `/platform` health page shows the failure; notifications belong with the instance page (U20).
- `fsa install --encrypt` is still refused (`unsupported_option`); `fsa backup --encrypt` or `fsa config set
  backup-encryption on` after the install do the same.
- Backups stay on the host; `fsa backup` says so (`backup_on_host`).

## Amendment 2026-10-19: what restore changed (U17, ADR 0085)
- `BACKUP_APP_FINGERPRINTS` lists the fingerprint of every APP key (APP_KEY first, then APP_PREVIOUS_KEYS, which
  InstanceSecrets now reads and renders); the HMAC key still derives from APP_KEY alone, so a manifest's first
  fingerprint names the key that verifies it.
- `BackupChecksums::verify()` refuses a `SHA256SUMS` line that is not `<sha256> [ *]<name>` with a plain file name
  (no `/`, no `..`, no leading dot, no control character) before anything is hashed, and ignores dotfiles beside the set
  (`.DS_Store`, a killed seal's temporary file); `strays()` names them and restore reports them as a warning.
  `verifySums()` checks the sums alone (the 1.0 format has no HMAC).
- The dump keeps `--no-privileges`; a restore re-applies the `REVOKE ALL … FROM PUBLIC` on `audit_seal`, `audit_prune`
  and `audit_prunable` and sets up the backup role again in the restored database (`instance:restore-finish`).
- `pg_restore --list` and the new `pg_restore --data-only --table=migrations` get `--` before the file.

## Amendment 2026-10-20: pre-upgrade safety backups (U18, ADR 0086)
After a committed upgrade, `fsa upgrade` removes every older `safety-upgrade-*` backup (R57: they replace each other only
after a commit; those of failed upgrades stay until then) and every `safety-rollback-*` one, and seals its own when
encryption is on. A completed `fsa rollback` keeps only its own `safety-rollback-*`. Restore and reset safety backups are
never removed. Every way an upgrade or a rollback goes back (revert, abort, return) seals the safety backups it took,
also one a killed run made before the journal named it (review S1); only an exit-8 halt keeps them plain. Before any
migration the upgrade verifies its safety backup with the restore's prepare step (checksums, HMAC, `pg_restore --list`,
the migration set); a backup that fails is exit 9.

## Amendment 2026-10-20: the `safety-adopt` backup (U19)
`fsa adopt` (ADR 0087) writes its `safety-adopt-<UTC>` backup in the 1.0 format of `scripts/backup.sh`, not in the set
above: `database.dump`, `storage.tar.gz`, `env` (the restore allow-list's values), `manifest.txt` and `SHA256SUMS`,
no HMAC, taken from the stopped 1.0 install before fsa copies its volumes. It is a safety backup (never pruned, 0700/0600)
and restores like any 1.0 archive (`--accept-unauthenticated`). Its `env` holds no APP key: the manifest names the keys
by fingerprint, and an in-place restore takes exactly this set by its name and SHA256SUMS digest (U19 reviews, ADR 0087).

## Amendment 2026-10-20: what reset changed (U23, ADR 0088)
- `fsa reset` takes `SafetyBackup::take($state, 'reset', $lock)` after the quiesce and the ledger line, and seals it on
  every way it closes. With `--no-backup` while a real organization exists it writes
  `backups/audit-export-reset-<UTC>.jsonl` (0600) instead: no backup set, so BackupStore never lists it and retention
  never deletes it.
- `ChainEpoch::begin('reset', …)` now has its caller (`instance:reset epoch`); the reset's ledger line adds `journal`,
  `demo` and `no_backup`.
- `fsa backup` refuses while any operation journal is open (exit 9 `operation_unfinished`, checked under the lock; U23
  second review A8): a half-reset or half-restored database is no state worth a backup. The backup daemon cannot read
  the journal; operations stop the backup service and keep it stopped until their `open` phase (ADR 0088).

## Amendment 2026-10-20: backups and whoever else writes into backups/ (M12 gate)
The gate review assumed `backups/` is synced off the host (a two-way sync, an rsync target that pushes back): someone
who can write there but holds no APP key. Against that writer:

- **The set being written (S1).** `<name>.partial` stays root's (0700) until `complete()`; only then does it go to the
  owner and get its final name. `pg_dump` and `tar` write to their stdout, a file the writer created itself
  (exclusively, 0600 from the umask) and keeps open (`ToolProcess`); the hash is read back through that descriptor
  (`ProducedFile`), and a regular file as stdout keeps the custom dump seekable (checked: byte-identical to `--file`
  apart from its time stamp, unlike a pipe). `PartialSet` records every file with its hash, size, device and inode;
  before `SHA256SUMS` is written the set must hold exactly those files, unchanged (hashed again through a fresh
  descriptor), in the directory the run created (same device and inode, still the process's, 0700). Anything else
  fails the run with the new reason `set_changed`. `SHA256SUMS` lists the recorded hashes, never what a path holds at
  the end. Such a writer can make a run fail; it cannot get a file of its own authenticated. On rootless Docker and
  Docker Desktop the owner is container root, so the root-owned partial protects nothing there; the owner is the trust
  boundary on those hosts anyway (it holds `env/` with APP_KEY).
- **Settings (S2).** The service reads its settings from `staging/backup/instance.json`, no longer from
  `backups/.instance.json` (which a backups/ writer could plant: recipients, domain, ACME CA, extra hosts, all signed
  into every backup). `staging/` is a new top-level directory of the instance (0700, never to be synced): the service
  mounts only `staging/backup/` (read-write: also the plain sets below), the render and the upgrade's release files
  write the file there, and a pre-release copy in `backups/` is deleted by the next render. `--from-backup` keeps
  recipients only with `--keep-recipients` (ADR 0085 amendment).
- **No plaintext in backups/ (S3).** An encrypted run writes its plain set in `staging/backup/<name>.partial` and only
  `backup.tar.age` into `backups/<name>.partial`; leftovers in either place are cleaned under the lock and reported by
  `fsa status` (`backup_partial_left` names them with their path). Restore scratch directories moved to `staging/` too
  (ADR 0085 amendment). Safety backups stay plain in `backups/` until sealed, as before: a rollback must read them
  without an identity, and moving them would need a second copy of every safety backup.
- **Durability (D6).** Every produced file is fsynced before it is hashed; the directory is synced before and after
  the rename to complete; the sealer and the age output are synced before plaintext is unlinked; SHA256SUMS' temporary
  files are synced before their rename.
- **Missing files (D5).** A success whose `missing_files` > 0 raises `backup_files_missing` (BackupAlerts: a warning in
  `fsa status`, `warn` on `/platform`), and a scheduled run that misses more referenced files than the newest earlier
  success still on disk prunes nothing (`retention_held` in its ledger line): a storage volume that lost files never
  costs the backups that still hold them. References now include the organizations' branding logos (D8).
- **Superseded safety backups (D1, amends the U18 amendment above).** After a committed upgrade only older
  `safety-upgrade-*` sets go, never one marked kept; `safety-rollback-*` sets are never removed by fsa (R57, plan line
  34: after a rollback that set can be the only copy of the upgraded days), and a completed rollback no longer removes
  older ones.
- **Final backups.** Uninstall's final backup is a backup of this format (kind `final`, ADR 0073 amendment); the format
  table's note that it is "its own format" no longer holds.
- **Reset's audit export (S5).** Written in `staging/`, encrypted to the recipients (`….jsonl.age`) when encryption is
  on, then moved into `backups/` (ADR 0088 amendment).
