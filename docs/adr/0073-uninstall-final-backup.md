# 0073: fsa uninstall writes a final backup with --keep-backups; orphan and unknown-ID guards
- Status: accepted
- Date: 2026-10-18

## Context
The M9 gate review found that `fsa uninstall` (ADR 0072) destroyed data without a backup step, against
BUILD_PROMPT_V2 §13.3 ("every destructive command backs up first unless the user skips it explicitly"). With
`--keep-backups` it kept `backups/` but deleted `env/app.env`, the only copy of APP_KEY, so the kept dumps lost
their encrypted columns. `--orphans <uuid>` force-removed any other installation's resources, including a live,
moved one; without a determinable UUID uninstall deleted the files (APP_KEY, database password) and left the
labelled volumes behind with exit 0. R44 (uninstall removes everything after confirmation) stays.

## Decision
**Final backup (`--keep-backups`).** Before anything is removed, under the instance lock, `Lifecycle\FinalBackup`
writes `backups/final-<UTC yyyymmddThhmmssZ>/` (0700; every file 0600):

| file | content | how |
|---|---|---|
| `database.dump` | `pg_dump --format=custom --no-password` | socket-less one-off of the app service (KTD25): `compose run --rm --no-TTY --no-deps --entrypoint pg_dump app`, connection values from `env/app.env` passed by name (`PGHOST`, `PGPORT`, `PGDATABASE`, `PGUSER`, `PGPASSWORD`), never in argv; the image carries the PostgreSQL 16 client (PGDG) |
| `app-storage.tar` | the app-storage volume | the same one-off with `--entrypoint tar -C /var/www/html/storage/app -cf - .` |
| `app-key.env` | APP_KEY and APP_PREVIOUS_KEYS (if set) | copied from `env/app.env` |
| `backup.json` | kind, time, UUID, version, size and SHA-256 of each file | written last |

- Both one-offs stream stdout into the file through `DockerGateway::composeRunToFile` (new): the file is created
  exclusively with mode 0600 before the first byte (`Docker\FileSink`), each chunk is written as it arrives and
  dropped from the process buffer, and the file is fsynced. A dump or archive never passes through PHP memory
  (the ops container has 512 MB; storage volumes can be larger). `--entrypoint` skips the app entrypoint's
  `artisan optimize`, so nothing but the tool's output reaches stdout.
- A dump must start with `PGDMP` and an archive must carry `ustar` at offset 257; anything else counts as failed.
- A stopped instance gets only Postgres started (`compose up --wait postgres`) for the dump.
- The database is dumped only when the instance has its `pg-data` volume, the storage archived only when it has
  `app-storage` (by the `com.docker.compose.volume` label or the `_<volume>` name suffix). With neither, only
  `app-key.env` is written; without an APP_KEY either, no backup directory is made (`final_backup: null`).
- Any failure deletes the partial backup directory and is **exit 4 `final_backup_failed`** (retryable; nothing
  removed; Docker unreachable stays exit 10). An unreadable state is such a failure (the services cannot be run);
  the hint names `fsa uninstall` without `--keep-backups` as the way to delete everything without a backup.
- The app roles keep running during the backup: pg_dump reads one consistent snapshot, and a failed backup must
  leave the instance as it was. Files written to storage during the archive may be missing from it.

**Without `--keep-backups`** the confirmation question says that data and `backups/` are deleted and that no final
backup is made; the JSON says it too: `data.data_deleted: true`, `data.backups_deleted: true`,
`data.final_backup: null`.

**Exit codes of uninstall.** 0 done; 2 bad `--orphans` value, this instance's UUID, or not an orphan of this
directory (`not_an_orphan`); 4 final backup failed (`final_backup_failed`, nothing removed) or a resource could not
be removed (`uninstall_incomplete`, retryable, `changed` true when something was removed or backed up; the files
stay so the same command finishes the job); 6 confirmation required; 7 `orphans_live` or `instance_id_unknown`;
9 lock held. `InstanceException` now carries `changed`, so a failure envelope can report a partial change.

**Unknown installation ID.** When no UUID can be determined (state invalid or missing, no install journal, no
compose file, or containers of several installations ran from the directory), uninstall refuses with **exit 7
`instance_id_unknown`** while any container with an installation label ran from this directory
(`com.docker.compose.project.working_dir`) or any labelled volume belongs to a compose project named after it
(`fsa-<slug>-<4 hex of that UUID>`). `data.candidates` lists the UUIDs; nothing is deleted. With nothing tied to the
directory, the files go as before, with the `instance_id_unknown` warning.

**`--orphans <uuid>`.** Accepted only for a UUID that `ProjectConflicts::orphans()` reports for this directory (the
list install warns about). Refused with exit 7 `orphans_live` while any container with that label runs anywhere
(the installation may have moved rather than been deleted). Containers of it outside this directory: only its
containers that ran from here are removed; its networks and volumes are kept (warning `orphan_volumes_kept`).
Install's `orphans_found` hint says to check with `docker ps -a --filter label=app.form-schema.instance=<uuid>`
first.

## Alternatives considered
- **Dump through stdout into memory (`composeRun`):** one line less, but a storage archive of a few GB would exceed
  the ops container's memory limit and crash uninstall without an envelope.
- **Writing the dump into a bind mount from inside the one-off:** files owned by the container user
  (`www-data`), so the ops side could not set 0600 on rootful hosts.
- **Stopping the app roles first for a quiescent storage archive:** a failed backup would leave the instance
  stopped although "nothing was removed".
- **Always writing a final backup, also without `--keep-backups`:** the backup would be deleted with `backups/` a
  moment later; R44 asks for complete removal after confirmation.
- **Deleting the files of an instance with an unknown UUID (as before):** leaves volumes whose secrets are gone.

## Consequences
- `fsa backup` (U16) can reuse `composeRunToFile` and the dump/archive checks; `fsa restore` reads `backup.json`.
- The J01 journey uninstalls without `--keep-backups`; the final-backup path is unit-tested against the recording
  gateway, and `composeRunToFile` with the real pg_dump and tar was checked against the app image and the dev
  Postgres (dump read back with `pg_restore --list`).
- ADR 0072's uninstall paragraph (exit 1 for an incomplete uninstall, `--orphans` removing any other UUID) is
  superseded by this ADR.

## Amendment 2026-10-20: adopted instances (U19)
An instance made by `fsa adopt` (ADR 0087) gets the final backup without `--keep-backups` and keeps `backups/` (its 1.0
checkout holds the data only up to the adopt); the new `--no-backup` skips the final backup for any instance. The result of
an adopted instance's uninstall adds `adopted` (checkout, the writes since adopt read from the running Postgres against the
ledger's baseline, and `restart_1_0`) and the warnings `restart_1_0` and `writes_since_adopt`. The 1.0 volumes carry no
instance label and stay.

## Amendment 2026-10-20: the final backup is a 2.0 backup (M12 gate)
The gate review found that the `final-*` set above (`backup.json`, `app-storage.tar`) was read by no fsa command, kept
`app-key.env` in plaintext even with backup encryption on, and was written while the app still took writes, although
the proxy-mode hint and this ADR promised `fsa install --from-backup` would carry the data over. It is replaced:

- **Format.** `Lifecycle\FinalBackup` runs the backup service's own entry point under the uninstall lock
  (`fsa backup-daemon --once --kind=final`, BackupRunner, as `fsa backup` does): `backups/final-<UTC>/` is a backup of
  ADR 0084's format (dump and storage from one snapshot, manifest, SHA256SUMS and its HMAC), encrypted when the instance
  has recipients. `BackupName` knows the kind `final`; retention never prunes it. `fsa install --from-backup` and
  `fsa restore` read it (`UninstallFinalBackupMoveTest`).
- **APP keys.** Encrypted: they go into the set as `secrets.env` (APP_KEY, APP_PREVIOUS_KEYS and the SMTP account, as
  `--include-secrets`); its HMAC can only be verified with a key from outside the archive, so a move without the old
  key takes `--accept-unauthenticated` (the restricted restore role), as the warning `final_backup_encrypted` says. Plain: this ADR's decision stays, the keys go beside the set as `backups/<name>.app-key.env`
  (0600), and the result names the file in the warning `app_key_beside_backup` with the `--from-backup … --app-key-file`
  command; an adopted instance's uninstall does the same (U19 V2). Without the database volume nothing is dumped: only
  the keys are saved beside (`final-<UTC>.app-key.env`, or `.age` to the recipients when encryption is on), for the
  backups `backups/` keeps.
- **Quiesced first (D2).** The gate goes up, app, scheduler and the backup service stop, then the queue with its grace
  period; then the dump; then the writes since adopt are counted. A failed backup starts again what ran and lowers the
  gate. The alternative this ADR rejected ("a failed backup would leave the instance stopped") no longer holds.
- **Failure** stays exit 4 `final_backup_failed`; its hint names `fsa uninstall --no-backup` for deleting everything
  without a final backup (DOC15), and `data.reason` carries the backup's own reason.
- `data.final_backup` is `{dir, backup, encrypted, includes_secrets, key_file, missing_files}` (was `{dir, files}`).
- `composeRunToFile` stays for the adopt backup (ADR 0087); the final backup no longer uses it.
- Superseded statements: the file table and the `--keep-backups` paragraph of "Decision", "The app roles keep running
  during the backup", the consequence "`fsa restore` reads `backup.json`", and U19's review note that a final set is
  restored by hand.
