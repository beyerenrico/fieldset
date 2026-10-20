# Backup and restore

Every instance backs itself up. This guide covers the schedule, encryption, copying backups off the host, restore
drills, restoring, moving to another server and the audit log's chain epochs. Agents: see the
[agent guide](agent-guide.md#recipe-backup-and-restore).

Never restore an instance by hand (`pg_restore`, `psql`, copying volumes): `fsa restore` quiesces the instance,
takes a safety backup, keeps the audit log verifiable and re-applies security changes. The 1.0 scripts
(`scripts/backup.sh`, the manual restore) are for checkout installs only and are in the
[archive](archive/1.0-backup-restore.md).

## What a backup is

A backup is a directory `backups/<kind>-<UTC time>/` in the instance directory with:

- `database.dump` (the whole database), `storage.tar.gz` (uploaded files and generated PDFs);
- `settings.json` (the instance's portable settings), `audit-heads.json` (the audit chain heads and epoch),
  `manifest.json` (the version and what the backup contains);
- `SHA256SUMS` and an HMAC keyed from the instance's APP_KEY, so a changed file is detected;
- with `--include-secrets`, also `secrets.env` (APP_KEY and the SMTP account; encrypted backups only).

Everything is read from one database snapshot while the instance keeps running, and a backup counts only once its
dump reads back. With encryption on, the whole set is one file, `backup.tar.age`.

Kinds: `scheduled`, `manual` (`fsa backup`), `final` (before `fsa uninstall --keep-backups`) and `safety-…` (taken
by `fsa upgrade`, `restore`, `reset`, `rollback` and `adopt` before they change data).

## Schedule and retention

The `backup` service backs up daily at 03:30 in the instance's time zone and keeps the newest 7 scheduled backups.
Only scheduled backups are pruned: manual, final and safety backups stay until you delete them (an upgrade's safety
backup is replaced by the next successful upgrade's). A file named `keep` inside a backup directory protects it from
pruning. A run that would leave less than 1 GiB free is skipped.

```sh
fsa config set backup-schedule 02:15     # or off
fsa config set backup-retention 14       # 1 to 365
fsa backup                               # a backup now
fsa status                               # last backup, next run, backups on disk, alerts
```

`fsa status` and the `/platform` health page warn when the last backup failed, a run was skipped, no backup succeeded
within two hours of its slot, or the backup service does not run. The `/platform` instance page shows the same warnings first, with
the `fsa` command for each, and every active platform operator gets one e-mail per incident (checked hourly). While
mail is captured that e-mail lands in the catcher, so check the instance page or `fsa status`.

## Copy backups off the host

A backup on the same disk does not survive the loss of that disk. Copy `backups/` to another machine regularly, for
example with `rsync -a /opt/form-schema-app/backups/ backup-host:form-schema-app/` from a cron job. Never copy
`staging/`: it holds plaintext working files.

## Encryption

```sh
fsa config set backup-encryption on --identity-out ~/forms-backup-identity.age --passphrase-file ~/forms-passphrase
```

This creates an age key pair. The private key (the identity) is written, encrypted with your passphrase (at least 12
characters), to `--identity-out`, which must be outside the instance directory; only the public key stays in the
instance. Every later backup is one encrypted file. Keep the identity and the passphrase somewhere else: without them,
no encrypted backup can be read. `--passphrase-stdin` asks for the passphrase on a terminal instead of reading a file.
`fsa config set backup-encryption off` switches back to plain backups.

## Restore drill

```sh
fsa restore manual-20261020T101500Z --drill
```

A drill restores the backup into a scratch database, reports counts, the audit chain heads, missing files, a test PDF
render and a decrypt sample, and drops the scratch database. The instance is not touched and nothing needs
confirming. Run one after every upgrade and regularly on the backups you copy off the host.

## Restore

```sh
fsa restore manual-20261020T101500Z
fsa config set webhooks on      # after checking the restored instance
```

The argument is a backup name in `backups/` or a path inside the instance directory. After your confirmation `fsa`:

1. decrypts and checks the backup in a container without network: checksums, the HMAC (verified only with APP keys
   from outside the archive), every archive member and every object of the dump;
2. raises the maintenance gate and stops the workers, appends the current audit chain heads to `backups/ledger.log`,
   and takes a safety backup (`safety-restore-…`);
3. restores into a scratch database and a fresh storage volume, migrates an older backup to the running version;
4. re-applies security changes made since the backup (revoked devices, deactivated or erased users, changed
   passwords, e-mail addresses and MFA, deleted API tokens, SSO identities and providers, revoked operators and
   roles, and more), clears sessions, reset tokens and sign-in links, and keeps numbering from going backwards;
5. starts a new audit chain epoch with an `instance.restored` entry;
6. swaps the restored database and volume in only when the restored instance passes its checks behind the gate.

If anything fails before the swap, the previous instance runs again (exit 5). Webhooks stay paused afterwards, so other
systems are not notified twice.

Options: `--identity-file` and `--passphrase-file` (or `--passphrase-stdin`) for an encrypted backup;
`--app-key-file` for a backup made with another APP key (one key per line, or `APP_KEY=` lines; the keys become
previous keys so old data still decrypts); `--accept-unauthenticated` for a backup whose HMAC cannot be verified;
`--without-files` to restore a backup that lacks the storage archive.

Only backups of the same instance restore in place. A backup from a newer version is refused (exit 7 `backup_newer`):
upgrade first. A restore needs free space for one more copy of the backup's dump and files.

If a restore stopped half-way, `fsa restore --abandon` cleans up and brings the instance back (also after exit 8; the
hint and `data.next` say whether it returns to the previous data or continues with the restored data).

## Move to another server

1. On the old host: `fsa backup --encrypt --include-secrets --identity-out <file> --passphrase-file <file>` (the first
   `--encrypt` turns encryption on).
2. Copy the backup's `backup.tar.age`, the identity and the APP key file `env/app.env` of the instance directory to the
   new host. Keep them private.
3. On the new host: the install one-liner with `--from-backup <backup.tar.age> --identity-file <file>
   --passphrase-file <file> --app-key-file <file>` (plus `--domain <name>` for a domain; until DNS points to the new
   host the instance reports "certificate pending").
4. The moved instance starts with captured mail and paused webhooks so nothing goes out twice; the result lists the
   commands that turn them on (`fsa config set webhooks on`, `fsa config set mail smtp …`). Switch DNS, then remove
   the old instance.

Settings that belong to the instance come from the backup, host-bound ones (directory, ports, domain) from the flags.
`--keep-recipients` keeps the backup's encryption recipients. The new instance gets a new instance ID, so backups of the
old instance do not restore into it in place; use `--from-backup` again for those. A 1.0 backup from
`scripts/backup.sh` installs with `--from-backup <file> --accept-unauthenticated`; its unsafe 1.0 settings are not
applied.

## Chain epochs and the ledger

The audit log is a hash chain per organization: each entry includes the hash of the one before, and `audit:verify`
compares the chain heads with heads kept outside the database. A restore, a rollback and a reset replace the
database, so the chain heads go back in time or start over, which would look like tampering.

To keep that verifiable, each of these operations:

1. appends a line to `backups/ledger.log` before the database changes: who, when, what, which backup, and the
   current chain heads with their epoch (append-only, mode 0600; if the line cannot be written, the operation stops
   with exit 9 `ledger_failed`);
2. starts a new **epoch**: a generation number of the chains, recorded with the replaced heads in the database before
   anything else writes, plus an `instance.restored`, `instance.rolled_back` or `instance.reset` audit entry that
   references the old heads.

Heads are only compared within their own epoch. `audit:verify --heads` reports the epoch with the heads, and so does
each backup's `audit-heads.json`. The epoch never repeats, even after a reset recreated the database: the next one is
always above the highest the ledger has seen. The restore, rollback and reset results report `epoch` (restore and reset
also `previous_epoch`). Keep `ledger.log` with your backups: after a reset it is the record of what was there.
