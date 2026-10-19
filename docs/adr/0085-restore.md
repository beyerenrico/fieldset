# 0085: Restore, drill and `fsa install --from-backup`
- Status: accepted
- Date: 2026-10-19
- Refines: ADR 0084 (backups: format, HMAC, fingerprints, safety backups, chain epoch), ADR 0059 (encrypted backups),
  ADR 0066 (exit codes, journal), ADR 0072 (`fsa status`), ADR 0074 (runtime.json), ADR 0077/0078 (`config set`),
  ADR 0080 (capture mail)

## Context
Plan unit U17 (PLAN M12-T02): BUILD_PROMPT_V2 §7 (`fsa restore`, `--drill`, `fsa install --from-backup`), R41, R42 and
plan R54, R57, R60, R64, R67, R68, AE8, F6, J8; KTD16, KTD18, KTD25, KTD26. U16 (ADR 0084) fixed the format and the HMAC
keying; U18 (upgrade supervisor) will reuse the restore primitives for its automatic rollback. Facts this relies on,
checked 2026-10-19: `pg_restore --data-only --table=migrations --file=-` prints a custom-format dump's migration rows
without a database (host PGDG 16.14 and the image's 16); `pg_restore --list` names an extension `EXTENSION - pg_trgm`
and every other object with its type, schema and tag (fixture `tests/Fixtures/restore/pg-restore-list.txt`, a real dump
of this app); the app image's www-data is 33:33 (`docker/app/Dockerfile`); Compose uses an existing volume declared
`external: true` with an explicit `name` as it is; the fake age of ADR 0084 decrypts files with `--output`.

## Decision
**Sequence and journal (KTD18, KTD26, R54).** `fsa restore <backup>` asks first (exit 6 without `--yes`), then runs
under the instance lock as the journaled operation `restore`, each phase written before it starts:

| phase | what | on failure |
|---|---|---|
| `plan` | the names, from the journal ID: `<db>_restore_<id>`, `<db>_pre_restore_<id>`, `fsa_restore_<id>`, `<project>_app-storage-r<id>` | — |
| `prepare` | the socket-less prepare one-off (below) and the verdict (RestoreChecks) | exit 2 or 7, nothing changed, scratch removed |
| `space` | safety backup + 3 × dump + the storage archive's unpacked bytes + 1 GiB on the instance directory's file system | exit 3 |
| `quiesce` | gate up, app/scheduler/backup stopped, then the queue with its 100 s grace, `audit:seal` | exit 5 (stack restarted) |
| `ledger` | `audit:verify --heads` of the live database appended to `backups/ledger.log` with action, host UID, backup, journal | exit 5 |
| `safety_backup` | `SafetyBackup::take($state, 'restore', $lock)` (unencrypted until sealed) | exit 5 |
| `restore_database` | leftover scratch dropped, scratch created (restricted role for an unauthenticated archive), `pg_restore --no-owner --no-privileges --single-transaction --exit-on-error`, ownership adopted | exit 5 |
| `restore_storage` | fresh labelled volume, GNU tar as 33:33, `--network none`, only the volume and the archive mounted | exit 5 |
| `finish` | `instance:restore-finish` in the scratch database (below) | exit 5 |
| `erasures` | data-export directories of accounts erased since the backup removed from the fresh volume | exit 5 |
| `swap` | databases renamed (live → aside, scratch → live), state names the fresh volume, APP keys rendered, Valkey flushed | previous database and volume back: exit 5, else 8 |
| `verify` | the app alone starts behind the gate; the internal smoke test sends the gate's token (curl reads `X-Fsa-Smoke-Token` from stdin) | same |
| `open` | gate down, every service starts | same |
| `cleanup` | aside database dropped, replaced volume removed, scratch removed, safety backup sealed, history `restored` | warnings only |

The plan's order (epoch before the upgrade child) holds for same-version backups; an older backup is not restored in
place yet (below). Before the swap a failure drops the scratch database, role, volume and directory, lowers the gate
and starts the previous stack: exit 5 `rolled_back` (the plan's "failure before the swap exits 5 with the old instance
running"). From the swap on, the put-aside database is renamed back (the restored one gets the scratch name and is
dropped), the state names the previous volume again, Valkey is flushed, the app must pass the smoke test behind the gate,
then everything starts: exit 5. If that fails, every frontend stops, the gate stays up, and the result names the
databases, both volumes and the safety backup: exit 8 `rollback_failed`, the journal stays open.

A resumed run (`fsa restore <same backup>` with the same flags; the journal accepts only the same backup) repeats the
phases before the swap from a clean scratch state (`always` phases; ledger and safety backup are kept), returns to the
previous instance when it finds a swap started but not verified (exit 5 `restore_interrupted`), and only opens and
cleans up after a verified swap. `fsa restore --abandon` does the same without repeating anything: before the swap it
removes the scratch artefacts and restarts the previous stack, after an unverified swap it returns to the previous
instance, after a verified one it finishes; the journal closes with `ok`. A process killed between the two
`ALTER DATABASE … RENAME` statements (milliseconds) leaves only the aside name: that case stops with exit 8 and the
names rather than guessing. A run killed inside the swap returns to the previous volume even when the state already
named the fresh one; a completed swap whose put-aside database or previous volume is gone is exit 8, never a silent mix;
once the clean-up began, a resumed run only finishes it. `instance:restore-db` drops only databases whose names contain
`restore_` (scratch, put-aside, drill) and touches only `fsa_restore_…`/`fsa_drill_…` roles; the carry reads the
pre-restore database in a read-only session; names from an archive reach error messages without control characters.

**Exit codes (§7, ADR 0066).** 2: damaged (a file does not match `SHA256SUMS`), unsafe (a hostile member, a refused dump
object, no migrations table), unauthenticated without `--accept-unauthenticated`, an APP key fingerprint nobody can
supply, encrypted without identity or with a wrong passphrase, a backup the ops container cannot see. 3: disk space.
5: failed and rolled back (before or after the swap). 6: confirmation. 7: a newer migration set (AE8), a version below
the release manifest's `upgrades_from`, an older backup in place (`upgrade_child_unavailable`). 8: the way back failed.

**Prepare one-off and extraction (R41, KTD16).** `fsa restore-prepare` (an image role like `backup-daemon`, hidden) runs
from the instance's app image with `--network none`, no socket, as the owner (KTD2), with the backup (or its directory)
and the identity file's directory mounted read-only and only `backups/.restore-<id>` (0750) writable; the passphrase and
the HMAC keys arrive on stdin, never as arguments. The identity file is mounted alone (not its directory), the scratch
directory's room for the decrypted set is checked before it is written, and the paths the one-off reports back are
accepted only when they are the backup itself or lie in the scratch directory (they become mounts of later containers). It decrypts (identity via batchpass, then `backup.tar.age` with the
identity, straight to `backup.tar`), reads every tar header itself (TarStream: ustar, GNU long names, pax `path`/`size`,
base-256 sizes, checksums) and refuses the whole archive for an absolute path, a `..` segment, a control character, a
symlink, a hard link, a device, a FIFO or any other type, a non-file member that carries data (GNU tar skips no data
after a directory header and would read it as the next header) and anything but zero bytes after the end blocks; a set member must be a regular file named like a backup file
(BackupSet::FILES), once. Only then is anything written (exclusive create, 0600), and `backup.tar` is deleted. A plain
backup directory is read in place once every entry is a regular file (no symlink); its storage archive is copied into
the scratch directory (0640) so tar can read it as www-data with the owner's group added. The storage archive is judged
the same way and listed for the drill. GNU tar extracts it later, as 33:33 with `--no-same-owner
--no-same-permissions`, so a member can never get another owner or a set-UID bit for anyone but www-data. Every `tar`,
`pg_restore` and `rm` argument list puts `--` before file operands (the brief's U16 finding).

**Dump inspection (KTD18).** `pg_restore --list` before anything is restored: allowed are TABLE, TABLE DATA, SEQUENCE,
SEQUENCE SET, SEQUENCE OWNED BY, DEFAULT, CONSTRAINT, FK CONSTRAINT, INDEX, TRIGGER, FUNCTION, COMMENT and EXTENSION,
in schema `public`, and the only extension is `pg_trgm` (the explorer's trigram index; trusted, so a non-superuser
database owner may create it). Any other extension, an event trigger, a rule, a policy, a procedural language, a foreign
server, a large object, another schema or an unreadable line refuses the backup: exit 2 `backup_unsafe`. For an
unauthenticated archive, functions and triggers must also carry the app's own names (DumpInspector::ROUTINES and
TRIGGERS; a unit test keeps both in step with the migrations).

**HMAC and APP keys (R67, KTD16, KTD18).** The HMAC is checked only with keys from outside the archive: the instance's
APP_KEY and APP_PREVIOUS_KEYS (env/app.env) and, with `--app-key-file`, the keys of a file (one `base64:` key per line,
or an env file such as the old host's env/app.env). fsa passes derived HMAC keys (HKDF as ADR 0084) with their
fingerprints; the one-off reports which verified. Only the instance's current APP_KEY and the key file's keys
authenticate (a previous key may have been retired because it leaked; a backup made under one needs `--app-key-file`),
and only when the key that verified is the one the manifest names first: then the backup is `authenticated`;
anything else (a mismatch, no HMAC, the 1.0 format) needs `--accept-unauthenticated` and is then
restored by `fsa_restore_<id>`, a LOGIN role without SUPERUSER, CREATEDB, CREATEROLE, REPLICATION or BYPASSRLS that owns
only the scratch database (PUBLIC may not connect), with `--no-owner --no-privileges`. Before anything is handed over,
the schema check (`instance:restore-db audit`, SchemaAudit) compares every object that carries code (functions outside
extensions, triggers, column defaults and generated columns, indexes, check constraints, rules, policies, views, event
triggers, schemas, extensions, domain and enum types) by name and printed definition with a database this image
migrated: the live database for `fsa restore` and the drill, the empty live database migrated first for `--from-backup`.
The restored database may lack objects (an older backup) but never have other or changed ones, else exit 2
`backup_unsafe` (exit 5 once the instance was quiesced). Only then do `REASSIGN OWNED` and `DROP OWNED` hand everything
to the app's user, and the role goes. Every fingerprint the manifest lists must be known
(instance or key file; for `--from-backup` also the keys inside the archive), else exit 2 `app_key_unknown`. Key-file
keys the backup lists and the instance lacks become APP_PREVIOUS_KEYS at the swap (InstanceSecrets::previousKeys, now
rendered for app, queue, scheduler and one-offs), so data encrypted under them decrypts; `instance:restore-finish` gets
them by name before the swap for its decrypt sample. The backup service's BACKUP_APP_FINGERPRINTS lists every key's
fingerprint, the current one first (the HMAC still derives from the current key).

**What `instance:restore-finish` does (R64, R68, KTD26),** in the scratch database, in a socket-less one-off of the app
service (`DB_DATABASE` by name), the pre-restore database as a read-only second connection:

1. the new epoch, `ChainEpoch::begin('restored', seed, previous heads)`, seeded with the higher of the ledger's highest
   epoch and the live epoch, then `instance.restored` (backup, epochs, the live database's final heads, the restored
   database's heads, host UID) as the first entry of the new epoch;
2. the security-state carry: devices revoked or asked to wipe since the backup; users deactivated or erased (the erasure
   scrub runs again on the restored row) or gone; password hashes and MFA settings that changed; memberships deactivated
   or gone; role and permission assignments removed; API tokens deleted; operators revoked;
3. every sequence in `public` and the app's `number_sequences` rows set to the greater of restored and live value, so a
   submission number or an ID is never issued twice;
4. sessions, remember tokens, password-reset tokens and sign-in links cleared;
5. `REVOKE ALL … FROM PUBLIC` on `audit_seal`, `audit_prune` and `audit_prunable` (the dump has `--no-privileges`, so
   the functions came back with PUBLIC EXECUTE) and `BackupRole::ensure()` (the U16 review finding);
6. a decrypt sample of the encrypted columns (mail payloads, mail settings, webhook secrets, identity providers, MFA
   secrets): a failure stops the restore before the swap (exit 5 `decrypt_failed`).

The data-export files of accounts erased since the backup come back with the storage archive; the finish step names
their directories and fsa removes them from the fresh volume (`data-exports/<org>/<id>` below the local and public
disks only) before the swap.

**Drill (R41).** `fsa restore --drill <backup>` takes the instance lock without a journal, prepares and judges the backup
like a restore (an older backup is allowed and reported as pending migrations; a newer one is exit 7), restores it into
`restore_drill_<id>` and runs `instance:restore-drill` in a one-off with `DB_DATABASE`, `MAIL_MAILER=array` and
`QUEUE_CONNECTION=sync`: counts, chain heads and their verification, the storage archive's file list (stdin) against the
file references, one document of the newest submission rendered through the app's renderer into a temporary directory
(no rendition row, no file on the storage volume), a decrypt sample and the migration set. The scratch database and
directory are dropped whatever happened; `restore_drill_*` leftovers of a killed drill go with the next drill. No gate,
no stopped service, no ledger line, no state change; exit 4 when a check fails.

**`fsa install --from-backup` (R42, R60, F6).** The backup is prepared and judged with purpose `from-backup` from any
path the launcher mounted. Portable settings come from the backup's `settings.json` (or, for a 1.0 archive, the
allow-listed `.env` values), host-bound ones (ports, LAN address, proxy wiring, tunnel, CA, owner) from flags and host
facts; `--domain` or `--url` overrides the backup's URL. A domain that does not resolve to the host yet is "certificate
pending": a warning, not exit 3, and the smoke test checks the app internally. The instance starts with capture mail
(the backup's SMTP server is listed under `pending_mail`) and with webhooks paused (`webhooks_paused` in the state,
`webhooks.paused` in runtime.json: the queue does not claim webhook steps; the stalled-run sweep takes them up within
`workflows.sweep_after_minutes`). The operator confirms the move with `fsa config set webhooks on` (new key, every mode,
runtime only, no service recreated) and `fsa config set mail smtp …` (ADR 0080). APP_KEY is the backup's current key
(from the key file or, inside an encrypted archive, `secrets.env`; a 1.0 archive's `env`), the other listed keys become
APP_PREVIOUS_KEYS; no key for the first listed fingerprint is exit 2 `app_key_required`. Without mode flags the backup's
mode applies: its domain (domain mode), local mode, or for a proxy-mode backup exit 2 `proxy_flags_required`;
`--port` without `--domain` means local mode; a 1.0 `APP_URL` with https and a public host name means domain mode,
anything else local mode. With `--from-backup`, `--org`, `--owner-name`, `--locale`, `--demo` and the SMTP and sender
flags are exit 2 `flag_not_applicable` (the data comes from the backup); `--owner-email` only asks for a sign-in link
for that existing admin at the end. The phases are `backup` (release verified, the backup prepared and judged; repeated
on a resume until `restore_storage` completed, so the scratch set is rebuilt) → doctor → secrets → state → pull → up →
`restore_database` (scratch database swapped with the empty live one, which is dropped) → `restore_storage` → migrate
(install's own phase brings an older dump up to date) and the backup role → `restore_finish` (no carry: there is no
earlier database) → start → smoke → done; the state history gets `installed` (with `from_backup`) and
`installed_from_backup` (backup, epoch). The same flags again on the finished instance change nothing; another backup is
exit 2 `use_restore`.

**1.0 reader and allow-list (R60, KTD20).** A directory with `manifest.txt` and `SHA256SUMS` (sha256sum format, the same
name rules) is the 1.0 format: no HMAC, so always `--accept-unauthenticated`. Its migration set is read from the dump.
From its `env` only APP_KEY, APP_PREVIOUS_KEYS, APP_URL, MAIL_FROM_ADDRESS, MAIL_FROM_NAME, MAIL_HOST and MAIL_PORT are
read (LegacyEnv); APP_DEBUG, SSO_ALLOW_INSECURE_IDP, SSO_DNS_DRIVER, TRUSTED_PROXIES and SMTP credentials never are,
and fsa's rendering forces `APP_DEBUG=false`, `SSO_ALLOW_INSECURE_IDP=false`, `SSO_DNS_DRIVER=dns`.

**Older backups and the upgrade child (KTD18, U18).** "Newer" and "older" compare migration sets read from the dump
itself (never a manifest an unauthenticated archive could forge). A backup that lacks migrations of this image is older.
Restoring it in place needs the upgrade child of U18 (supervised migrations with lock_timeout and liveness, version
steps); until it exists `fsa restore` exits 7 `upgrade_child_unavailable` with the hint to use `fsa install
--from-backup` (a new directory, whose migrate phase upgrades the dump) or `--drill`. An fsa backup below the release
manifest's `upgrades_from` is exit 7 `backup_too_old`; the 1.0 format is judged by its migration set alone.

**Swap and storage (KTD18).** Databases are swapped by renaming in the maintenance database after terminating their
backends (`instance:restore-db`, never the live name dropped, never a system database). The storage volume is not
swapped in place: the state's new host-bound setting `storage_volume` names the fresh volume, and the renderer declares
`app-storage` as that existing volume (`external: true`, explicit name), so app, queue, scheduler and the backup service
mount it after their recreation. The fresh volume carries the instance label and the role label `app-storage`, so
uninstall removes it like every other volume and its final backup (ADR 0073) finds and archives it. The old database
and volume stay until the restored instance passed its verification.

**Stray files (ADR 0084 finding).** `SHA256SUMS` lines must be `<sha256> [ *]<name>` with a plain name (letters,
digits, `.`, `_`, `-`, no leading dot, no `/`); anything else refuses the backup before a byte is hashed. Dotfiles in a
backup directory (`.DS_Store`, a killed seal's `.backup.tar.age.tmp`) are ignored and reported as the warning
`backup_strays_ignored`; any other unlisted file still fails verification.

**Status (R45).** `fsa status` reports `last_restore` and `restore` (storage volume, paused webhooks, an interrupted
restore with `fsa restore <backup>` and `fsa restore --abandon`, scratch directories left behind, warning
`restore_scratch_left`); `operation_unfinished` names both commands. `fsa config show` lists `storage_volume`,
`webhooks` and `last_restore`.

**What U18 reuses.** RestoreDatabases (create, `pg_restore`, adopt, swap, drop, finish with `--reason=rolled_back
--no-carry`), RestoreStorage (fresh volume, fill), RestoreStack (quiesce, render, flush, verify behind the gate,
reopen), RestoreRecovery, the Ledger and the prepare step for a safety backup; the automatic rollback skips the carry and
the sequence advance because writers were stopped since the safety backup.

## Alternatives considered
- **Restoring into the live database:** no way back once `pg_restore` starts; a scratch database and a rename keep the
  live data until the restored one is verified.
- **Pointing the app at the scratch database (`DB_DATABASE` in the state):** every env file and the Postgres
  healthcheck would follow the restore's name forever; renaming keeps the database name stable.
- **Copying the restored files into the existing volume:** the old files would be gone before verification; a fresh
  volume per restore keeps them.
- **Letting GNU tar judge the archive (`tar -tv` output):** names with spaces or newlines make its listing ambiguous;
  reading the headers is exact, and GNU tar's own `..` protection stays as a second line.
- **Authenticating `--from-backup` with the APP_KEY inside an encrypted backup:** anyone can encrypt to the public
  recipient, so the key inside proves nothing; the operator passes the old key (or accepts the archive).

## Consequences
- Residual risk, recorded for human review: a TOC entry of an unauthenticated archive can carry more SQL than its label
  says; it runs as the restricted role during `pg_restore` only, and the schema check then refuses any code-bearing
  object this image does not define. What it cannot judge is data (rows are restored as they are), and the app's
  database user is still the Postgres superuser. Only accept archives you made yourself (1.0 backups).
- Docker's data root may be another file system than the instance directory; the space check then underestimates.
- An interrupted restore keeps the gate up until resumed or abandoned (the app answers 503).
- Held webhook steps go out up to `workflows.sweep_after_minutes` (30) after `fsa config set webhooks on`.

## Amendment notes
ADR 0084, 0074 and 0077 carry amendments of 2026-10-19 for the HMAC fingerprints and checksum rules, the runtime
`webhooks` block and the `webhooks` key.
