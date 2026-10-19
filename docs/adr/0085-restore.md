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
| `swap` | databases renamed (live → aside, scratch → live), state names the fresh volume and pauses webhooks, APP keys rendered, Valkey flushed | phase `return`: previous database and volume back, exit 5, else 8 |
| `verify` | the app alone starts behind the gate; the internal smoke test sends the gate's token (curl reads `X-Fsa-Smoke-Token` from stdin) | same |
| `open` | every service starts, still behind the gate | same |
| `gate` | the gate goes down: from here on the restored instance takes requests | fail forward (never back) |
| `cleanup` | aside database dropped, replaced volume removed, scratch removed, safety backup sealed, history `restored` | warnings only |

The plan's order (epoch before the upgrade child) holds for same-version backups; an older backup is not restored in
place yet (below). Before the swap a failure goes back in the journaled phase `abort` (third review M2: started before
anything is touched, so a run killed while going back is finished as an abort and never resumed as a restore over the
writes the reopened instance took): the scratch database, role, volume and directory go, the unchanged app starts, the
gate goes down once it is healthy and the other services start; one that does not is reported as `services_degraded`,
not fatal (L7: requests reach the data they always reached). Exit 5 `rolled_back` (the plan's "failure before the swap
exits 5 with the old instance running"). From the swap until the gate went down, the journaled phase `return` first stops
app, queue, scheduler and backup (M3: workers `open` started must not write into either database while they are renamed,
nor hold connections that make the rename fail), renames the put-aside database back (the restored one gets the scratch
name and is dropped), the state names the previous volume and webhook setting again, Valkey is flushed, the app must
pass the smoke test behind the gate, the gate goes down and everything else starts (degraded services reported): exit 5.
If that fails, every writer stops, the gate stays up, and the result names the databases, both volumes and the safety
backup: exit 8 `rollback_failed`, the journal stays open. Each exit 8 names its own way on (RestoreHalt, L4/L5): after a
failed abort `fsa restore --abandon` starts the previous instance (nothing was swapped); after a failed return
`--abandon` (or the same `fsa restore`) continues returning; when the put-aside database or the previous volume vanished
before any rename back (journal note `no_way_back`) `--abandon` continues forward with the restored database and volume
(verify, start, gate down, clean up). The last resort is always installing the safety backup into a new directory with
`fsa install --from-backup`: `fsa restore` of another backup exits 9 while the journal is open. Every way a restore ends
seals the safety backup when encryption is on, after the instance runs again, except exit 8 (a manual restore of it
then needs no identity).

A resumed run (`fsa restore <same backup>` with the same flags; the journal accepts only the same backup) repeats the
phases before the swap from a clean scratch state unless an `abort` began, which it only finishes (`always` phases;
ledger and safety backup are kept; on an instance a
killed run quiesced, the leftovers go before the space check, which no longer counts the safety backup already taken,
and a failure there goes through the abort path: exit 5, never "nothing changed"). It continues a `return` that began
(after an exit 8 or a kill) and never verifies, opens or cleans up once one began; it returns to the previous instance
when it finds a swap started but not completed, or completed but not verified (exit 5 `restore_interrupted`); after a
verified swap it starts everything behind the gate, lowers it and cleans up, and once lowering began it only lowers and
cleans up (no gate raised again). `fsa restore --abandon` does the same without repeating anything: before the swap it
removes the scratch artefacts and restarts the previous stack, from the swap until the gate went down it returns to the
previous instance (also after an exit 8), afterwards it finishes; the journal closes with `ok`. The return is
repeatable: the journal notes `returning_database` before its first rename and `returned_database` after the last, so a
resumed return never renames twice and accepts a missing put-aside database only when its own rename began; a completed
return is never repeated (the previous instance took requests again), a resumed run only drops what is left and seals. A process
killed between the two `ALTER DATABASE … RENAME` statements of the swap leaves only the aside name: the put-aside
database is renamed back to the live name (`instance:restore-db rename`, restore-made names only, to a free name). A run
killed inside the swap returns to the previous volume even when the state already named the fresh one; a completed swap
whose put-aside database or previous volume is gone, with no return that renamed it, is exit 8, never a silent mix. `instance:restore-db` drops only databases whose names contain
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
in schema `public`, and the only extension is `pg_trgm` (the explorer's trigram index). For a restricted restore the
superuser creates it in the scratch database beforehand and `pg_restore --use-list` skips the dump's EXTENSION and
COMMENT ON EXTENSION entries (`restore.list` in the scratch directory, written by the prepare one-off), so the restricted
role neither owns the extension nor can add its own objects to it (review S1). Any other extension, an event trigger, a rule, a policy, a procedural language, a foreign
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
the schema check (`instance:restore-db audit`, SchemaAudit) compares every object that carries code with a database
this image migrated: functions outside extensions, aggregates, triggers (with their enabled state), rules, policies,
views, event triggers, schemas, operators, casts, domain and enum types by name and printed definition; extensions by
version, owner and the md5 of their sorted members with each member's owner; every grant to a role other than the
object's owner (tables, sequences, columns, routines, schemas, types, languages, the database, default privileges;
grantor ignored) must exist in the reference too; and any per-database role setting (`pg_db_role_setting`) is refused,
named by parameter, never by value (review S1, S2); column defaults (generated columns
included), check constraints and indexes by name and by every `name(` their printed expression calls (string literals
left out), which must be one the reference's own expressions use: Postgres prints a re-parsed expression differently
after a dump and restore (J08 found `IN (…)` coming back as `= ANY (ARRAY[…])`) and records no dependency on built-in
functions, so `pg_read_file(…)` in a default is caught by name. The reference is the live database for `fsa restore`
and the drill, the empty live database migrated first for `--from-backup`.
The restored database may lack objects (an older backup) but never have other or changed ones, else exit 2
`backup_unsafe` (exit 5 once the instance was quiesced). Only then do `REASSIGN OWNED` and `DROP OWNED` hand everything
to the app's user; adopt then revokes every explicit grant to a non-owner (PUBLIC included) on public tables, sequences,
columns and routines, resets per-role and database settings, revokes everything on the database from PUBLIC and grants
CONNECT and TEMPORARY back to PUBLIC (Postgres's default); the finish step grants the backup role's rights again, and the role goes. Every fingerprint the manifest lists must be known
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
   or gone; role and permission assignments removed; API tokens deleted; operators revoked; and (review S3) external
   identities, identity providers, their mappings and SSO domains deleted since the backup (status, JIT and enforcement
   of providers and the verification of domains copied), group memberships and form access rows removed, organizations
   soft-deleted, webhook endpoints deleted or deactivated, and changed e-mail addresses (a conflict with the unique index
   is skipped and counted);
3. every sequence in `public` and the app's `number_sequences` rows set to the greater of restored and live value, so a
   submission number or an ID is never issued twice;
4. sessions, remember tokens, password-reset tokens and sign-in links cleared;
5. `REVOKE ALL … FROM PUBLIC` on `audit_seal`, `audit_prune` and `audit_prunable` (the dump has `--no-privileges`, so
   the functions came back with PUBLIC EXECUTE) and `BackupRole::ensure()` (the U16 review finding);
6. a decrypt sample of the encrypted columns (mail payloads, mail settings, webhook secrets, identity providers, MFA
   secrets): a failure stops the restore before the swap (exit 5 `decrypt_failed`).

Everything it writes is one transaction on the restored database (review A9): a failure leaves no epoch and no
`instance.restored` behind. That alone did not stop a second epoch (third review, correcting this ADR): a finish that
committed while fsa died before recording its phase ran again on resume of a `--from-backup` install, whose
`restore_finish` runs on the swapped live database. fsa now passes its journal ID (`--journal`), `instance.restored`
records it, and a finish for a journal that already has its entry writes no second epoch and no second entry
(`epoch_repeated: true`; the carry, credentials, grants and the decrypt sample run again, they are idempotent).

**Carry window (third review H1).** The R64 carry compares the pre-restore database with the restored one only while
the former descends from the backup (mode `state`). When its chain has a restore, rollback or reset epoch that began
after the backup's time (fsa passes the manifest's `created_at` as `--backup-created-at`; without it, the restored
database's newest audit entry), it was itself restored from another backup, so its state records no admin actions
(mode `audit`: undoing a mistaken restore with its safety backup, or restoring the same backup twice). Then a
revocation, wipe, erasure, deactivation or soft delete is carried only when its timestamp is after the backup, and every
other change only when the source's audit log names the row after the backup (`auth.password_changed`, `auth.mfa_*`,
the user's `updated` with `email`, `api_token.revoked`, `platform.admin_revoked`, `member.status_changed`/`roles_changed`/
`groups_changed`, `form.access_changed`, the `sso.*` provider and domain events, the generic `deleted`/`updated` of
providers, mappings, domains, webhook endpoints and organizations). The rest is counted in `carry_skipped`, not applied,
and the restore warns `carry_partial` with the counts. In both modes a child row is never removed because its form,
group, provider, role or organization is gone from the source (`cascade_skipped`, reported in the result): restoring a
deleted object brings back its grants and members. The five core cases stay carried by state in mode `state` (revoked
device, erased user, changed password, deleted API token, revoked operator). Limits: after a restore of S, a later
restore of S again loses what the first restore's carry re-applied without audit entries (the carry writes none);
actions without an audit event (hard-deleted users, direct permission removals, deleted membership rows, identities
unlinked outside `sso.connection_changed`, group changes without an actor, password changes with the audit suppressed,
wipe requests other than by timestamp) are carried only in mode `state`.

The data-export files of accounts erased since the backup come back with the storage archive; the finish step names
their directories and fsa removes them from the fresh volume (`data-exports/<org>/<id>` below the local and public
disks only) before the swap.

**Drill (R41).** `fsa restore --drill <backup>` takes the instance lock without a journal, prepares and judges the backup
like a restore (an older backup is allowed and reported as pending migrations; a newer one is exit 7), restores it into
`restore_drill_<id>` and runs `instance:restore-drill` in a one-off with `DB_DATABASE`, `MAIL_MAILER=array`,
`QUEUE_CONNECTION=sync` and `CACHE_STORE=array` (the restored rows share IDs with the live ones, so nothing may reach the
live cache, review A14): counts, chain heads and their verification, the storage archive's file list (stdin) against the
file references, one document of the newest submission rendered through the app's renderer into a temporary directory
(no rendition row, no file on the storage volume), a decrypt sample and the migration set. The scratch database and
directory are dropped whatever happened; `restore_drill_*` leftovers of a killed drill go with the next drill, together
with their `fsa_drill_<id>` role (review A10). No gate, no stopped service, no ledger line, no state change; exit 4 when
a check fails. Known limit (review A14): the rendered document reads the images it embeds (logos, photos) from the live
storage volume, not from the backup; the drill reports `render_storage: live`, and the backup's files are checked by name
against the references instead. Mounting a scratch copy of the storage archive was not worth its disk space and time.

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
exit 2 `use_restore` (hint: install it into a new directory; `fsa restore` takes only the instance's own backups). A
failed attempt removes `backups/.restore-<id>` (the decrypted set: a plaintext dump, `secrets.env`) before it exits; the
resumed attempt prepares it again (review A5). The backup's age recipients are kept only from an authenticated archive
and only in age's format; the others are dropped with the warning `backup_recipients_dropped` (review S4), since an
unauthenticated archive could otherwise make every later backup readable by whoever wrote it. Scratch database, role,
volume and directory names use the hex journal ID only, like an in-place restore (RestoreNames, review S7).

**Whose backup (review A3).** `fsa restore` in place takes only backups this instance made: the manifest's `instance.uuid`
must be the state's UUID, else exit 2 `backup_other_instance` with the hint to `fsa install --from-backup` into a new
directory. The R64 carry matches rows by their IDs, so another instance's users would inherit this one's revocations and
erasures. A 1.0 archive names no instance: it counts as this instance's only when the APP_KEY in its `env` is one of the
instance's own keys (current or previous: an adopted 1.0 install keeps its key, U19); without its `env` it is refused.
An instance installed with `--from-backup` gets a new UUID, so backups of its predecessor go to a new directory too (the
predecessor's backups made after the move would match the wrong accounts). The drill takes any instance's backup.

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
restore with `fsa restore <backup>` and `fsa restore --abandon`, `swap` as `done`, `interrupted` or `not_started` and
`swapped` null while a killed swap's renames are unknown (review A13), `returning`, scratch directories left behind,
warning `restore_scratch_left`); `operation_unfinished` names both commands. `fsa config show` lists `storage_volume`,
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
- An in-place restore also leaves webhooks paused (review A15): runs restored in flight would repeat deliveries the
  receivers got after the backup. The result says so (`webhooks: paused`, warning `webhooks_paused`); the operator checks
  the receivers and runs `fsa config set webhooks on`. Marking restored in-flight runs instead would need the app to know
  which deliveries happened after the backup, which it cannot.
- A webhook step is held when the runtime file cannot be read in an instance (fail closed, review S5/A11); without a
  runtime directory at all (development, tests, installs without fsa) nothing is held.
- When the free disk space cannot be read (`df` fails), the space checks pass and the restore, the drill and
  `--from-backup` warn `disk_space_unknown` (review A12, its drill and `--from-backup` half in the third review):
  refusing would block every restore on such a host.
- Valkey is flushed by a `valkey-cli FLUSHALL` one-off of the `redis` service with `VALKEY_EXTRA_FLAGS` emptied: the
  image's entrypoint appends that variable (`--requirepass …`) to every command, which made FLUSHALL a syntax error
  (J08 reported `valkey_not_flushed` until then) and put the password in the one-off's arguments.
- A signal (Ctrl-C, SIGTERM) reaches a restore at a phase boundary and is handled like a failure: before the gate goes
  down the restore goes back to the previous instance (exit 5) rather than leaving it quiesced; a signal during that way
  back leaves the journal open for a resume. A run killed right after appending to `backups/ledger.log` appends the same
  heads again on resume; the ledger is append-only and read by its highest epoch, so the duplicate is harmless.

## Amendment notes
U19 verification review (R1, ADR 0087): a 1.0 archive whose `env` holds no APP key counts as this instance's only when it
is the adopt backup this instance wrote, by its name and the digest of its SHA256SUMS as the `adopted` record holds them;
the first key fingerprint of its unauthenticated manifest is no proof on its own.
ADR 0084, 0074 and 0077 carry amendments of 2026-10-19 for the HMAC fingerprints and checksum rules, the runtime
`webhooks` block and the `webhooks` key. The second review of U17 (2026-10-19, findings A1–A15, S1–S7) changed this ADR in
place: the `gate` and `return` phases, the gate lowered last, the repeatable return, the rename of a half-done swap,
sealing on every closing path, the resumed run on a quiesced instance, the restricted restore's extension, the wider
schema audit and adopt, the wider R64 carry, the finish transaction, whose backups an in-place restore takes, the
from-backup recipients and scratch clean-up, the drill's cache and leftovers, paused webhooks after an in-place restore,
fail-closed webhook holds and the unknown free space. The third review (findings H1, M2, M3, L4–L7) added the journaled
`abort`, the writers stopped before a return, the degraded but open previous instance, the forward `--abandon` without a
way back, the exit-8 hints, the epoch keyed on the journal and the carry window.
