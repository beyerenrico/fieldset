# Troubleshooting

Start with the general checks, then find your symptom below. Commands assume the Docker Compose
installation from the [install guide](install.md), run in the project directory.

Contents:
- [General checks](#general-checks)
- [The stack does not start](#the-stack-does-not-start)
- [The dashboard looks broken behind HTTPS](#the-dashboard-looks-broken-behind-https)
- [PDFs are not created (Gotenberg)](#pdfs-are-not-created-gotenberg)
- [Mail is not sent](#mail-is-not-sent)
- [The queue does not run](#the-queue-does-not-run)
- [The scheduler does not run](#the-scheduler-does-not-run)
- [Field App](#field-app)
- [Single sign-on](#single-sign-on)
- [Login throttling and lockouts](#login-throttling-and-lockouts)

## General checks

```bash
docker compose ps                                     # app and queue "healthy", scheduler and gotenberg running
docker compose logs --since 30m app queue scheduler   # JSON logs if LOG_CHANNEL=stderr
curl -fsS http://127.0.0.1:8080/up                    # the application boots (not a database check)
docker compose exec queue php artisan horizon:status  # "Horizon is running."
docker compose exec app php artisan queue:failed      # failed jobs
docker compose exec app php artisan schedule:list     # scheduled tasks and their next run
docker compose exec app php artisan migrate:status    # every migration "Ran"
```

Operators listed in `PLATFORM_ADMIN_EMAILS` can open the Horizon dashboard at `/horizon` (sign in at
`/admin/login` first) to see queues, failed jobs and their errors.

If logs are missing from `docker compose logs`, `.env` probably still has `LOG_CHANNEL=stack` from the
example file; the logs are then in `storage/logs/laravel.log` inside each container. Set
`LOG_CHANNEL=stderr` and recreate the containers.

After any change to `.env`: `docker compose up -d --force-recreate app queue scheduler`.

## The stack does not start

| Symptom (logs) | Cause and fix |
|---|---|
| `SSO_ALLOW_INSECURE_IDP and SSO_DNS_DRIVER=config are not allowed in production.` | `compose.dev.yaml` is loaded (through `COMPOSE_FILE` in `.env`) or `.env` sets these values. Set `COMPOSE_FILE=compose.yaml`, `SSO_ALLOW_INSECURE_IDP=false`, `SSO_DNS_DRIVER=dns`. |
| `No application encryption key has been specified.` | `APP_KEY` is empty. Restore it from your secret store; generate a new one only for a new, empty installation. |
| `postgres` exits on the first start with `role "form_schema" does not exist` | `DB_USERNAME` was changed. The init script `docker/postgres/init/01-test-db.sql` expects the user `form_schema`. Use `DB_USERNAME=form_schema`, remove the half-initialized volume (`docker compose down`, then `docker volume rm form-schema-app_pg-data`, only on a new installation without data) and start again. |
| `queue` or `scheduler` restart a few times right after the first start | They started before `app` finished the migrations. They recover on their own. |
| `app` is unhealthy and the logs show a database error | Check `DB_PASSWORD` against the password the database volume was created with; changing it in `.env` does not change the database. |

## The dashboard looks broken behind HTTPS

Symptoms: the admin dashboard loads without styles or does not react, the browser console reports
"mixed content", SSO redirect URIs start with `http://`, or all users share one login rate limit.

> TODO-VERIFY (trusted proxies, M8-T03): at the time of writing the application does not trust
> `X-Forwarded-*` headers, so behind a TLS-terminating reverse proxy it generates `http://` URLs. Replace
> this note with the setting that fixes it once it exists.

Also check that `APP_URL` starts with `https://` and that the proxy forwards the `Host` header.

## PDFs are not created (Gotenberg)

**"Das PDF wird erstellt …" ("The PDF is being created…") does not go away.** PDFs are created by the
queue. Check [the queue](#the-queue-does-not-run) first. The scheduler's `documents:sweep` re-dispatches
PDFs that are missing after 10 minutes, so a short queue outage heals by itself.

**The submission shows "PDF-Erzeugung fehlgeschlagen" ("PDF generation failed")** in the admin inbox
under "Dokumente". There is no separate notification for this; the Field App shows that the office was
informed. The panel names the reason:
- "Das Dokument ist zu groß für die PDF-Erzeugung (zu viele oder zu große Fotos)." The document with all
  its photos exceeds the render budget (`DOCUMENTS_MAX_REQUEST_BYTES`, 25 MB). Use fewer or smaller
  photos, or raise the budget together with Gotenberg's `--api-body-limit` (30 MB in `compose.yaml`).
- "Der PDF-Dienst konnte das Dokument auch nach mehreren Versuchen nicht erzeugen." Gotenberg was
  unreachable or failed on three attempts in a row. Check Gotenberg:

  ```bash
  docker compose ps gotenberg
  docker compose logs --since 1h gotenberg
  docker compose exec app curl -fsS http://gotenberg:3000/health     # {"status":"up",…}
  ```

  `app` and `queue` reach Gotenberg over the internal `renderer` network; `GOTENBERG_URL` must stay
  `http://gotenberg:3000`. Gotenberg has no internet access on purpose.

After fixing the cause, click "PDF neu erzeugen" ("Re-render PDF") on the submission. Customer emails
that were waiting for the failed PDF were not sent; send them again after the new PDF exists.

**"PDF-Vorschau" ("Preview PDF") fails** with "Die Vorschau konnte nicht erzeugt werden." The preview is
rendered while you wait, so the same Gotenberg checks apply.

## Mail is not sent

Look at "E-Mail" → "E-Mail-Protokoll" ("Mail log") in the dashboard:

| Status | Meaning and fix |
|---|---|
| "In Warteschlange" ("Queued") for minutes | The queue is not running ([below](#the-queue-does-not-run)). A mail whose earlier attempt failed temporarily also waits here for the next attempt. |
| "Fehlgeschlagen" ("Failed") | Open the mail to see the error, fix the cause, then "Erneut senden" ("Send again"). Mail is tried 3 times before it fails. |
| "Fehlgeschlagen" with "Delivery was interrupted; the mail may or may not have been sent." | A worker stopped while sending. Check with the recipient before sending again. |
| "Gesendet" ("Sent") but nothing arrived | The SMTP server accepted it. Check the spam folder and the SPF/DKIM/DMARC records of `MAIL_FROM_ADDRESS`'s domain. |

Common causes:
- `MAIL_MAILER` is not set: the default `log` writes mails to the log instead of sending them.
- Wrong `MAIL_HOST`, `MAIL_PORT` or credentials: the error is in the mail log or the container logs.
- An organization's own SMTP server: "Dieser Mailserver ist nicht erlaubt. Er muss über eine öffentliche
  Adresse erreichbar sein." means it resolves to a private address; only public servers on ports 25,
  465, 587 and 2525 are allowed.
- "Test-E-Mail senden" only queues the mail; the result appears in the mail log, and only if the queue
  runs.

Invitations and password-reset mails do not appear in the mail log. Look for errors in
`docker compose logs app queue`.

## The queue does not run

Symptoms: PDFs stay "being created", mails stay "In Warteschlange", workflows do not start,
notifications and exports do not arrive, PDF imports never finish.

```bash
docker compose ps queue
docker compose exec queue php artisan horizon:status   # running, paused or inactive
docker compose logs --since 1h queue
docker compose up -d --force-recreate queue
```

- `QUEUE_CONNECTION` must be `redis`; with the framework default `database`, jobs go to a table that
  Horizon does not work.
- Exports pending but everything else works: the `supervisor-exports` supervisor is missing. Recreate
  the `queue` container with the current image. It runs one export at a time, so a large export delays
  the next.
- Failed jobs: `docker compose exec app php artisan queue:failed`, then retry with
  `php artisan queue:retry <id>` or in the Horizon dashboard.
- `DOCUMENTS_QUEUE`, `MESSAGING_QUEUE` or `WORKFLOWS_QUEUE` set to a custom name: no supervisor works that
  queue unless you add one to `config/horizon.php`.

## The scheduler does not run

Symptoms: scheduled workflows and review reminders stop, scheduled report emails do not arrive, export
files are not deleted, missing PDFs are not re-dispatched, SSO health checks stop.

```bash
docker compose ps scheduler
docker compose logs --since 1h scheduler
docker compose exec app php artisan schedule:list
```

The `scheduler` service has no health check, so check its logs: `workflows:tick` runs every minute.

## Field App

**Work stays "Wartet auf Synchronisierung" ("Waiting to sync").** The device tries every minute while
the app is open, on reconnect and on "Jetzt synchronisieren" ("Sync now"), with longer pauses (up to 15
minutes) after errors. Ask the worker what the header shows:

| Header or message | Cause | What to do |
|---|---|---|
| "Erneut anmelden, um zu synchronisieren" | The session expired, or the device check failed (also when the device was rate-limited). Automatic syncing pauses. | Sign in again in the app; the data stays on the device. |
| "Brauchen Ihre Entscheidung: N" | The server refused something, for example a retired form version, revoked form access, or a draft over the 2 MB limit. | The worker opens the item and follows the instructions ([Field App guide](field-app-guide.md#when-the-app-needs-your-decision)). |
| "Nicht gespeichert – der Speicher dieses Geräts ist voll." | The device is out of storage. | Free space; sync; install the app. |
| Nothing, the app is just offline | No connection. On iPhone and iPad the app only syncs while it is open. | Open the app once online. |

What an administrator can check:
- whether the submission already arrived in "Einreichungen";
- server errors (5xx) or rate limiting (429) in `docker compose logs app`;
- the queue, because the PDF and the customer email follow the sync;
- `SESSION_LIFETIME` (43200 minutes keeps devices signed in for 30 days).

Do not use "Geräte sperren" ("Revoke devices") to fix a stuck device: the device then deletes all local
data, including unsent work.

**Sign-in does not stick, or every request fails right after sign-in.** Check that the host name of
`APP_URL` is in `SANCTUM_STATEFUL_DOMAINS`, that `SESSION_SECURE_COOKIE=true` is only used over HTTPS,
and that the Field App is opened on the same host as `APP_URL`.

**"Die Formulare sind offline noch nicht verfügbar."** The device has not downloaded the forms yet;
connect once.

**The app does not offer to install, or offline mode does not work.** The site must be served over
HTTPS. On iPhone and iPad, install from Safari's share menu.

## Single sign-on

**Administrators get "Single Sign-on erfordert Ihre Aufmerksamkeit: <provider>"** ("Single sign-on needs
your attention"). The hourly provider check or the daily domain check failed; the SSO page shows the
status "Fehler" ("Error") and the last error. The notification names the reason, for example:
- the discovery document, the signing keys (JWKS) or the SAML metadata could not be loaded: check the
  issuer or metadata URL and that the server can reach the provider over HTTPS;
- issuer, tenant, audience or signature mismatches: compare the issuer, tenant ID and client ID with the
  provider's settings; for SAML, the provider's signing certificate in its metadata;
- `invalid_client`: the client secret is wrong or expired; enter a new one;
- a domain lost its verification: the TXT record is gone or changed;
- the break-glass Owner is no longer valid (deactivated, no longer Owner, or no two-factor
  authentication), so enforcement has no emergency access.

The same problem is reported at most every 6 hours. Run the check by hand:
`docker compose exec app php artisan sso:check-providers`. A successful check sets the status back to
"Aktiv".

**Users see the password step instead of the identity provider.** The email domain is not verified, or
the provider is not active yet (run "Anmeldung testen", "Test sign-in").

**Domain verification fails.** "Kein TXT-Eintrag gefunden" ("No TXT record found"): the record is
missing, not yet visible (DNS caching), or was created as
`_form-schema-app-verification.example.com.example.com` because the DNS provider appended the domain;
then enter only `_form-schema-app-verification` as the name.

**The provider reports an invalid redirect URI.** Register exactly the redirect URI shown on the SSO page
(`https://<your server>/auth/sso/<id>/callback`). If it starts with `http://`, see
[the HTTPS note](#the-dashboard-looks-broken-behind-https).

**Users come back with an error.** The login screen explains it; the codes are `sso_failed`,
`sso_denied`, `account_inactive`, `not_member` (no membership and just-in-time provisioning is off),
`domain_not_verified`, `email_unverified` (the provider does not mark the email as verified) and
`provider_unavailable`.

**"Ihre Organisation schreibt die zentrale Anmeldung vor."** ("Your organization requires single
sign-on.") Password login is turned off for this domain. Only the break-glass Owner can still use a
password. If the identity provider is down, the break-glass Owner signs in with password and two-factor
code and can turn enforcement off.

## Login throttling and lockouts

| Where | Limit | Message |
|---|---|---|
| Field App password login | 5 attempts per minute per email address and IP | "Zu viele Versuche. Bitte warten Sie kurz und versuchen Sie es erneut." |
| Field App two-factor code | 5 per minute | same |
| Admin login, password step | 5 per minute per IP | "Zu viele Loginversuche. Bitte in … Sekunden nochmal versuchen." |
| Admin login, email step | 10 per minute per IP | same |
| SSO discovery (email step) | 10 per minute per email address and IP, 300 per minute per IP | |
| Password reset | one email per user per minute | "Bitte warten Sie, bevor Sie es erneut versuchen." |
| API | 240 requests per minute per user | 429 `too_many_requests` |

The limits reset after a minute; waiting is the fix. Limits per IP assume the application sees the real
client IP (see [the HTTPS note](#the-dashboard-looks-broken-behind-https)).

**Lost two-factor device.** Sign in with one of the recovery codes, then set up two-factor
authentication again in the profile.

**Forgotten password.** "Passwort vergessen?" ("Forgot password?") on the admin login page
(`/admin/login`) sends a reset link to any account, including field workers, who then sign in to the
Field App with the new password; the Field App itself has no reset screen. Not available for addresses
whose organization enforces SSO.
