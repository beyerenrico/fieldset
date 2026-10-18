# Upgrade guide

This guide upgrades a Docker Compose installation (see the [install guide](install.md)) from 0.1.x or
0.2.x to 1.0. The same procedure applies to later releases; always read the release notes of every
version you skip.

Contents:
- [Before you start](#before-you-start)
- [Upgrade steps](#upgrade-steps)
- [What changes between versions](#what-changes-between-versions)
- [The Field App update](#the-field-app-update)
- [After the upgrade](#after-the-upgrade)
- [Rollback](#rollback)

## Before you start

1. **Read the release notes** in `docs/releases/` and `CHANGELOG.md` for every version between yours
   and the target. Find your current version with `git describe --tags`.
2. **Back up and test the backup.** The upgrade changes the database schema; the backup is your way
   back.

   ```bash
   scripts/backup.sh --dest /srv/backups/form-schema-app
   scripts/restore-drill.sh --backup /srv/backups/form-schema-app/<newest> --scratch-db restore_drill_preupgrade
   ```

3. **Check that the system is healthy** before you change it, so you do not mistake old problems for
   upgrade problems:

   ```bash
   docker compose ps
   docker compose exec queue php artisan horizon:status
   docker compose exec app php artisan queue:failed
   docker compose exec app php artisan migrate:status
   ```

4. **Compare your `.env` with the new templates**: `.env.production.example` for production and
   `.env.example` for development. [What changes between versions](#what-changes-between-versions) lists
   the new settings for 1.0.
5. **Plan a short downtime.** The dashboard, the Field App sync and the API are unavailable while the
   `app` container restarts and runs the migrations, usually a minute or two. Field workers can keep
   working offline; their devices send everything afterwards. Large installations should allow longer:
   0.2 builds report indexes concurrently.
6. **Make sure there is disk space** for the new image and the backup.

## Upgrade steps

```bash
cd /srv/form-schema-app

# 1. Get the new release.
git fetch --tags
git checkout v1.0.0

# 2. Add new settings to .env (see "What changes between versions").
git diff v0.2.0 v1.0.0 -- .env.example
less .env.production.example     # new in 1.0: the production template

# 3. Build the new image. There is no registry image to pull: the image is built from the checkout.
docker compose build

# 4. Recreate the containers with the new image.
docker compose up -d --wait
```

Step 4 recreates all three application containers:
- `app` runs `php artisan migrate --force --isolated` on start (`RUN_MIGRATIONS=true` in
  `compose.yaml`; app containers starting at the same time take turns) and caches the new
  configuration. You can also run the migrations yourself first:
  `docker compose run --rm --no-deps app php artisan migrate --force`.
- `queue` starts Horizon with the new configuration, including new supervisors such as
  `supervisor-exports` (added in 0.2). Recreating the container is the Horizon restart. If you run
  Horizon outside Docker, run `php artisan horizon:terminate` and let your process manager start it
  again.
- `scheduler` picks up new scheduled tasks. The scheduler runs in UTC (`app.timezone`).

The web server configuration (`docker/app/Caddyfile`) is part of the image, so the rebuild applies its
changes. If you replaced it with your own file, merge the changes listed below by hand.

## What changes between versions

### 0.1 → 0.2

From the [0.2.0 release notes](../releases/0.2.0.md):
- Migrations add report and dashboard indexes with `CREATE INDEX CONCURRENTLY` (outside a
  transaction), plus an optional `pg_trgm` index for the explorer search. If PostgreSQL refuses the
  extension, the migration logs it and continues without the index.
- Horizon gets the `supervisor-exports` supervisor for the `exports` queue (one process, so large
  exports run one after another). Without a Horizon restart, exports stay pending.
- The Caddyfile routes `/app/index.html` and `/form-preview/preview.html` through Laravel, so the
  Content Security Policy always applies. Rebuild the image.
- The Field App moves its local data into a new IndexedDB database on first start; devices stay
  registered.
- Revoking a device now also ends that user's other sessions.
- New scheduled tasks: scheduled report emails (hourly), the export sweep (every 10 minutes) and the
  daily clean-up of expired exports. The `scheduler` service must run.

### 0.2 → 1.0

Single sign-on and the canvas builder (milestone M7):
- Migrations create the SSO tables. No data migration is needed for the canvas builder: it edits the
  same form drafts as the classic builder.
- New settings in `.env.example`; the defaults are the safe production values:

  ```dotenv
  SSO_ALLOW_INSECURE_IDP=false
  SSO_DNS_DRIVER=dns
  SSO_DNS_RECORDS=
  SSO_SAML_SP_CERTIFICATE=
  SSO_SAML_SP_PRIVATE_KEY=
  ```

  With `APP_ENV=production` the application **refuses to boot** if `SSO_ALLOW_INSECURE_IDP=true` or
  `SSO_DNS_DRIVER=config`, the development values from `compose.dev.yaml`. If your production `.env`
  still lists `compose.dev.yaml` in `COMPOSE_FILE`, change it to `COMPOSE_FILE=compose.yaml` before
  upgrading.
- The SAML key pair (`SSO_SAML_SP_CERTIFICATE`, `SSO_SAML_SP_PRIVATE_KEY`, PEM) is optional. Without it
  the application sends unsigned SAML requests and its metadata has no certificate; identity providers
  that require signed requests need it.
- Login is now email-first in the admin dashboard and the Field App: the address decides whether the
  user goes to the organization's identity provider or to the password step. Organizations that enforce
  SSO lose password login and password reset for their domains (except the break-glass Owner).
- New scheduled tasks: `sso:check-providers` (hourly) and `sso:recheck-domains` (daily).
- Outbound connections: the `app` and `scheduler` containers now call your organizations' identity
  providers (discovery documents, keys, tokens, SAML metadata) over HTTPS and look up DNS TXT records.
  Allow this in your firewall if outbound traffic is restricted (webhooks from `queue` already needed
  it).

Hardening for 1.0 (milestone M8, [release notes](../releases/1.0.0.md)):

**New migrations.**
- `2026_10_22_100000_harden_activity_log`: hash chain columns and four indexes on `activity_log`, the
  append-only trigger, and the database functions `audit_prune()`, `audit_seal()` and
  `audit_prunable()`. The indexes are not built concurrently, so writes to the audit log wait while they
  build; plan for that on a large audit log.
- `2026_10_22_100100_create_privacy_tables`: retention policies, legal holds and data exports, plus
  `submissions.anonymized_at` and `users.erased_at`.
- `2026_10_22_200000_create_platform_admins_table`: operators of the `/platform` panel.
- `2026_10_22_300000_add_first_used_at_to_personal_access_tokens`: first use of integration tokens.

`compose.yaml` no longer mounts `docker/postgres/init`, which created the test databases
(`form_schema_test` and others) on a new database volume. Installations from 0.x may still have them;
the application does not use them.

The new service providers (the `/platform` panel, audit, privacy) are registered in
`bootstrap/providers.php` and come with the new code; there is nothing to configure.

**The audit log is append-only.** After the migration the database refuses every change and deletion of
audit entries except through `audit_seal()` and `audit_prune()`.
- Retention: `audit:prune` deletes entries older than `AUDIT_RETENTION_DAYS` (365 by default, at least
  30). It replaces spatie's `activitylog:clean`, which the database now refuses. If you scheduled
  `activitylog:clean` yourself, remove it.
- Existing entries are not sealed into the hash chain yet. `audit:seal` seals them, oldest first,
  5,000 every five minutes, and `audit:verify` reports unsealed entries until it is done. To finish at
  once, run `docker compose exec app php artisan audit:seal --limit=100000` until it seals 0 entries.
- Then set up the regular chain check: [Protect the audit log](install.md#7-protect-the-audit-log).

**New scheduled tasks** (times in UTC; the `scheduler` service must run):

| Task | When |
|---|---|
| `audit:seal` (catch-up sealing of the hash chain) | every 5 minutes |
| `privacy:apply-retention` (per-form retention) | daily at 02:30 |
| `audit:prune` (audit retention) | daily at 03:15 |
| `model:prune` for expired personal data and audit log exports | daily at 00:00 |
| `sanctum:prune-expired --hours=24` (expired integration tokens) | daily at 00:00 |

**Horizon.** No new supervisor. Personal data and queued audit log exports run on the `exports` queue
(`supervisor-exports`), retention runs on `default`. Recreating the `queue` container in step 4 restarts
Horizon; outside Docker run `php artisan horizon:terminate`.

**Reverse proxy on the host, `TRUSTED_PROXIES` set.**
- `compose.yaml` now publishes the app port on `127.0.0.1` only (`127.0.0.1:${APP_PORT}:8080`). The
  reverse proxy must run on the same host. `APP_PORT` is the port number only: if your `.env` has an
  address in it, such as `APP_PORT=127.0.0.1:8080`, change it to `APP_PORT=8080`.
- Before 1.0 the application trusted no proxy headers. Set `TRUSTED_PROXIES=172.16.0.0/12` as in
  `.env.production.example`: a proxy on the host reaches the container from the Docker bridge range.
  Without it, links and assets use `http://` and rate limits count the proxy's address. Never trust a
  range that untrusted clients can reach ([install guide, step 5](install.md#5-reverse-proxy-and-tls)).
- Unless `APP_ENV` is `local` or `testing`, requests for a host name other than the one in `APP_URL` get
  400. Add further names to `TRUSTED_HOSTS`.

**Admin sessions time out.** The admin dashboard now signs users out after 30 minutes without activity
and 12 hours after sign-in at the latest (`config/security.php`). The Field App session is unchanged
(30 days, rolling).

**HTTPS headers.** Behind HTTPS, session cookies are always Secure, and `Strict-Transport-Security`
(one year) is sent when the request is HTTPS or `APP_URL` starts with `https://`. Browsers then refuse
plain HTTP for that host for a year; `SECURITY_HSTS_ENABLED=false` switches the header off. Every
response now has a Content Security Policy: the admin pages load scripts, styles, fonts and images only
from the instance itself, so customizations that load them from elsewhere are blocked.

**Builder preview.** The preview runs in a sandbox on an opaque origin. The image's Caddyfile now sends
`Access-Control-Allow-Origin: *` for `/form-preview/assets/*` and `X-Content-Type-Options: nosniff` for
static files; the rebuild applies it. If you serve `public/` with another web server, add the same header
for `/form-preview/assets/*`, or the preview cannot load its scripts.

**Logs.** The image now formats stderr logs with `App\Support\Logging\JsonLogFormatter`: one JSON
object per line, secrets redacted, with the request id. If your `.env` sets
`LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter`, remove that line. The production template uses
`LOG_CHANNEL=stack` and `LOG_STACK=json`; add `sentry` and `SENTRY_DSN` for error reporting
([configuration](configuration.md#logging-and-error-reporting)). API answers with a 4xx status (validation
errors, conflicts, not found) are no longer logged as errors.

**New settings.** All have safe defaults; only `TRUSTED_PROXIES` needs a value (empty trusts no proxy).
Details in the [configuration reference](configuration.md):

```dotenv
TRUSTED_PROXIES=172.16.0.0/12
TRUSTED_HOSTS=
SECURITY_HSTS_ENABLED=
UPLOADS_CLAMAV_ENABLED=false
UPLOADS_CLAMAV_HOST=clamav
UPLOADS_CLAMAV_PORT=3310
SENTRY_DSN=
PLATFORM_IMPERSONATION_MINUTES=30
PLATFORM_SESSION_HOURS=12
API_TOKEN_DEFAULT_EXPIRY_DAYS=90
AUDIT_RETENTION_DAYS=365
PRIVACY_LEGAL_HOLD_MIN_MONTHS=120
PRIVACY_EXPORT_KEEP_DAYS=7
```

Further optional settings: `API_RATE_LIMIT`, `LOG_JSON_STREAM`, `SENTRY_LEVEL`, `SENTRY_ENVIRONMENT`,
`SENTRY_RELEASE`, `SENTRY_TIMEOUT`, `SECURITY_HSTS_MAX_AGE`, `SECURITY_HSTS_INCLUDE_SUBDOMAINS`,
`SECURITY_HSTS_PRELOAD`, `UPLOADS_CLAMAV_TIMEOUT`, `UPLOADS_CLAMAV_FAIL_OPEN`,
`AUDIT_EXPORT_SYNC_MAX_ROWS`, `PRIVACY_RETENTION_BATCH`, `PRIVACY_EXPORTS_PER_HOUR`,
`PRIVACY_EXPORT_DISK`. The `AWS_*` lines were removed from `.env.example`; they never had an effect.

**What users notice.**
- The forms list, new forms, the search and the dashboard open the canvas builder. The classic builder
  is one click away ("Klassischer Editor"); bookmarks to `…/forms/<id>/edit` still open it. Forms with
  an open PDF import report still open in the classic builder.
- New pages under "Compliance": audit log, retention, legal holds, personal data, my data. New page
  "Team" → "API-Tokens". See the [admin guide](admin-guide.md#audit-log).
- Nothing is deleted or anonymized until an Owner or Admin sets a retention period for a form.
- The PDF of an anonymized submission answers **410 Gone** with a notice, in the dashboard, the Field App
  and the API (`document_anonymized`).
- Operators get the `/platform` panel: grant the role with `php artisan app:platform-admin <email>`.
  `/horizon` and `/docs/api` still use `PLATFORM_ADMIN_EMAILS`.

For integrators: the API stays `/api/v1`. 0.2 added `client_submitted_at` on submit and
`retired_version_ids` in the sync manifest. 1.0 adds integration tokens with abilities, a rate limit per
token, and 410 `document_anonymized` on `GET /submissions/{uuid}/document` and the signed PDF download;
treat it as permanent and do not poll. Field App sessions work as before. See
[API and integrations](api.md).

## The Field App update

The Field App is a web app with a service worker. It does not update in the middle of someone's work:
1. After the upgrade, each device finds the new version the next time the app checks, for example when it
   is opened.
2. The app shows "Eine neue Version der App ist verfügbar." ("A new version of the app is available.").
3. "Jetzt aktualisieren" ("Update now") saves open drafts on the device, activates the new version and
   reloads. "Später" ("Later") keeps the old version until the next prompt.

Unsent work stays in the device's outbox during the update. If the app is open in several tabs, the
other tabs ask to reload ("Neu laden"). Tell field workers to accept the prompt; nothing else is needed,
and devices stay registered.

## After the upgrade

```bash
docker compose ps                                   # app and queue healthy
docker compose exec app php artisan migrate:status  # every migration "Ran"
docker compose exec queue php artisan horizon:status
docker compose exec app php artisan schedule:list   # includes the new tasks
docker compose exec app php artisan audit:verify    # after the old entries are sealed
docker compose logs --since 15m app queue scheduler
```

Then check in the dashboard: sign in, open a submission and its PDF, send a test email from
"E-Mail-Einstellungen", and open the Field App on a phone (accept the update prompt). In the Horizon
dashboard (`/horizon`, for `PLATFORM_ADMIN_EMAILS`) both supervisors should be running. Run a restore
drill on the first backup after the upgrade.

## Rollback

**If the upgrade fails before or during the migrations:** read the error in `docker compose logs app`.
Most migration problems can be fixed and the migrations run again
(`docker compose run --rm --no-deps app php artisan migrate --force`). An interrupted
`CREATE INDEX CONCURRENTLY` leaves an invalid index; the index migrations of this release drop such an
index and build it again when they rerun. To check that none is left after the migrations:

```bash
docker compose exec postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT indexrelid::regclass FROM pg_index WHERE NOT indisvalid"'
```

**To go back to the previous release:** do not check out the old code against the migrated database.
The supported way back is the pre-upgrade backup:
1. `git checkout <previous tag>` and `docker compose build`.
2. Restore the backup taken before the upgrade, as described in
   [Full restore](backup-restore.md#full-restore) (stop the application, recreate the database,
   restore database and storage, start).
3. Data created after the upgrade is lost, except for work still in the Field App outboxes, which the
   devices send again.

The Field App follows the server: after a rollback, devices should be offered the previous app version
through the same update prompt, because the service worker file changes again.
