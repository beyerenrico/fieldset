# Agent guide: running Fieldset with `fieldset`

This guide is for an AI agent (or a script) that installs and operates a Fieldset instance for a person. Every
recipe gives the exact commands, the JSON fields to read and what to do when a step fails. You need a shell on the
host, Docker (Engine 24+ or Docker Desktop) and `curl`. You do not need the repository.

The same guide ships with every release as the asset `agent-guide.md` and lives in the repository at
`docs/guide/agent-guide.md`. People who prefer prose read the [install guide](https://github.com/beyerenrico/fieldset/blob/main/docs/guide/install.md). Links to other
guides point to the repository on GitHub, so they also work in the release asset.
The docs site [beyerenrico.github.io/fieldset](https://beyerenrico.github.io/fieldset/) has one copy per minor version;
`https://beyerenrico.github.io/fieldset/versions.json` lists them, so you can open the copy of the version you run.

## Contents

- [Rules for agents](#rules-for-agents)
- [The result envelope](#the-result-envelope)
- [Exit codes and recovery](#exit-codes-and-recovery)
- Recipes:
  [local demo](#recipe-local-demo) ·
  [sign-in links](#recipe-sign-in-links) ·
  [configure SMTP](#recipe-configure-smtp) ·
  [backup and restore](#recipe-backup-and-restore) ·
  [upgrade](#recipe-upgrade) ·
  [move to another server](#recipe-move-to-another-server) ·
  [VPS install](#recipe-vps-install) ·
  [phone demo](#recipe-phone-demo) ·
  [change the domain](#recipe-change-the-domain) ·
  [Windows (WSL 2)](#recipe-windows-wsl-2) ·
  [repair ownership](#recipe-repair-ownership) ·
  [remove an instance](#recipe-remove-an-instance)
- [How this guide is tested](#how-this-guide-is-tested)

## Rules for agents

1. **Always pass `--json`.** stdout then carries exactly one JSON document (the envelope below); stderr carries
   progress as one JSON object per line. Parse stdout, never the progress lines.
2. **Branch on the exit code and `errors[0].code`,** never on message text. Messages and hints are for people.
3. **Pass `--yes` only for a step the person asked for.** Destructive commands (`uninstall`, `restore`, `rollback`,
   `reset`, `adopt`, `repair-ownership`, and `config set` of a key that changes the URL) exit with 6 `confirmation_required` without it,
   and `data` then holds the question and the facts it is about. Show those to the person, then run the command again
   with `--yes`.
4. **Never put a secret on the command line.** `fieldset` refuses any flag whose name says password, passphrase, secret,
   token or key (exit 2 `secret_in_argument`). Secrets go in through files (`--password-file`, `--passphrase-file`,
   `--app-key-file`) or stdin (`--password-stdin`, `--passphrase-stdin`).
5. **Never print a secret.** One-time secrets (the sign-in link, random demo passwords, the mail catcher's password)
   appear only under `secrets`. Pass `--secrets-file <file>` and `fieldset` writes them to that file (mode 0600) instead;
   the envelope then names the file in `secrets[].written_to`. Hand the file, or its content, to the person; do not
   echo it into a chat log.
6. **Run commands of an instance through its own shim:** `<dir>/fieldset <command> …`, for example `~/forms/fieldset status
   --json`. `install.sh` creates the shim. With `--link` (or by answering its question) `install.sh` also links
   `fieldset` into your PATH; this guide always writes the full path.
7. **A command that stopped half-way is resumed, not repeated by hand.** Resuming means running the command the
   envelope names (`data.command`, see exit 9 below) or, for an install, the same install command again: it continues
   where it stopped (see [a failed install](#a-failed-install)). Never clean up or redo its steps yourself.

Every command lists its flags with `<dir>/fieldset <command> --help`.

## The result envelope

```json
{
  "schema_version": 1,
  "command": "install",
  "status": "ok",
  "exit_code": 0,
  "changed": true,
  "data": {},
  "secrets": [{"name": "sign_in_link", "written_to": "/home/me/forms-secrets.json", "expires_at": "2026-10-20T10:15:00Z"}],
  "warnings": [{"code": "…", "message": "…", "hint": "…"}],
  "errors": []
}
```

- `status` is `ok`, `failed`, `aborted` (exit 6 and 9: nothing changed), `rolled_back` (exit 5) or `rollback_failed`
  (exit 8). `exit_code` is the process's exit code.
- `changed` says whether the command changed anything. Running a finished command again with the same flags is safe
  and answers `changed: false`.
- Each error is `{code, message, hint, retryable}`. When `retryable` is true, the same command may succeed later.
- Without `--secrets-file`, a secret entry is `{name, value, expires_at}`. The secrets file holds
  `{schema_version, command, secrets: [{name, value, expires_at}]}`.

## Exit codes and recovery

| Exit | Meaning | What to do |
|---|---|---|
| 0 | Success | Read `data`. Look at `warnings` too. |
| 1 | Internal error | Report `errors[0]` to the person. Run `<dir>/fieldset status --json` to see what changed. |
| 2 | Invalid input (a flag, a value, a missing file; `path_on_windows_drive`: the instance directory is on a Windows drive, see [Windows](#recipe-windows-wsl-2)) | Fix the input named in `errors[0]`. Nothing changed, except with `secrets_file_unwritable`: the command may have done its work (`changed: true`) and only its secrets were not saved; the hint says how to get them again. A failed install leaves its directory: see [a failed install](#a-failed-install). |
| 3 | Preflight failed: the doctor (Docker, disk, ports, DNS) or a precondition of the command, such as `default_password_in_use` (accounts still sign in with the demo password `password`); the launcher's owner checks `owner_mapping_unknown` and `socket_no_group_access`, and `owner_not_measured` of `repair-ownership` (see [repair ownership](#recipe-repair-ownership)); `wsl1_unsupported` (see [Windows](#recipe-windows-wsl-2)) | `data.checks` lists the doctor's checks, each `{key, status, summary, hint, details}` with `status` `ok`, `warn`, `fail`, `skipped` or `unknown` (the check could not find out; it counts like `warn`: listed under `warnings`, exit 0 when nothing fails); fix every `fail` as its `hint` says. A precondition names its fix in `errors[0].hint`. Then run the command again. Nothing changed; a failed install leaves its directory (see [a failed install](#a-failed-install)). |
| 3 | install.sh could not download or verify the release (`release_unavailable`, `image_unavailable`, `verify_failed`, `manifest_signature_invalid`, …) | `data` is empty: read `errors[0]`. `release_unavailable` and `image_unavailable` are network problems (`retryable: true`). On `verify_failed` or `manifest_signature_invalid`, stop and report `errors[0]` to the person: never retry with another install.sh or release URL, never bypass the check. Only if `errors[0].message` shows that Sigstore (TUF, Rekor) could not be reached may the same command be run again later. Nothing changed: no directory was created. |
| 4 | A step after the preflight failed (a service unhealthy, a migration, a restore step, `owner_repair_failed` or `secrets_exposed` of `repair-ownership`) | Read `errors[0].hint`: it says what was touched (the restore steps leave the live database and storage alone). Then `<dir>/fieldset logs app --json`. An install resumes when run again. |
| 5 | Rolled back | The instance is back where it was. `errors[0].code` is the cause. See below. |
| 6 | Confirmation required | Show `data.question` and the other `data` facts to the person; run again with `--yes` if they agree. |
| 7 | Incompatible (version, schema, another instance's data) | Nothing changed (a failed install leaves its directory, see [a failed install](#a-failed-install)). `errors[0].hint` names the way forward (for example `--to <version>` or `--major`). |
| 8 | Rollback failed: the instance is stopped behind its maintenance gate | Follow the recovery object below exactly; do not improvise. |
| 9 | Aborted, nothing changed (lock held, unfinished operation, cancelled, `rate_limited`) | See below. `rate_limited` (too many sign-in links, `retryable: true`): wait a few minutes, then run the same command again. |
| 10 | Docker unavailable | Start Docker (or fix access to its socket) and run the command again. |

### The recovery object

When a command cannot finish, the envelope tells you how to continue. Commands in `data.command`, `data.next` and the
hints start with a bare `fieldset`; you may have no `fieldset` on your PATH, so replace that leading `fieldset` with `<dir>/fieldset`, the
instance's shim (for example `fieldset upgrade --resume` becomes `~/forms/fieldset upgrade --resume --json`). Read these fields in
this order:

1. **Exit 9 `operation_unfinished`:** an earlier command stopped half-way. `data.command` is the exact command line that
   resumes it and `data.options` its flags (`data.operation` and `data.last_phase` say what stopped where). Run
   `data.command` with `--json` (plus `--yes` if the person agrees, and the same secret files it names). Every other
   changing command answers this exit until the operation is finished.
2. **Exit 9 `instance_locked`:** another `fieldset` command (or a scheduled backup) is running. Wait and run again.
3. **Exit 5 (rolled back):** `data.retry_after` (seconds) means a passing problem: wait, then run the same command
   again. `data.fix_first` means fix that first. `data.command` and `data.options`, when present, are the command to
   repeat. `fieldset config set` and `fieldset tunnel` fill all of these; `fieldset upgrade`, `fieldset restore` and `fieldset reset` name the
   cause in `errors[0]` and the safety backup in `data.safety_backup`.
4. **Exit 8 (rollback failed):** `errors[0].hint` is the complete instruction. `data.next` adds a machine-readable
   part, and its form depends on the command:
   - `fieldset upgrade`: `data.next` is the command to run: `fieldset upgrade --resume`.
   - `fieldset rollback`: `data.next` is `fieldset rollback`.
   - `fieldset restore`: `data.next` is a kind: `abort` (nothing was swapped), `return` (going back to the previous data),
     `forward` or `no_way_back` (continuing with the restored data). In every case the command is
     `<dir>/fieldset restore --abandon --yes --json`; the kind tells the person what they will end up with.
   - `fieldset config set` and `fieldset tunnel`: `data.recovery` lists the steps; running the same command again finishes the
     revert.

   `data.safety_backup`, when present, names the backup taken before the operation. If the recovery command fails
   too, the last resort in the hint is to install that backup into a new directory with `install.sh --dir <new dir>
   --from-backup <backup>`.

### A failed install

Once install.sh has verified the release it creates the instance directory, so an install that fails later (exit 2,
3, 4 or 7, for example `doctor_failed`, `path_missing` or `backup_newer`) leaves that directory behind. Before the
first container starts it holds only install.sh's scaffold (such as `.fieldset-instance`, `fieldset`, `locks`, `operations`,
`releases`), and `<dir>/fieldset status` and `<dir>/fieldset uninstall` answer exit 3 `no_state` ("the installation
did not finish"). Then either:

- fix the cause and run the install command again with the same `--dir` (a corrected flag, such as another
  `--version`, is fine): it continues in that directory; or
- if the person gives up on this install, remove the directory: `rm -r <dir>`. Only do that while `<dir>/fieldset status
  --json` answers `no_state`: then no container, volume or network exists yet.

After exit 4 the instance's containers may exist already: run the same install command again, it resumes.

## Recipe: local demo

Goal: a demo instance on this computer, filled with sample data, mail caught by the instance's mail catcher.

```sh runnable
curl --proto '=https' --tlsv1.2 -fsSL https://github.com/beyerenrico/fieldset/releases/latest/download/install.sh | sh -s -- --dir ~/forms --org "Example Ltd" --owner-email owner@example.com --demo --secrets-file ~/forms-secrets.json --json --yes
```

```json expect
{
  "exit_code": 0,
  "status": "ok",
  "data": {
    "url": "<string>",
    "mode": "local",
    "port": "<int>",
    "version": "<string>",
    "owner_email": "owner@example.com",
    "mail": {"mode": "capture", "catcher_url": "<string>"},
    "demo": {"organization": "<string>", "users": "<list>", "login_url": "<string>"},
    "sign_in_link": {"delivered": "secrets_file", "expires_at": "<string>"},
    "next_commands": "<list>"
  }
}
```

Read:

- `data.url` (for example `http://localhost:8080`): the admin dashboard is at `<url>/admin`, the Field App at
  `<url>/app`. When port 8080 is taken the installer picks the next free port itself (`data.port`); pass `--port <n>`
  only to choose one.
- `secrets[0].written_to`: the file with the owner's one-time sign-in link (valid 15 minutes, one use). Give the link
  to the person; it leads to "set a password".
- `data.demo.users`: the demo accounts. On a loopback-only instance their password is `data.demo.default_password`
  (`password`); when the instance is reachable from other machines, each account gets a random password instead, under
  `secrets` as `demo_password:<email>`.
- `data.mail.catcher_url`: the mail catcher, where every mail of the instance lands. `data.mail.catcher_login_command`
  (local and proxy mode) is `fieldset config show --catcher-login`, which returns the catcher's password as a secret. Run it
  as `~/forms/fieldset config show --catcher-login --secrets-file ~/forms-catcher.json --json`: without `--secrets-file` the
  password is printed on stdout. The catcher asks for a user name and that password: the user name is
  `data.catcher_login.user` (`fieldset`), the password the secret `catcher_password`.

On a computer with a desktop, `--open` hands the sign-in link to the browser instead of a file
(`data.sign_in_link.delivered` is then `browser`). A demo on an instance that is already installed:
`~/forms/fieldset demo --json --secrets-file ~/forms-secrets.json`.

Failures:

- Exit 3 `doctor_failed`: `data.checks` names the failed check, often the port given with `--port` is in use: pass
  another one, or leave `--port` out and the installer picks a free one.
- Exit 10 `docker_too_old`: the Docker Engine is older than 24 (`data.checks` says which version runs). Update Docker.
- Exit 7 `project_conflict`: another instance uses the same directory name. Pick another `--dir`.
- Exit 10 `docker_missing` or `docker_unavailable`: install or start Docker.
- Exit 4 (`services_unhealthy`, `migrate_failed`, …): read `~/forms/fieldset logs app --json`, then run the same install
  command again; it resumes.
- Any failed install leaves `~/forms` behind: see [a failed install](#a-failed-install).

## Recipe: sign-in links

Check that the instance is healthy, then issue a new sign-in link for an admin, for example when the first one expired.

```sh runnable
~/forms/fieldset status --json
```

```json expect
{"exit_code": 0, "status": "ok", "data": {"installed": true, "health": "ok", "mode": "local", "url": "<string>", "services": "<object>", "version": "<string>"}}
```

`data.health` is `ok`, `degraded` (a service is unhealthy: `data.services` says which), `stopped` or `not_started`.
`data.operation` is not null while an operation is unfinished (see exit 9).

```sh runnable
~/forms/fieldset login-link owner@example.com --secrets-file ~/forms-link.json --json
```

```json expect
{"exit_code": 0, "status": "ok", "data": {"email": "owner@example.com", "sign_in_link": {"delivered": "secrets_file", "expires_at": "<string>"}}}
```

The link is in `~/forms-link.json` (`secrets[0].value` of that file). Failures: exit 2 `user_not_found`, `not_admin`,
`inactive` or `sso_required` (the person signs in through single sign-on instead); exit 9 `rate_limited` with a
retryable error: wait a few minutes, then run it again.

## Recipe: configure SMTP

A new instance captures mail: invitations and password resets stay in the mail catcher and reach nobody, and the admin
dashboard shows a "mail is captured" banner. Switch to the person's SMTP server. Put the SMTP password in a file only
the person can read (for example `~/smtp-password`), never on the command line.

```sh runnable
~/forms/fieldset config set mail smtp --smtp-host smtp.example.com --smtp-port 587 --smtp-username forms@example.com --password-file ~/smtp-password --from-address forms@example.com --test-to owner@example.com --json
```

```json expect
{"exit_code": 0, "status": "ok", "data": {"key": "mail", "from": "capture", "to": "<string>", "mail": {"mode": "smtp"}, "checked": "<list>"}}
```

`fieldset` sends a test mail to `--test-to` through the new server before it keeps the change; `data.checked` lists what
it verified and `data.to` describes the new mail setup. The default encryption is
STARTTLS on port 587 (`--smtp-encryption tls` for port 465, `none` only for a relay inside a trusted network). The
password can also come from stdin: `printf '%s\n' "$smtp_password" | ~/forms/fieldset config set mail smtp … --password-stdin --json`.
The mail catcher keeps running for old mail; `--remove-catcher` removes it. `fieldset config set mail capture` switches
back.

Failures:

- Exit 5 `test_mail_failed`: the server refused the mail or the login; the instance still captures mail. The message
  says what the server answered. Fix host, port, user, password or encryption and run again. `data.command` is the
  command to repeat: it names the password the way you passed it (`--password-file <file>` or `--password-stdin`) and
  the user name as `<the same user name>`, which you replace (a user name is never printed).
- Exit 2 `password_required`: `--smtp-username` without `--password-file` or `--password-stdin`.

## Recipe: backup and restore

The instance backs up daily at 03:30 (its time zone) into `<dir>/backups/` and keeps the newest 7 scheduled backups.
Take a backup now:

```sh runnable
~/forms/fieldset backup --json
```

```json expect
{"exit_code": 0, "status": "ok", "data": {"backup": "<string>", "path": "<string>", "kind": "manual", "encrypted": false, "bytes": "<int>"}}
```

`data.backup` is the backup's name (`manual-<UTC time>`), `data.path` its directory. The warning `backup_on_host`
reminds you that a backup on the same disk is no backup: copy `data.path` to another machine. Scheduled backups carry
no APP key: keep the `APP_KEY` and `APP_PREVIOUS_KEYS` lines of `~/forms/env/app.env` outside the host too (ask the
person where), or a restore elsewhere cannot verify the backup and encrypted values stay unreadable (see
[backup and restore](https://github.com/beyerenrico/fieldset/blob/main/docs/guide/backup-restore.md#copy-backups-off-the-host)).

To check the scheduled backups, read `warnings[].code` of `~/forms/fieldset status --json` (each `hint` names the command):

| Code | Meaning | Do |
|---|---|---|
| `backup_failed` | The last backup failed. | Read `~/forms/fieldset logs backup --json`, fix the cause, run `~/forms/fieldset backup --json`. |
| `backup_disk_space` | The last scheduled backup was skipped for lack of disk space. | Free space (old backups in `backups/`), run `~/forms/fieldset backup --json`. |
| `backup_skipped` | Scheduled backups were skipped for more than 24 hours: an operation held the lock. | Finish the unfinished operation (`~/forms/fieldset status --json` names it), run `~/forms/fieldset backup --json`. |
| `backup_service_failed` | The backup service cannot start. | Read `~/forms/fieldset logs backup --json`; any `~/forms/fieldset config set` that changes a setting renders its configuration again. |
| `backup_files_missing` | The newest backup misses files the database references (its `manifest.json` lists them). | Check the storage volume; report to the person. |
| `backup_overdue` | No backup succeeded within 2 hours of the scheduled slot. | `~/forms/fieldset logs backup --json` and `~/forms/fieldset doctor --post --json`, then `~/forms/fieldset backup --json`. |
| `backup_partial_left` | An interrupted backup left a partial directory that may hold unencrypted data. | The next run removes it; never copy it off the host. |

Check that the backup restores, without touching the instance (a drill). `<backup>` is `data.backup` from above:

```sh runnable
~/forms/fieldset restore <backup> --drill --json
```

```json expect
{"exit_code": 0, "status": "ok", "data": {"backup": "<string>", "dropped": true, "storage": "<object>"}}
```

Restore it into the instance. This replaces the instance's data with the backup's, so ask the person first. `fieldset`
takes a safety backup of the current data before it starts:

```sh runnable
~/forms/fieldset restore <backup> --yes --json
```

```json expect
{"exit_code": 0, "status": "ok", "data": {"backup": "<string>", "epoch": "<int>", "previous_epoch": "<int>", "safety_backup": "<string>", "webhooks": "paused"}}
```

After a restore, webhooks stay paused so that other systems are not notified twice. When the person confirms, turn
them on again:

```sh runnable
~/forms/fieldset config set webhooks on --json
```

```json expect
{"exit_code": 0, "status": "ok", "data": {"key": "webhooks", "to": "on"}}
```

Notes:

- `data.epoch`: a restore starts a new epoch of the audit log's hash chain (see [backup and restore](https://github.com/beyerenrico/fieldset/blob/main/docs/guide/backup-restore.md)).
- Encrypted backups (`fieldset config set backup-encryption on`) restore with `--identity-file <file>` and
  `--passphrase-file <file>`.

Failures:

- Exit 7 `backup_newer`: the backup comes from a newer version. Upgrade first. Exit 7 `backup_too_old`: the backup is
  older than the oldest version this release upgrades from; restore it into an instance of an intermediate release
  first. Nothing changed.
- Exit 2 `backup_not_found`, `backup_not_visible` (the path lies outside the instance directory, whether it exists or
  not: `fieldset restore` sees only that directory; copy the backup into `~/forms/backups/` and restore it by name), `not_an_app_backup` (the dump is not a backup of this app),
  `backup_unauthenticated` (the backup's signature does not match this instance's APP key: pass the key of the instance
  that made it with `--app-key-file`, or `--accept-unauthenticated` if the person accepts an unverified backup),
  `identity_required` (an encrypted backup), `path_missing` (the directory of the `--identity-file` does not exist),
  `identity_unreadable` (the `--identity-file` is missing or not a regular file), `decrypt_failed` (wrong identity or
  passphrase). Nothing changed.
- Exit 3 `low_disk_space`: free space; a restore needs room for one more copy.
- Exit 5: the restore failed and the instance runs on its previous data again. `errors[0].code` is the cause, for
  example `pg_restore_failed`, `restore_database_failed` or `storage_restore_failed`; their hint says whether the live
  database or storage volume was touched. A drill reports the same codes with exit 4 (the instance is never touched).
  Exit 8: see the recovery object.

## Recipe: upgrade

`fieldset upgrade` takes a safety backup, pulls the new release, migrates, checks the new version's health, and rolls back
by itself when the check fails. Without `--to` it upgrades to the newest stable release:

```sh
~/forms/fieldset upgrade --json
```

Upgrade to a named version (`<version>`, for example `2.0.1`):

```sh runnable
~/forms/fieldset upgrade --to <version> --json
```

```json expect
{"exit_code": 0, "status": "ok", "data": {"from": "<string>", "to": "<string>"}}
```

- `data.current: true`: the instance already runs that version; nothing changed.
- After a real upgrade: `data.safety_backup`, `data.migrated` and `data.notes_url` (the release notes).
- `~/forms/fieldset version --json` shows the running version in `data.instance`. Its `data.newest_known` is always
  `null`: the update check runs in the app.
- Update check: `~/forms/fieldset config set update-check on --json` (or `off`) lets the app read the newest release's
  manifest once a day; the result appears only on the `/platform` instance page. `~/forms/fieldset config show --json` lists
  what it contacts under `data.outbound_contacts`.

The upgrade runs in a background container (the supervisor), so it goes on when your shell loses it (a closed SSH
session, a timeout). Do not start it again: that answers exit 9 `supervisor_running`, and other commands answer
`instance_locked` while it runs. Instead, `~/forms/fieldset upgrade --follow --json` attaches to it and returns its result
(or the last upgrade's result once it finished). `~/forms/fieldset status --json` shows `data.supervisor.running` and
`data.last_upgrade`. When `fieldset status` reports an unfinished upgrade, run `~/forms/fieldset upgrade --resume --json` only
once `data.supervisor.running` is `false`.

"The last upgrade" is the last `fieldset upgrade` run, even one that changed nothing: once an upgrade finished, running
`fieldset upgrade` again (it answers `current: true`) replaces it, and from then on `--follow` and `data.last_upgrade` show
that run, not the real upgrade. Keep the real upgrade's own result, and use `~/forms/fieldset version --json`
(`data.instance`) to see which version runs.

Failures:

- Exit 7 `major_upgrade`: a new major version. Read its release notes with the person, then add `--major`.
  `downgrade_refused`, `upgrade_path` (`data.path` lists the versions to go through one by one).
- Exit 9 `drain_timeout`: queued jobs did not finish in time. Run again later, or add `--drop-jobs` if the person
  accepts losing them.
- Exit 5 (`target_unhealthy` and others): the new version failed its checks and the instance runs the previous one
  again. `data.logs` holds the new version's log lines. Report them; do not retry blindly.
- Exit 8 `upgrade_rollback_failed`: run `~/forms/fieldset upgrade --resume --json` (`data.next`).
- Going back after a successful upgrade: `~/forms/fieldset rollback --yes --json` restores the upgrade's safety backup
  into the previous version. Data written since the upgrade is lost: `data.loss` in the confirmation (exit 6) says
  how much.

## Recipe: move to another server

Move an instance with all its data to a new host: an encrypted backup with the instance's secrets, the files copied
over, an install from that backup on the new host.

1. On the old host, note the version the instance runs: `data.instance` (`<old-version>` in step 3). The new host must
   install the same version (or a newer one).

   ```sh runnable
   ~/forms/fieldset version --json
   ```

   ```json expect
   {"exit_code": 0, "status": "ok", "data": {"instance": "<string>"}}
   ```

   Create a file with a passphrase of at least 12 characters, readable only by you (for example
   `~/forms-passphrase`). Then take an encrypted backup that includes the secrets. The first `--encrypt` turns
   encryption on and writes the decryption identity to `--identity-out`, outside the instance directory:

   ```sh runnable
   ~/forms/fieldset backup --encrypt --include-secrets --identity-out ~/forms-identity.age --passphrase-file ~/forms-passphrase --json
   ```

   ```json expect
   {"exit_code": 0, "status": "ok", "data": {"backup": "<string>", "path": "<string>", "encrypted": true, "includes_secrets": true}}
   ```

2. Collect what the new host needs: the backup file (`<backup-path>` is `data.path` from step 1), the identity, and
   the instance's APP key lines (they prove the backup is authentic). Copy only the `APP_KEY` and `APP_PREVIOUS_KEYS`
   lines of `env/app.env`, never the whole file: it also holds the database and SMTP passwords. An instance whose key
   was never rotated has no `APP_PREVIOUS_KEYS` line; `APP_KEY` alone is enough then. `umask 077` makes the copies
   readable only by you, and the command can run again:

   ```sh runnable
   mkdir -p -m 700 ~/forms-move && umask 077 && cp <backup-path>/backup.tar.age ~/forms-move/backup.tar.age && cp ~/forms-identity.age ~/forms-move/identity.age && grep -e '^APP_KEY=' -e '^APP_PREVIOUS_KEYS=' ~/forms/env/app.env > ~/forms-move/app-key.env
   ```

   ```json expect
   {"exit_code": 0}
   ```

   Copy the directory to the new host, for example `scp -r ~/forms-move new-host:`, and create the passphrase file
   there too. Keep these files private: together they open the backup.

3. On the new host, install from the backup, with `--version <old-version>` from step 1 (a backup restores only into
   the version that made it or a newer one):

   ```sh runnable
   curl --proto '=https' --tlsv1.2 -fsSL https://github.com/beyerenrico/fieldset/releases/latest/download/install.sh | sh -s -- --version <old-version> --dir ~/forms-new --from-backup ~/forms-move/backup.tar.age --identity-file ~/forms-move/identity.age --passphrase-file ~/forms-passphrase --app-key-file ~/forms-move/app-key.env --secrets-file ~/forms-new-secrets.json --json --yes
   ```

   ```json expect
   {"exit_code": 0, "status": "ok", "data": {"url": "<string>", "mode": "local", "from_backup": {"authenticated": true, "encrypted": true}, "webhooks": "paused", "confirm_commands": "<list>"}}
   ```

   For a domain, add `--domain <name>` (and `--acme-email <address>`); until the DNS name points to the new host, the
   instance reports "certificate pending". The moved instance starts with captured mail and paused webhooks so that
   nothing goes out twice while the old host still runs. `data.confirm_commands` lists the commands that turn both
   back on (`fieldset config set webhooks on`, `fieldset config set mail smtp …`); run them once the person has switched over,
   with `~/forms-new/fieldset` and `--json`. The mail command carries the old server's host, port, encryption and sender
   address; only two placeholders are left: replace `<account>` with the SMTP user name and `<file>` with a file that
   holds the SMTP password (see [configure SMTP](#recipe-configure-smtp)). A server without a login needs neither:
   leave out `--smtp-username <account> --password-file <file>`.

   If the old instance encrypted its backups before the move, add `--keep-recipients` to the install command: the new
   instance then goes on encrypting its backups to the same identity (keep the identity and passphrase, step 5).
   Without it, the warning `backup_recipients_not_kept` says that new backups are not encrypted until you run
   `~/forms-new/fieldset config set backup-encryption on` there.

4. When the new host works and DNS points to it, remove the old instance. `--keep-backups` keeps its `backups/`
   directory and writes a final backup there first (`data.final_backup`):

   ```sh runnable
   ~/forms/fieldset uninstall --keep-backups --yes --json
   ```

   ```json expect
   {"exit_code": 0, "status": "ok", "data": {"removed": {"containers": "<list>", "networks": "<list>", "volumes": "<list>"}, "kept": ["backups"], "final_backup": "<object>"}}
   ```

5. Once the move works, ask the person whether to delete the move's files on both hosts: `~/forms-move`, the
   identity `~/forms-identity.age` and the passphrase file `~/forms-passphrase`. Every encrypted backup of the old
   instance (its final backup, copies off the host) opens only with that identity and passphrase, and with
   `--keep-recipients` (step 3) so do the new instance's backups. The person may want to keep both in a safe place
   outside the hosts instead:

   ```sh
   rm -r ~/forms-move && rm ~/forms-identity.age ~/forms-passphrase
   ```

Failures of step 3: exit 2 `decrypt_failed` (wrong identity or passphrase), `path_missing` (the identity's directory
does not exist), `identity_unreadable` (no identity file at that path), `backup_unauthenticated` (wrong or
missing `--app-key-file`), exit 7 `backup_newer` (the new host installs an older version than the old host runs: pass
`--version <old-version>`; ignore the hint's `fieldset upgrade`, which is for the old host). Each leaves `~/forms-new`
behind: fix the cause and run the install command again (see [a failed install](#a-failed-install)).

## Recipe: VPS install

Goal: a public instance with HTTPS on a server with a domain. Prerequisites: the domain's A (and AAAA, if any) record
points to the server, and ports 80 and 443 are free and reachable. Run as root; as another user, pipe into `sudo sh`
instead of `sh`:

```sh
curl --proto '=https' --tlsv1.2 -fsSL https://github.com/beyerenrico/fieldset/releases/latest/download/install.sh | sudo sh -s -- --dir /opt/fieldset --domain forms.example.com --acme-email ops@example.com --org "Example Ltd" --owner-email owner@example.com --secrets-file /root/forms-secrets.json --json --yes
```

Expect exit 0 and `data.mode` `domain`, `data.url` `https://forms.example.com`, `data.sign_in_link.delivered`
`secrets_file`. Then configure SMTP (recipe above) with `/opt/fieldset/fieldset`.

- `--acme-staging` uses Let's Encrypt's staging CA for a first try (the browser will not trust the certificate);
  `/opt/fieldset/fieldset config set acme-ca production --json` switches to real certificates afterwards.
- Behind an existing reverse proxy, use `--behind-proxy traefik|caddy|port` (with `--url` and, for `port`, `--port`)
  instead of `--domain`; see the [install guide](https://github.com/beyerenrico/fieldset/blob/main/docs/guide/install.md).
- SMTP can be set during the install with the same `--smtp-*`, `--password-file` and `--from-address` flags.

Failures: exit 3 `doctor_failed` with the check `dns` (the name does not point here yet: wait for DNS) or a port check
(80 or 443 in use); exit 4: a service did not become healthy (read `/opt/fieldset/fieldset logs edge --json` for
the certificate, `… logs app --json` for the application, then run the same command again; it resumes). A failed
install leaves `/opt/fieldset` behind: run the same command again once the cause is fixed, or see
[a failed install](#a-failed-install).

## Recipe: phone demo

Goal: try the Field App on a phone in the same network. Phones need HTTPS for the app's offline features. Two ways:

**Local certificate authority** (phones in the same Wi-Fi):

```sh
~/forms/fieldset config set phone-https local-ca --yes --json
```

Or at install: add `--phone-https local-ca` (with `--lan-address <IPv4>` when the computer has several addresses).
It changes the instance's URL to `https://<LAN address>` (hence `--yes`), adds an edge container that serves ports 80
and 443 on that address, and restarts the services whose configuration changes (`data.services`). Read
`data.phone_https.url` (the HTTPS address for the phone), `data.phone_https.trust_url` (a page the phone opens first,
with the CA certificate and instructions) and `data.phone_https.root_sha256` (to compare on the phone). The person
installs and trusts the certificate on the phone, then opens `url`. `~/forms/fieldset config set phone-https off --yes
--json` ends it.

Failures: exit 3 `doctor_failed` (port 80 or 443 is in use on the LAN address; `data.checks`); exit 3
`default_password_in_use` (demo accounts still use the documented password: run `~/forms/fieldset demo --rotate-passwords
--json --secrets-file ~/forms-secrets.json` first; other accounts on that password: change it); exit 2
`lan_address_required` (`~/forms/fieldset config set lan-address <IPv4> --json` first); exit 5: the edge did not pass its
check and the instance is back on its previous URL (`data.fix_first` or `data.retry_after`, see the recovery object).

**Quick tunnel** (a public `trycloudflare.com` address, no certificate to install; it expires):

```sh
~/forms/fieldset tunnel start --yes --json
```

Read `data.url` and `data.tunnel.expires_at`. `--yes` is needed when the instance holds organizations other than the
demo, because their data becomes reachable from the internet. Through the tunnel only the Field App, the API and
sign-in are reachable; `--tunnel-admin` opens the admin dashboard (`/admin`) there too, so pass it only when the person
asks for it. Demo accounts still on the documented password get random ones first (under `secrets`, warning
`demo_passwords_rotated`; pass `--secrets-file`). `fieldset tunnel renew` extends it, `fieldset tunnel stop` ends it. The tunnel
only works in local mode and not together with the local CA (exit 2 `invalid_combination`). Exit 3
`default_password_in_use`: an account that is not a demo account still uses the documented password; change it first.

## Recipe: change the domain

Move an instance to another domain name, or a local instance to a domain. Point the new name's DNS record to the
server first.

```sh
/opt/fieldset/fieldset config set domain new.example.com --yes --json
```

Without `--yes` the command exits 6: `data.question` is the question to show the person, and `data` describes the
change (`from`, `to`, `change`, `impact`). On success, `data.url` is the new URL and `data.previous.url` the old one.
Old links (sign-in links, links in sent mail) stop working.

Failures: exit 3 `default_password_in_use` (a local instance whose demo accounts still use the documented password
`password` would become reachable from the internet: run `/opt/fieldset/fieldset demo --rotate-passwords --json
--secrets-file <file>` first, which writes the new passwords to that file, then the command again; for an account that
is not a demo account, change its password); exit 3 `doctor_failed` (DNS does not point here, or ports 80/443 are
busy; `data.checks`); exit 5
`certificate_failed` (no certificate could be obtained: the instance keeps its old domain; `data.fix_first` says what
to fix). Back to local mode: `fieldset config set mode local --yes`.

## Recipe: Windows (WSL 2)

Fieldset runs on Windows inside a WSL 2 distro, with Docker Desktop's WSL integration or Docker Engine in the distro.
Run every command in the distro's shell, never in PowerShell or Git Bash; the recipes above work unchanged there, with
the instance in the distro's home (`~/forms`). Two steps are the person's, on Windows: checking WSL 2 in PowerShell
(`wsl -l -v`, the `VERSION` column says 2) and turning on Docker Desktop's WSL integration for the distro (Settings >
Resources > WSL integration).

Refusals before anything is created:

- Exit 3 `wsl1_unsupported`: the distro runs WSL 1. The hint names `wsl --set-version <distro> 2`; the person runs it in
  PowerShell.
- Exit 2 `path_on_windows_drive`: `--dir`, or a link on its path, is on a Windows drive (`/mnt/c/…`). Use a directory in
  the distro's home. Backups and file flags may point to a Windows drive.
- Exit 10 `docker_missing` ("Docker is not available in this WSL distro": the WSL integration is off, or no Docker is
  installed) or `docker_unavailable` ("Start Docker Desktop on Windows"). Ask the person to fix it, then run the same
  command again.

On WSL 2 with interop, `--open` hands the sign-in link to the Windows browser and the shim prints it to the terminal
too; without interop no browser opens. Prefer `--secrets-file` as everywhere.

Phones: `~/forms/fieldset config set phone-https local-ca --yes --json` (or `--phone-https local-ca` at install) uses the
Windows PC's LAN address. Windows decides whether phones get through, so check it:

```sh
~/forms/fieldset doctor --json
```

Two checks run only on WSL 2 (`skipped` elsewhere). Their `details`:

| Check | `details` | Statuses |
|---|---|---|
| `windows_network` (with the local CA only) | `address` (the certificate's address), `setup` (`desktop`, `engine-nat`, `engine-mirrored` or `unknown`), `facts` (what fieldset learned from Windows: `full`, `addresses`, `unavailable` or `skipped`), `row` (the problem found: `public_profile`, `desktop_local_only`, `facts_unknown`, `host_rule`, `portproxy`, `hyperv_rule`, or `null`), `commands` (copy-ready lines), `unknown` (facts Windows did not report); `listeners` when another program uses port 80 or 443, `candidates` with `lan_address_changed` | `ok`; `fail` with `commands` (a warning in install's preflight, so the install completes and its result carries them); `unknown` with the warning `windows_facts_unknown`; `warn` `lan_address_changed` (the certificate's address is no longer Windows': run `~/forms/fieldset config set lan-address <address> --yes --json` with the address the hint names) |
| `wsl_lifecycle` | `backend` (`desktop` or `engine`), `instance_idle_timeout` and `vm_idle_timeout` (milliseconds or `null`), `wslconfig` (`read` or `unknown`), `systemd` (`true`, `false` or `null`); `idle_distro: unknown` on Docker Desktop | `ok`; `warn` on Docker Engine when the distro stops without a terminal or Docker does not start with it (the hint has the `.wslconfig` and `/etc/wsl.conf` lines); `unknown` with the warning `wsl_lifecycle_unknown` |

`details.commands` are PowerShell lines for the person to run as administrator on Windows. Show them with the `summary`
and the `hint`; never run them yourself, and never change Windows settings. Then run `~/forms/fieldset doctor --json`
again. On WSL 2 the `lan_address` check is skipped: `windows_network` compares the address instead.

`~/forms/fieldset uninstall --yes --json` on WSL 2 with the local CA adds `data.windows_cleanup`, the lines that remove
the rules the doctor suggested, and the warning `windows_rules`. Show both to the person.

## Recipe: repair ownership

When Docker starts to map user IDs differently than when the instance was set up (rootful Docker became rootless, the
instance directory moved to WSL 2 or another host), `fieldset doctor --json` fails its `permissions` check (exit 3
`doctor_failed`) with `details.owner_mismatch: true`. `details.saved_owner_uid` is the owner saved with the instance,
`details.measured_owner_uid` the one measured just now (both as containers see the files), and `details.owner_source`
says who measured: `measured` (a container, just now), `saved` (state.json) or `rules` (the measurement could not run).
With `rules` the check is `unknown` (a warning, never a mismatch): run `fieldset doctor` again once Docker can start
containers. A mismatch's hint names the repair; ask the person first:

```sh
~/forms/fieldset repair-ownership --yes --json
```

It gives the instance files to the measured owner (owners only: modes, symlinks, hard-linked files and Docker volumes
stay as they are), saves that owner in state.json and recreates the backup service for it. `data.owner` is `{from, to,
group, runtime, source}`, `data.files_changed` how many entries got the new owner, `data.backup_service` `recreated`
(or `unchanged` when nothing was to do) and `data.message` a sentence for the person. When the saved and the measured
owner already match it changes nothing (`changed: false`). Then run `~/forms/fieldset doctor --json` again.

Without `--yes` the command exits 6 and `data` names both owners (`data.owner`). Failures: exit 3 `owner_not_measured`
(the owner could not be measured in this run: run it again through `<dir>/fieldset` once Docker can start containers);
exit 4 `owner_repair_failed` or `services_unhealthy` (the saved owner is unchanged, `retryable: true`: fix the cause the
message names, then run the same command again); exit 4 `secrets_exposed` (an env file is no longer mode 0600: report
it, the hint names the `chmod 600`); exit 9 `instance_locked` or `operation_unfinished`.

The launcher itself refuses a host it cannot run on, for every command: exit 3 `owner_mapping_unknown` (containers see
the person's files as a third user ID, as with Docker's user-namespace remapping; both IDs are in the message) and exit
3 `socket_no_group_access` (the Docker socket grants its group no write access inside containers). Report them; the
hint names the Docker setup that works.

## Recipe: remove an instance

Ask the person first. Keep the backups unless they say otherwise:

```sh
~/forms/fieldset uninstall --keep-backups --yes --json
```

It removes the instance's containers, volumes, networks and directory, but writes a final backup into `backups/` first
(`data.final_backup`) and keeps `backups/`: afterwards `~/forms` holds only `backups/`. On an instance without backup
encryption the final backup is not encrypted, and the APP keys that authenticate it are written beside it in plain
text (mode 0600) as `backups/final-<UTC time>.app-key.env` (warning `app_key_beside_backup`); keep that file as safe as
the backup. An encrypted final backup carries the keys inside (warning `final_backup_encrypted`). The warning's hint
is the exact command that brings the instance back, for example:

```sh
curl --proto '=https' --tlsv1.2 -fsSL https://github.com/beyerenrico/fieldset/releases/latest/download/install.sh | sh -s -- --dir ~/forms --from-backup ~/forms/backups/final-<UTC time> --app-key-file ~/forms/backups/final-<UTC time>.app-key.env --json --yes
```

Only if the person explicitly wants the backups deleted too:

```sh
~/forms/fieldset uninstall --yes --json
```

Without `--yes` the command exits 6 and `data` names what would be deleted (`final_backup`, `keeps_backups`).

## How this guide is tested

Fenced blocks marked `sh runnable` are executed by the journey `tests/Install/Journeys/J11AgentGuideTest.php` against a
locally built release, in the order they appear; each is followed by a `json expect` block. The test checks the exit
code, and that every field of the expect block exists in the envelope: a placeholder such as `"<string>"`, `"<int>"`,
`"<list>"` or `"<object>"` stands for any value of that type, every other value must match exactly. Blocks marked only
`sh` need a domain, DNS, a phone or the internet, or would remove what later blocks use, and are not executed.
Placeholders in angle brackets (`<backup>`, `<backup-path>`, `<version>`, `<old-version>`) are values from earlier
results, as the text says.
