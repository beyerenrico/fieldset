# Troubleshooting

For an instance installed with `install.sh` and managed with `fieldset`. Start with the general checks, then find your
symptom below. The 1.0 guide for checkout installs is in the [archive](archive/1.0-troubleshooting.md).

## General checks

```sh
fieldset status            # health of every service, URL, mail, backups, an unfinished operation
fieldset doctor --post     # Docker, memory, disk, ports, DNS, clock, and every service healthy
fieldset logs app          # also queue, scheduler, postgres, redis, gotenberg, mailpit, backup, edge, tunnel
fieldset logs app --since 30m --tail 0
```

Every `fieldset` command exits with a documented code and, with `--json`, prints one JSON document; the
[agent guide](agent-guide.md#exit-codes-and-recovery) explains them. The most important rule: when a command stopped
half-way, `fieldset status` and every other command name the command that continues it (exit 9
`operation_unfinished`). Run exactly that command; do not repair by hand with `docker` commands.

## The install or a command fails before it starts

| Answer | Cause and fix |
|---|---|
| exit 10 `docker_missing`, `docker_unavailable` | Docker is not installed, not running, or you may not use its socket. Start Docker (or add your user to the `docker` group) and run the command again. |
| exit 10 `docker_too_old` | Docker Engine older than 24. Upgrade Docker. |
| exit 3 `doctor_failed` | `data.checks` (or the human output) names the failed check and its fix: `ports` (a port is in use: pick another `--port`, or stop what uses 80/443), `dns` (the domain does not point to this host yet), `disk`, `memory`, `clock`, `registry` (images cannot be pulled), `path_sharing`. |
| exit 3 `path_not_shared` | On Docker Desktop the instance directory is not shared with Docker. Use a directory under your home directory, or add its parent in Docker Desktop's file-sharing settings. |
| exit 3 `verify_failed`, `manifest_signature_invalid` | The release manifest did not verify. Do not work around it: download again; if it persists, report it. |
| exit 7 `project_conflict` | Another instance or a leftover uses the same Compose project. `fieldset uninstall --orphans <uuid>` removes the leftovers of an instance whose directory is gone. |
| exit 9 `instance_locked` | Another `fieldset` command or a backup runs. Wait. |

## The site answers 503

The maintenance gate is up: an upgrade, restore, rollback, reset or adopt is running or stopped half-way.
`fieldset status` shows `operation`. Let it finish, or run the command it names. After exit 8 (rollback failed) the
instance stays behind the gate on purpose: follow `errors[0].hint` of that result.

## No certificate in domain mode

`fieldset status` shows the URL, and `fieldset logs edge` shows Caddy's attempts to get a certificate. Common causes: the DNS
record does not point to this host (yet), port 80 or 443 is blocked by a firewall, or Let's Encrypt's rate limits
after many attempts (use `fieldset config set acme-ca staging` while testing). An instance moved with `--from-backup`
reports "certificate pending" until DNS points to it.

## Links point to the wrong host, or the dashboard looks broken behind a proxy

In proxy mode the app trusts forwarded headers only from exactly one address: the proxy container (`traefik`,
`caddy`) or the instance network's gateway (`port`). If the proxy was recreated with a new address, `fieldset status` and
`fieldset doctor` warn `proxy_address_changed`; `fieldset config set trusted-proxy auto` follows it. Give the proxy a fixed
address on the shared network to avoid it. The instance's URL is `fieldset config set url <origin>`.

## Mail is not delivered

A new instance captures mail: everything lands in the mail catcher and the admin dashboard shows a "mail is captured"
banner. That is intended until you configure SMTP: `fieldset config set mail smtp …` (see
[configuration](configuration.md#mail)). The catcher's address is in `fieldset status` (`mail.catcher_url`); its login
comes from `fieldset config show --catcher-login --secrets-file <file>`.

With SMTP configured, a mail that does not arrive shows its error in the organization's mail log ("E-Mail-Protokoll").
`fieldset config set mail smtp` with the same settings sends a new test mail. Exit 5 `test_mail_failed` quotes the
server's answer: wrong login, a sender the server does not allow (`--from-address`), or STARTTLS required where the
server offers none.

## PDFs are not created

PDFs are rendered by the `gotenberg` service after a submission, in the queue. Check `fieldset status` (gotenberg and queue
healthy) and `fieldset logs gotenberg` and `fieldset logs queue`. The `/platform` health page shows Gotenberg and the queue too.

## The queue or the scheduler does not run

`fieldset status` shows `queue` and `scheduler`. Both stop on purpose while an operation holds the maintenance gate. If
they stopped otherwise, `fieldset logs queue` or `fieldset logs scheduler` says why; `fieldset doctor --post` fails while a service
is unhealthy. Backups need the `backup` service; `fieldset status` warns when it does not run.

## Field App

**Work stays "Wartet auf Synchronisierung" ("Waiting to sync").** The device tries every minute while the app is
open, on reconnect and on "Jetzt synchronisieren" ("Sync now"), with longer pauses (up to 15 minutes) after errors.
Ask the worker what the header shows:

| Header or message | Cause | What to do |
|---|---|---|
| "Erneut anmelden, um zu synchronisieren" | The session expired, or the device check failed (also when the device was rate-limited). Automatic syncing pauses. | Sign in again in the app; the data stays on the device. |
| "Brauchen Ihre Entscheidung: N" | The server refused something, for example a retired form version, revoked form access, or a draft over the 2 MB limit. | The worker opens the item and follows the instructions ([Field App guide](field-app-guide.md#when-the-app-needs-your-decision)). |
| "Nicht gespeichert – der Speicher dieses Geräts ist voll." | The device is out of storage. | Free space; sync; install the app. |
| Nothing, the app is just offline | No connection. On iPhone and iPad the app only syncs while it is open. | Open the app once online. |

What an administrator can check: whether the submission already arrived in "Einreichungen"; server errors or rate
limiting in `fieldset logs app`; the queue, because the PDF and the customer email follow the sync. Do not use "Geräte
sperren" ("Revoke devices") to fix a stuck device: the device then deletes all local data, including unsent work.

**The app does not offer to install, or offline mode does not work.** The app must be opened over HTTPS: a domain
instance, `fieldset config set phone-https local-ca` (the phone must trust the instance's CA first, see its trust page),
or `fieldset tunnel start`. On iPhone and iPad, install from Safari's share menu.

**The phone does not reach the local instance.** The phone must be in the same network as the computer, and the
computer's firewall must allow the HTTPS port. `fieldset status` shows `phone_https.lan_address`; if the computer's
address changed, `fieldset config set lan-address <IPv4>`.

**"Die Formulare sind offline noch nicht verfügbar."** The device has not downloaded the forms yet; connect once.

## Single sign-on

**Administrators get "Single Sign-on erfordert Ihre Aufmerksamkeit: <provider>"** ("Single sign-on needs your
attention"). The hourly provider check or the daily domain check failed; the SSO page shows the status "Fehler"
("Error") and the last error, for example: the discovery document, the signing keys or the SAML metadata could not be
loaded (check the URL and that the server reaches the provider over HTTPS); issuer, tenant, audience or signature
mismatches; `invalid_client` (the client secret is wrong or expired); a domain lost its verification; the break-glass
Owner is no longer valid. The same problem is reported at most every 6 hours; the next successful check sets the status
back to "Aktiv".

**Users see the password step instead of the identity provider.** The email domain is not verified, or the provider is
not active yet (run "Anmeldung testen", "Test sign-in").

**Domain verification fails.** "Kein TXT-Eintrag gefunden" ("No TXT record found"): the record is missing, not yet
visible (DNS caching), or was created as `_fieldset-verification.example.com.example.com` because the DNS
provider appended the domain; then enter only `_fieldset-verification` as the name.

**The provider reports an invalid redirect URI.** Register exactly the redirect URI shown on the SSO page
(`https://<your server>/auth/sso/<id>/callback`). It follows the instance's URL: after
`fieldset config set domain` or `url`, register the new one.

**Users come back with an error.** The login screen explains it; the codes are `sso_failed`, `sso_denied`,
`account_inactive`, `not_member`, `domain_not_verified`, `email_unverified` and `provider_unavailable`.

**"Ihre Organisation schreibt die zentrale Anmeldung vor."** ("Your organization requires single sign-on.") Only the
break-glass Owner can still use a password; if the identity provider is down, they sign in with password and
two-factor code and can turn enforcement off.

## Login throttling and lockouts

| Where | Limit |
|---|---|
| Field App password login | 5 attempts per minute per email address and IP |
| Field App two-factor code | 5 per minute |
| Admin login, password step | 5 per minute per IP |
| Admin login, email step | 10 per minute per IP |
| Password reset | one email per user per minute |
| API | 240 requests per minute per integration token, otherwise per user |
| `/platform` login | 5 attempts per minute per IP |
| `fieldset login-link` | exit 9 `rate_limited`: wait a minute |

The limits reset after a minute; waiting is the fix. Limits per IP need the real client IP: in proxy mode, see the
proxy section above. Quick-tunnel visitors all share the tunnel's address.

**Lost two-factor device.** Sign in with a recovery code, then set up two-factor authentication again.

**Forgotten password, or the owner cannot sign in.** "Passwort vergessen?" on `/admin/login` (only once mail is
delivered), or on the server: `fieldset login-link <email>` issues a one-time sign-in link for an admin.

## Audit log

**`activity_log is append-only` in the logs.** Something tried to change or delete audit entries, and the database
refused it. That is the protection working.

**The chain heads changed after a restore, rollback or reset.** Expected: these start a new chain epoch and record
the old heads in `backups/ledger.log` and in an audit entry; see [chain epochs](backup-restore.md#chain-epochs-and-the-ledger).
