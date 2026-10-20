# Agent guide: running form-schema-app with `fsa`

This guide is for an AI agent (or a script) that installs and operates a form-schema-app instance for a person. Every
recipe gives the exact commands, the JSON fields to read and what to do when a step fails. You need a shell on the
host, Docker (Engine 24+ or Docker Desktop) and `curl`. You do not need the repository.

The same guide ships with every release as the asset `agent-guide.md` and lives in the repository at
`docs/guide/agent-guide.md`. People who prefer prose read the [install guide](install.md).

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
  [remove an instance](#recipe-remove-an-instance)
- [How this guide is tested](#how-this-guide-is-tested)

## Rules for agents

1. **Always pass `--json`.** stdout then carries exactly one JSON document (the envelope below); stderr carries
   progress as one JSON object per line. Parse stdout, never the progress lines.
2. **Branch on the exit code and `errors[0].code`,** never on message text. Messages and hints are for people.
3. **Pass `--yes` only for a step the person asked for.** Destructive commands (`uninstall`, `restore`, `rollback`,
   `reset`, `adopt`, and `config set` of a key that changes the URL) exit with 6 `confirmation_required` without it,
   and `data` then holds the question and the facts it is about. Show those to the person, then run the command again
   with `--yes`.
4. **Never put a secret on the command line.** `fsa` refuses any flag whose name says password, passphrase, secret,
   token or key (exit 2 `secret_in_argument`). Secrets go in through files (`--password-file`, `--passphrase-file`,
   `--app-key-file`) or stdin (`--password-stdin`, `--passphrase-stdin`).
5. **Never print a secret.** One-time secrets (the sign-in link, random demo passwords, the mail catcher's password)
   appear only under `secrets`. Pass `--secrets-file <file>` and `fsa` writes them to that file (mode 0600) instead;
   the envelope then names the file in `secrets[].written_to`. Hand the file, or its content, to the person; do not
   echo it into a chat log.
6. **Run commands of an instance through its own shim:** `<dir>/fsa <command> …`, for example `~/forms/fsa status
   --json`. `install.sh` creates the shim. With `--link` (or by answering its question) `install.sh` also links
   `fsa` into your PATH; this guide always writes the full path.
7. **A command that stopped half-way is resumed, not repeated by hand.** See exit 9 below.

Every command lists its flags with `<dir>/fsa <command> --help`.

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
| 1 | Internal error | Report `errors[0]` to the person. Run `<dir>/fsa status --json` to see what changed. |
| 2 | Invalid input (a flag, a value, a missing file) | Fix the input named in `errors[0]`. Nothing changed. |
| 3 | Preflight failed (Docker, disk, ports, DNS, release download) | `data.checks` (from the doctor) names each failed check with a `hint`. Fix it and run the command again. Nothing changed. |
| 4 | Health check failed | The command changed things and could not finish them. Read `errors[0].hint` and `<dir>/fsa logs app --json`. |
| 5 | Rolled back | The instance is back where it was. `errors[0].code` is the cause. See below. |
| 6 | Confirmation required | Show `data.question` and the other `data` facts to the person; run again with `--yes` if they agree. |
| 7 | Incompatible (version, schema, another instance's data) | Nothing changed. `errors[0].hint` names the way forward (for example `--to <version>` or `--major`). |
| 8 | Rollback failed: the instance is stopped behind its maintenance gate | Follow the recovery object below exactly; do not improvise. |
| 9 | Aborted, nothing changed (lock held, unfinished operation, cancelled) | See below. |
| 10 | Docker unavailable | Start Docker (or fix access to its socket) and run the command again. |

### The recovery object

When a command cannot finish, the envelope tells you how to continue. Read these fields in this order:

1. **Exit 9 `operation_unfinished`:** an earlier command stopped half-way. `data.command` is the exact command line that
   resumes it and `data.options` its flags (`data.operation` and `data.last_phase` say what stopped where). Run
   `data.command` with `--json` (plus `--yes` if the person agrees, and the same secret files it names). Every other
   changing command answers this exit until the operation is finished.
2. **Exit 9 `instance_locked`:** another `fsa` command (or a scheduled backup) is running. Wait and run again.
3. **Exit 5 (rolled back):** `data.retry_after` (seconds) means a passing problem: wait, then run the same command
   again. `data.fix_first` means fix that first. `data.command` and `data.options`, when present, are the command to
   repeat. `fsa config set` and `fsa tunnel` fill all of these; `fsa upgrade`, `fsa restore` and `fsa reset` name the
   cause in `errors[0]` and the safety backup in `data.safety_backup`.
4. **Exit 8 (rollback failed):** `errors[0].hint` is the complete instruction. `data.next` adds a machine-readable
   part, and its form depends on the command:
   - `fsa upgrade`: `data.next` is the command to run: `fsa upgrade --resume`.
   - `fsa rollback`: `data.next` is `fsa rollback`.
   - `fsa restore`: `data.next` is a kind: `abort` (nothing was swapped), `return` (going back to the previous data),
     `forward` or `no_way_back` (continuing with the restored data). In every case the command is
     `<dir>/fsa restore --abandon --yes --json`; the kind tells the person what they will end up with.
   - `fsa config set` and `fsa tunnel`: `data.recovery` lists the steps; running the same command again finishes the
     revert.

   `data.safety_backup`, when present, names the backup taken before the operation. If the recovery command fails
   too, the last resort in the hint is to install that backup into a new directory with `install.sh --dir <new dir>
   --from-backup <backup>`.

## Recipe: local demo

Goal: a demo instance on this computer, filled with sample data, mail caught by the instance's mail catcher.

```sh runnable
curl --proto '=https' --tlsv1.2 -fsSL https://github.com/beyerenrico/form-schema-app/releases/latest/download/install.sh | sh -s -- --dir ~/forms --org "Example Ltd" --owner-email owner@example.com --demo --secrets-file ~/forms-secrets.json --json --yes
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
  `<url>/app`.
- `secrets[0].written_to`: the file with the owner's one-time sign-in link (valid 15 minutes, one use). Give the link
  to the person; it leads to "set a password".
- `data.demo.users`: the demo accounts. On a loopback-only instance their password is `data.demo.default_password`
  (`password`); when the instance is reachable from other machines, each account gets a random password instead, under
  `secrets` as `demo_password:<email>`.
- `data.mail.catcher_url`: the mail catcher, where every mail of the instance lands. `data.mail.catcher_login_command`
  prints its login into a secrets file.

On a computer with a desktop, `--open` hands the sign-in link to the browser instead of a file
(`data.sign_in_link.delivered` is then `browser`). A demo on an instance that is already installed:
`~/forms/fsa demo --json --secrets-file ~/forms-secrets.json`.

Failures:

- Exit 3 `doctor_failed`: `data.checks` names the failed check, often a port in use. `--port <n>` picks another one.
- Exit 7 `project_conflict`: another instance uses the same directory name. Pick another `--dir`.
- Exit 10 `docker_missing` or `docker_unavailable`: install or start Docker.
- Exit 4 (`services_unhealthy`, `migrate_failed`, …): read `~/forms/fsa logs app --json`, then run the same install
  command again; it resumes.

## Recipe: sign-in links

Check that the instance is healthy, then issue a new sign-in link for an admin, for example when the first one expired.

```sh runnable
~/forms/fsa status --json
```

```json expect
{"exit_code": 0, "status": "ok", "data": {"installed": true, "health": "ok", "mode": "local", "url": "<string>", "services": "<object>", "version": "<string>"}}
```

`data.health` is `ok`, `degraded` (a service is unhealthy: `data.services` says which), `stopped` or `not_started`.
`data.operation` is not null while an operation is unfinished (see exit 9).

```sh runnable
~/forms/fsa login-link owner@example.com --secrets-file ~/forms-link.json --json
```

```json expect
{"exit_code": 0, "status": "ok", "data": {"email": "owner@example.com", "sign_in_link": {"delivered": "secrets_file", "expires_at": "<string>"}}}
```

The link is in `~/forms-link.json` (`secrets[0].value` of that file). Failures: exit 2 `user_not_found`, `not_admin`,
`inactive` or `sso_required` (the person signs in through single sign-on instead); exit 9 `rate_limited` with a
retryable error: wait a minute.

## Recipe: configure SMTP

A new instance captures mail: invitations and password resets stay in the mail catcher and reach nobody, and the admin
dashboard shows a "mail is captured" banner. Switch to the person's SMTP server. Put the SMTP password in a file only
the person can read (for example `~/smtp-password`), never on the command line.

```sh runnable
~/forms/fsa config set mail smtp --smtp-host smtp.example.com --smtp-port 587 --smtp-username forms@example.com --password-file ~/smtp-password --from-address forms@example.com --test-to owner@example.com --json
```

```json expect
{"exit_code": 0, "status": "ok", "data": {"key": "mail", "from": "capture", "to": "<string>", "mail": {"mode": "smtp"}, "checked": "<list>"}}
```

`fsa` sends a test mail to `--test-to` through the new server before it keeps the change; `data.checked` lists what
it verified and `data.to` describes the new mail setup. The default encryption is
STARTTLS on port 587 (`--smtp-encryption tls` for port 465, `none` only for a relay inside a trusted network). The
password can also come from stdin: `printf '%s\n' "$smtp_password" | ~/forms/fsa config set mail smtp … --password-stdin --json`.
The mail catcher keeps running for old mail; `--remove-catcher` removes it. `fsa config set mail capture` switches
back.

Failures:

- Exit 5 `test_mail_failed`: the server refused the mail or the login; the instance still captures mail. The message
  says what the server answered. Fix host, port, user, password or encryption and run again.
- Exit 2 `password_required`: `--smtp-username` without `--password-file` or `--password-stdin`.

## Recipe: backup and restore

The instance backs up daily at 03:30 (its time zone) into `<dir>/backups/` and keeps the newest 7 scheduled backups.
Take a backup now:

```sh runnable
~/forms/fsa backup --json
```

```json expect
{"exit_code": 0, "status": "ok", "data": {"backup": "<string>", "path": "<string>", "kind": "manual", "encrypted": false, "bytes": "<int>"}}
```

`data.backup` is the backup's name (`manual-<UTC time>`), `data.path` its directory. The warning `backup_on_host`
reminds you that a backup on the same disk is no backup: copy `data.path` to another machine.

Check that the backup restores, without touching the instance (a drill). `<backup>` is `data.backup` from above:

```sh runnable
~/forms/fsa restore <backup> --drill --json
```

```json expect
{"exit_code": 0, "status": "ok", "data": {"backup": "<string>", "dropped": true, "storage": "<object>"}}
```

Restore it into the instance. This replaces the instance's data with the backup's, so ask the person first. `fsa`
takes a safety backup of the current data before it starts:

```sh runnable
~/forms/fsa restore <backup> --yes --json
```

```json expect
{"exit_code": 0, "status": "ok", "data": {"backup": "<string>", "epoch": "<int>", "previous_epoch": "<int>", "safety_backup": "<string>", "webhooks": "paused"}}
```

After a restore, webhooks stay paused so that other systems are not notified twice. When the person confirms, turn
them on again:

```sh runnable
~/forms/fsa config set webhooks on --json
```

```json expect
{"exit_code": 0, "status": "ok", "data": {"key": "webhooks", "to": "on"}}
```

Notes:

- `data.epoch`: a restore starts a new epoch of the audit log's hash chain (see [backup and restore](backup-restore.md)).
- Encrypted backups (`fsa config set backup-encryption on`) restore with `--identity-file <file>` and
  `--passphrase-file <file>`.

Failures:

- Exit 7 `backup_newer`: the backup comes from a newer version. Upgrade first.
- Exit 2 `backup_not_found`, `backup_unauthenticated` (the backup's signature does not match this instance's APP key:
  pass the key of the instance that made it with `--app-key-file`, or `--accept-unauthenticated` if the person accepts
  an unverified backup), `identity_required` (an encrypted backup).
- Exit 3 `low_disk_space`: free space; a restore needs room for one more copy.
- Exit 5: the restore failed and the instance runs on its previous data again. Exit 8: see the recovery object.

## Recipe: upgrade

`fsa upgrade` takes a safety backup, pulls the new release, migrates, checks the new version's health, and rolls back
by itself when the check fails. Without `--to` it upgrades to the newest stable release:

```sh
~/forms/fsa upgrade --json
```

Upgrade to a named version (`<version>`, for example `2.0.1`):

```sh runnable
~/forms/fsa upgrade --to <version> --json
```

```json expect
{"exit_code": 0, "status": "ok", "data": {"from": "<string>", "to": "<string>"}}
```

- `data.current: true`: the instance already runs that version; nothing changed.
- After a real upgrade: `data.safety_backup`, `data.migrated` and `data.notes_url` (the release notes).
- `~/forms/fsa version --json` shows the running version in `data.instance`.

Failures:

- Exit 7 `major_upgrade`: a new major version. Read its release notes with the person, then add `--major`.
  `downgrade_refused`, `upgrade_path` (`data.path` lists the versions to go through one by one).
- Exit 9 `drain_timeout`: queued jobs did not finish in time. Run again later, or add `--drop-jobs` if the person
  accepts losing them.
- Exit 5 (`target_unhealthy` and others): the new version failed its checks and the instance runs the previous one
  again. `data.logs` holds the new version's log lines. Report them; do not retry blindly.
- Exit 8 `upgrade_rollback_failed`: run `~/forms/fsa upgrade --resume --json` (`data.next`).
- Going back after a successful upgrade: `~/forms/fsa rollback --yes --json` restores the upgrade's safety backup
  into the previous version. Data written since the upgrade is lost: `data.loss` in the confirmation (exit 6) says
  how much.

## Recipe: move to another server

Move an instance with all its data to a new host: an encrypted backup with the instance's secrets, the files copied
over, an install from that backup on the new host.

1. On the old host, create a file with a passphrase of at least 12 characters, readable only by you (for example
   `~/forms-passphrase`). Then take an encrypted backup that includes the secrets. The first `--encrypt` turns
   encryption on and writes the decryption identity to `--identity-out`, outside the instance directory:

   ```sh runnable
   ~/forms/fsa backup --encrypt --include-secrets --identity-out ~/forms-identity.age --passphrase-file ~/forms-passphrase --json
   ```

   ```json expect
   {"exit_code": 0, "status": "ok", "data": {"backup": "<string>", "path": "<string>", "encrypted": true, "includes_secrets": true}}
   ```

2. Collect what the new host needs: the backup file (`<backup-path>` is `data.path` from step 1), the identity, and
   the instance's APP key file (it proves the backup is authentic):

   ```sh runnable
   mkdir -m 700 ~/forms-move && cp <backup-path>/backup.tar.age ~/forms-move/backup.tar.age && cp ~/forms-identity.age ~/forms-move/identity.age && cp ~/forms/env/app.env ~/forms-move/app-key.env
   ```

   ```json expect
   {"exit_code": 0}
   ```

   Copy the directory to the new host, for example `scp -r ~/forms-move new-host:`, and create the passphrase file
   there too. Keep these files private: together they open the backup.

3. On the new host, install from the backup:

   ```sh runnable
   curl --proto '=https' --tlsv1.2 -fsSL https://github.com/beyerenrico/form-schema-app/releases/latest/download/install.sh | sh -s -- --dir ~/forms-new --from-backup ~/forms-move/backup.tar.age --identity-file ~/forms-move/identity.age --passphrase-file ~/forms-passphrase --app-key-file ~/forms-move/app-key.env --secrets-file ~/forms-new-secrets.json --json --yes
   ```

   ```json expect
   {"exit_code": 0, "status": "ok", "data": {"url": "<string>", "mode": "local", "from_backup": {"authenticated": true, "encrypted": true}, "webhooks": "paused", "confirm_commands": "<list>"}}
   ```

   For a domain, add `--domain <name>` (and `--acme-email <address>`); until the DNS name points to the new host, the
   instance reports "certificate pending". The moved instance starts with captured mail and paused webhooks so that
   nothing goes out twice while the old host still runs. `data.confirm_commands` lists the commands that turn both
   back on (`fsa config set webhooks on`, `fsa config set mail smtp …`); run them once the person has switched over.

4. When the new host works and DNS points to it, remove the old instance. `--keep-backups` keeps its `backups/`
   directory and writes a final backup there first (`data.final_backup`):

   ```sh runnable
   ~/forms/fsa uninstall --keep-backups --yes --json
   ```

   ```json expect
   {"exit_code": 0, "status": "ok", "data": {"removed": {"containers": "<list>", "networks": "<list>", "volumes": "<list>"}, "kept": ["backups"], "final_backup": "<object>"}}
   ```

Failures of step 3: exit 2 `decrypt_failed` (wrong identity or passphrase), `backup_unauthenticated` (wrong or
missing `--app-key-file`), exit 7 `backup_newer` (install the same or a newer version on the new host: `--version`).
`--keep-recipients` keeps the old instance's backup encryption key on the new one. Without it, the warning
`backup_recipients_not_kept` says that new backups are not encrypted until you run `fsa config set backup-encryption on`
there.

## Recipe: VPS install

Goal: a public instance with HTTPS on a server with a domain. Prerequisites: the domain's A (and AAAA, if any) record
points to the server, and ports 80 and 443 are free and reachable. Run as root (or with sudo):

```sh
curl --proto '=https' --tlsv1.2 -fsSL https://github.com/beyerenrico/form-schema-app/releases/latest/download/install.sh | sh -s -- --dir /opt/form-schema-app --domain forms.example.com --acme-email ops@example.com --org "Example Ltd" --owner-email owner@example.com --secrets-file /root/forms-secrets.json --json --yes
```

Expect exit 0 and `data.mode` `domain`, `data.url` `https://forms.example.com`, `data.sign_in_link.delivered`
`secrets_file`. Then configure SMTP (recipe above) with `/opt/form-schema-app/fsa`.

- `--acme-staging` uses Let's Encrypt's staging CA for a first try (the browser will not trust the certificate);
  `/opt/form-schema-app/fsa config set acme-ca production --json` switches to real certificates afterwards.
- Behind an existing reverse proxy, use `--behind-proxy traefik|caddy|port` (with `--url` and, for `port`, `--port`)
  instead of `--domain`; see the [install guide](install.md).
- SMTP can be set during the install with the same `--smtp-*`, `--password-file` and `--from-address` flags.

Failures: exit 3 `doctor_failed` with the check `dns` (the name does not point here yet: wait for DNS) or a port check
(80 or 443 in use); exit 4: a service did not become healthy (read `/opt/form-schema-app/fsa logs edge --json` for
the certificate, `… logs app --json` for the application, then run the same command again; it resumes).

## Recipe: phone demo

Goal: try the Field App on a phone in the same network. Phones need HTTPS for the app's offline features. Two ways:

**Local certificate authority** (phones in the same Wi-Fi):

```sh
~/forms/fsa config set phone-https local-ca --yes --json
```

Or at install: add `--phone-https local-ca` (with `--lan-address <IPv4>` when the computer has several addresses).
Read `data.phone_https.url` (the HTTPS address for the phone), `data.phone_https.trust_url` (a page the phone opens
first, with the CA certificate and instructions) and `data.phone_https.root_sha256` (to compare on the phone). The
person installs and trusts the certificate on the phone, then opens `url`. `fsa config set phone-https off` ends it.

**Quick tunnel** (a public `trycloudflare.com` address, no certificate to install; it expires):

```sh
~/forms/fsa tunnel start --yes --json
```

Read `data.url` and `data.tunnel.expires_at`. `--yes` is needed when the instance holds organizations other than the
demo, because their data becomes reachable from the internet. `fsa tunnel renew` extends it, `fsa tunnel stop` ends
it. The tunnel only works in local mode and not together with the local CA (exit 2 `invalid_combination`). Exit 3
`default_password_in_use`: demo accounts still use the documented password; run `~/forms/fsa demo
--rotate-passwords --json --secrets-file ~/forms-secrets.json` first.

## Recipe: change the domain

Move an instance to another domain name, or a local instance to a domain. Point the new name's DNS record to the
server first.

```sh
/opt/form-schema-app/fsa config set domain new.example.com --yes --json
```

Without `--yes` the command exits 6 and `data` describes the change (`from`, `to`, `impact`). On success, `data.url`
is the new URL and `data.previous.url` the old one. Old links (sign-in links, links in sent mail) stop working.

Failures: exit 3 `doctor_failed` (DNS does not point here, or ports 80/443 are busy; `data.checks`); exit 5
`certificate_failed` (no certificate could be obtained: the instance keeps its old domain; `data.fix_first` says what
to fix). Back to local mode: `fsa config set mode local --yes`.

## Recipe: remove an instance

```sh
~/forms/fsa uninstall --yes --json
```

It removes the instance's containers, volumes, networks and directory, backups included, so ask the person first.
`--keep-backups` keeps `backups/` and writes a final backup into it first (`data.final_backup`). Without `--yes` the
command exits 6 and `data` names what would be deleted (`final_backup`, `keeps_backups`).

## How this guide is tested

Fenced blocks marked `sh runnable` are executed by the journey `tests/Install/Journeys/J11AgentGuideTest.php` against a
locally built release, in the order they appear; each is followed by a `json expect` block. The test checks the exit
code, and that every field of the expect block exists in the envelope: a placeholder such as `"<string>"`, `"<int>"`,
`"<list>"` or `"<object>"` stands for any value of that type, every other value must match exactly. Blocks marked only
`sh` need a domain, DNS, a phone or the internet, and are not executed. Placeholders in angle brackets (`<backup>`,
`<backup-path>`, `<version>`) are values from earlier results, as the text says.
