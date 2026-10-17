# Configuration reference

All settings are environment variables in `.env`, read through the files in `config/`. Every service
in `compose.yaml` loads `.env`; values in `.env` override the defaults built into the image. After a
change, recreate the containers: `docker compose up -d --force-recreate app queue scheduler` (the
configuration is cached when a container starts).

"Default" is the value the application uses when the variable is not set. Where `.env.example` sets a
different value, the table says so. Settings marked *fixed* are configuration keys without an
environment variable; changing them means editing `config/` and rebuilding the image.

Contents:
- [Application](#application)
- [Docker Compose and the image](#docker-compose-and-the-image)
- [Database](#database)
- [Redis, queue, cache and sessions](#redis-queue-cache-and-sessions)
- [Logging](#logging)
- [Mail](#mail)
- [Mail delivery rules](#mail-delivery-rules)
- [Documents and PDF rendering](#documents-and-pdf-rendering)
- [Fillable PDF import](#fillable-pdf-import)
- [Submissions and uploads](#submissions-and-uploads)
- [Workflows and webhooks](#workflows-and-webhooks)
- [Reports and exports](#reports-and-exports)
- [Dashboards](#dashboards)
- [Single sign-on](#single-sign-on)
- [Files and storage](#files-and-storage)
- [Other](#other)

## Application

`config/app.php`

| Variable | Default | Meaning |
|---|---|---|
| `APP_NAME` | `form-schema-app` | Name in mails, the browser title and the webhook `User-Agent`. |
| `APP_ENV` | `production` (image); `.env.example`: `local` | `production` for real installations. Production refuses the SSO development settings. |
| `APP_DEBUG` | `false`; `.env.example`: `true` | Shows stack traces. Never `true` in production. |
| `APP_KEY` | none | Encryption key, `base64:` + 32 random bytes. Required; back it up ([why](backup-restore.md#the-app_key)). |
| `APP_PREVIOUS_KEYS` | empty | Comma-separated old keys, so data encrypted with them stays readable after a key change. |
| `APP_URL` | `http://localhost` | Public base URL (`https://…`). Used for links generated in the background. |
| `APP_LOCALE` | `en` | Default language where no user or organization language applies (`de` or `en`). |
| `APP_FALLBACK_LOCALE` | `en` | Language for missing translations. |
| `APP_ALLOW_REGISTRATION` | `false` | Lets anyone register a user and an organization at `/admin`. Only for a public instance. |
| `PLATFORM_ADMIN_EMAILS` | empty | Comma-separated emails of operators: access to `/horizon` and `/docs/api`. |
| `APP_INVITATIONS_PER_DAY` | `1000` | Member invitations per organization in 24 hours. |
| `APP_MAINTENANCE_DRIVER` | `file` | Laravel maintenance mode driver. |
| `BCRYPT_ROUNDS` | `12` | Password hashing cost. |
| *fixed* `app.timezone` | `UTC` | Server time zone. Organizations and users have their own time zones for display and schedules. |
| *fixed* API rate limit | 240 per minute | Per user (per IP for guests) on `/api/v1`. |

## Docker Compose and the image

| Variable | Default | Meaning |
|---|---|---|
| `COMPOSE_FILE` | `compose.yaml`; `.env.example`: `compose.yaml:compose.dev.yaml` | Read by Docker Compose. Use `compose.yaml` alone in production. |
| `COMPOSE_PROFILES` | empty | `sso` starts the development Keycloak (with `compose.dev.yaml` only). |
| `APP_PORT` | `8080` | Host port (optionally `IP:port`, for example `127.0.0.1:8080`) published for the `app` service. |
| `FORWARD_DB_PORT`, `FORWARD_REDIS_PORT`, `FORWARD_GOTENBERG_PORT`, `FORWARD_MAILPIT_UI_PORT`, `FORWARD_MAILPIT_SMTP_PORT` | `54329`, `63799`, `13000`, `18025`, `11025` | Development only (`compose.dev.yaml`). |
| `RUN_MIGRATIONS` | `true` for `app` (set in `compose.yaml`) | Run `php artisan migrate --force` when the container starts. |

Set in the image (`docker/app/Dockerfile`) and overridden by `.env` when present: `APP_ENV=production`,
`LOG_CHANNEL=stderr`, `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter`, `SERVER_NAME=:8080`.
PHP limits (`docker/app/php.ini`): `memory_limit=512M`, `upload_max_filesize=25M`, `post_max_size=30M`.

Gotenberg flags in `compose.yaml`: `--chromium-deny-private-ips=true`, `--chromium-deny-public-ips=true`,
`--chromium-disable-javascript=true`, `--api-body-limit=30MB`, `--api-timeout=60s`.

## Database

`config/database.php`. PostgreSQL 15 or newer is required (16 in `compose.yaml`).

| Variable | Default | Meaning |
|---|---|---|
| `DB_CONNECTION` | `pgsql` (set in `compose.yaml`) | Only PostgreSQL is supported. |
| `DB_HOST`, `DB_PORT` | `postgres`, `5432` (set in `compose.yaml`) | The values in `.env` are used only outside Docker. |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `form_schema`, `form_schema`, `secret` | Also create the database on the first start of the `postgres` service. Keep the user `form_schema` ([why](install.md#2-create-the-env-file)); set a strong password. |
| `DB_SSLMODE` | `prefer` | For an external PostgreSQL server. |
| `DB_URL` | empty | Alternative connection URL. |

## Redis, queue, cache and sessions

`config/database.php`, `config/queue.php`, `config/cache.php`, `config/session.php`,
`config/sanctum.php`, `config/horizon.php`

| Variable | Default | Meaning |
|---|---|---|
| `REDIS_HOST`, `REDIS_PORT` | `redis`, `6379` (set in `compose.yaml`) | Valkey service. |
| `REDIS_PASSWORD` | none | The bundled Valkey has no password and no published port. |
| `REDIS_CLIENT` | `phpredis`; `.env.example`: `predis` | Both work in the image. |
| `REDIS_DB`, `REDIS_CACHE_DB` | `0`, `1` | Databases for queues and cache. |
| `QUEUE_CONNECTION` | `database`; `.env.example`: `redis` | Must be `redis`: Horizon only works Redis queues. |
| `CACHE_STORE` | `database`; `.env.example`: `redis` | Cache and locks. |
| `SESSION_DRIVER` | `database` | Sessions in PostgreSQL. |
| `SESSION_LIFETIME` | `120`; `.env.example`: `43200` | Minutes. Keep 43200 (30 days, rolling) so field workers stay signed in while offline. |
| `SESSION_ENCRYPT` | `false`; `.env.example`: `true` | Encrypt session data. |
| `SESSION_SECURE_COOKIE` | unset; `.env.example`: `false` | Set `true` in production (HTTPS only). |
| `SESSION_SAME_SITE` | `lax` | Keep `lax`. |
| `SESSION_DOMAIN`, `SESSION_PATH` | none, `/` | Cookie scope. |
| `SANCTUM_STATEFUL_DOMAINS` | localhost entries plus the `APP_URL` host | Hosts whose browser requests use the session cookie (the Field App). `.env.example` lists only `localhost`; set your host name. |
| `HORIZON_PATH` | `horizon` | Path of the Horizon dashboard. |
| `HORIZON_PREFIX`, `HORIZON_NAME`, `HORIZON_DOMAIN` | derived from `APP_NAME`, none, none | Horizon's Redis prefix and dashboard options. |
| *fixed* Horizon supervisors | `supervisor-1`: queue `default`, up to 10 processes in production; `supervisor-exports`: queue `exports`, 1 process | Queues without a supervisor are never worked. |

## Logging

`config/logging.php`

| Variable | Default | Meaning |
|---|---|---|
| `LOG_CHANNEL` | `stderr` (image); `.env.example`: `stack` | Use `stderr` in Docker so `docker compose logs` shows the logs. `stack` writes to a file inside the container. |
| `LOG_STACK` | `single` | Channels of the `stack` channel. |
| `LOG_LEVEL` | `debug` | `info` or `warning` in production. |
| `LOG_STDERR_FORMATTER` | JSON (image) | Monolog formatter for `stderr`. |
| `LOG_DEPRECATIONS_CHANNEL` | `null` | PHP deprecation warnings. |

Every request gets an `X-Request-Id` (taken from the request or generated), which appears in the logs.

## Mail

`config/mail.php`. The instance-wide transport; organizations can add their own SMTP server in the
dashboard.

| Variable | Default | Meaning |
|---|---|---|
| `MAIL_MAILER` | `log`; `.env.example`: `smtp` | `smtp` in production. The image includes the `smtp`, `sendmail` and `log` transports only. |
| `MAIL_HOST`, `MAIL_PORT` | `127.0.0.1`, `2525` | SMTP server. |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | none | SMTP credentials. |
| `MAIL_SCHEME` | none | `smtps` or `smtp`; when empty, port 465 means `smtps`. |
| `MAIL_URL` | none | Alternative DSN. |
| `MAIL_EHLO_DOMAIN` | host of `APP_URL` | Name in the SMTP greeting. |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | `hello@example.com`, `APP_NAME` | Sender of instance mail. Organizations without their own SMTP server send from this address with their own sender name. |

## Mail delivery rules

`config/messaging.php`

| Variable | Default | Meaning |
|---|---|---|
| `MESSAGING_QUEUE` | `default` queue | Queue of the delivery job. A different name needs a Horizon supervisor. |
| `MESSAGING_TRIES` | `3` | Attempts per mail (30 s and 120 s apart). |
| `MESSAGING_CUSTOMER_SENDS_PER_SUBMISSION_PER_DAY` | `10` | "Send to customer" limit per submission. |
| `MESSAGING_CUSTOMER_SENDS_PER_ORGANIZATION_PER_HOUR` | `200` | "Send to customer" limit per organization. |
| `MESSAGING_ATTACHMENT_MAX_BYTES` | `10485760` (10 MB) | Larger PDFs are sent as an expiring link. |
| `MESSAGING_LINK_TTL_DAYS` | `14` | Lifetime of document links; expired links answer 410 Gone. |
| `MESSAGING_LINK_RATE_LIMIT` | `30` | Requests per minute and IP on document links. |
| `MESSAGING_LINK_PRUNE_AFTER_DAYS` | `90` | Expired links are deleted this many days after expiry. |
| `MESSAGING_SMTP_ALLOW_PRIVATE_HOSTS` | `false` | Allow organization SMTP servers on private addresses and any port. Development only. |
| `MESSAGING_SMTP_TIMEOUT` | `15` | Seconds, organization SMTP servers. |
| *fixed* allowed organization SMTP ports | 25, 465, 587, 2525 | |

## Documents and PDF rendering

`config/documents.php`, `config/laravel-pdf.php`

| Variable | Default | Meaning |
|---|---|---|
| `GOTENBERG_URL` | `http://gotenberg:3000` (set in `compose.yaml`) | Gotenberg API. |
| `GOTENBERG_USERNAME`, `GOTENBERG_PASSWORD` | none | Basic auth, if your Gotenberg requires it. |
| `LARAVEL_PDF_DRIVER` | `gotenberg` | Keep `gotenberg`. |
| `DOCUMENTS_DISK` | `local` | Disk for PDFs and logos. |
| `DOCUMENTS_RENDER_ON_SUBMIT` | `true` (except in tests) | Create the PDF after every submit. |
| `DOCUMENTS_QUEUE` | `default` | Queue of the render job. A different name needs a Horizon supervisor. |
| `DOCUMENTS_MAX_REQUEST_BYTES` | `26214400` (25 MB) | Size budget of one render request (photos, logo and fonts are embedded). Keep it below Gotenberg's `--api-body-limit` (30 MB). |
| `DOCUMENTS_PHOTO_MAX_EDGE` | `1600` | Photos are scaled down to this many pixels on the long edge for print. |
| `DOCUMENTS_LINK_TTL_MINUTES` | `5` | Lifetime of the signed PDF link in the Field App. |
| `DOCUMENTS_SWEEP_AFTER_MINUTES`, `DOCUMENTS_SWEEP_BATCH` | `10`, `100` | `documents:sweep` re-dispatches PDFs missing for this long, this many per run. |
| `DOCUMENTS_RERENDER_PER_SUBMISSION_PER_MINUTE`, `DOCUMENTS_RERENDER_PER_USER_PER_MINUTE` | `1`, `10` | Limits of "PDF neu erzeugen". |
| `DOCUMENTS_LOGO_MAX_KB`, `DOCUMENTS_LOGO_MAX_SIDE`, `DOCUMENTS_LOGO_MAX_PIXELS` | `2048`, `4000`, `16000000` | Logo upload limits. |

## Fillable PDF import

`config/forms.php`

| Variable | Default | Meaning |
|---|---|---|
| `QPDF_BINARY` | `qpdf` | qpdf executable (included in the image). |
| `PDF_IMPORT_MAX_BYTES` | `20971520` (20 MB) | Largest PDF. |
| `PDF_IMPORT_MAX_PAGES` | `50` | Most pages. |
| `PDF_IMPORT_TIMEOUT` | `60` | Seconds per qpdf call. |

## Submissions and uploads

`config/submissions.php`

| Variable | Default | Meaning |
|---|---|---|
| `SUBMISSIONS_MAX_PAYLOAD_BYTES` | `2097152` (2 MB) | Largest JSON request body on `/api`. |
| `SUBMISSIONS_IDEMPOTENCY_TTL_HOURS` | `24` | How long a submit response is replayed for the same `Idempotency-Key`. |
| `SUBMISSIONS_IMAGE_MAX_PIXELS`, `SUBMISSIONS_IMAGE_MAX_SIDE` | `40000000`, `12000` | Larger images are refused. |
| `SUBMISSIONS_IMAGE_STORE_MAX_PIXELS` | `25000000` | Larger accepted images are scaled down when stored. |
| *fixed* attachment size | 25 MB | Per file. |

## Workflows and webhooks

`config/workflows.php`

| Variable | Default | Meaning |
|---|---|---|
| `WORKFLOWS_COMPLETE_WITHOUT_REVIEW` | `true` (except in tests) | Complete a submission right after submit when no workflow manages its status. |
| `WORKFLOWS_QUEUE` | `default` queue | Queue of workflow steps. A different name needs a Horizon supervisor. |
| `WORKFLOWS_STEP_TRIES`, `WORKFLOWS_STEP_BACKOFF_BASE` | `5`, `10` | Attempts per step; waits of base, base×3, base×9 … seconds. |
| `WORKFLOWS_SWEEP_AFTER_MINUTES` | `30` | Runs without progress this long get their step queued again. |
| `WORKFLOWS_START_SWEEP_AFTER_MINUTES`, `WORKFLOWS_START_SWEEP_DAYS` | `10`, `7` | Restart of workflows whose start was lost. |
| `WORKFLOWS_EMAILS_PER_SUBMISSION_PER_DAY`, `WORKFLOWS_EMAILS_PER_ORGANIZATION_PER_HOUR` | `25`, `500` | Caps on workflow emails; over a cap the step fails and admins are notified. |
| `WORKFLOWS_WEBHOOK_TIMEOUT`, `WORKFLOWS_WEBHOOK_CONNECT_TIMEOUT` | `10`, `5` | Seconds. |
| `WORKFLOWS_WEBHOOK_ALLOW_PRIVATE_HOSTS` | `false` | Allow webhooks to private addresses and any port. Development only. |
| `WORKFLOWS_MAX_STEPS`, `WORKFLOWS_MAX_DEFINITION_KB`, `WORKFLOWS_MAX_RECIPIENTS`, `WORKFLOWS_MAX_ADDRESS_FIELDS` | `25`, `64`, `50`, `10` | Limits of one workflow definition. |
| *fixed* webhook ports | 80, 443, 8080, 8443 | |
| *fixed* longest review SLA | 2160 hours (90 days) | |

## Reports and exports

`config/reports.php`

| Variable | Default | Meaning |
|---|---|---|
| `REPORTS_EXPORT_DISK` | `local` | Disk for export files. |
| `REPORTS_EXPORT_QUEUE` | `exports` | Queue worked by `supervisor-exports`. |
| `REPORTS_EXPORT_TIMEOUT` | `85` | Seconds per export job; keep below the queue's `retry_after` (90). |
| `REPORTS_EXPORT_KEEP_DAYS` | `7` | Export files are deleted after this many days. |
| `REPORTS_EXPORT_MAX_ROWS` | `50000` | Most rows of one export. |
| `REPORTS_PDF_ZIP_MAX_BYTES`, `REPORTS_PDF_ZIP_MAX_FILES` | `209715200` (200 MB), `2000` | Limits of a PDF ZIP. |
| *fixed* | 6 visible field columns, 10,000 selected rows, 2 pending exports and 10 export requests per minute per user, 500 groups per saved report, scheduled reports at 07:00 organization time | |

## Dashboards

`config/dashboards.php`

| Variable | Default | Meaning |
|---|---|---|
| `DASHBOARD_CACHE_TTL` | `60` | Seconds a widget result is cached. |
| `DASHBOARD_STALE_DRAFT_DAYS` | `14` | A draft counts as stale after this many days without changes. |

## Single sign-on

`config/sso.php`

| Variable | Default | Meaning |
|---|---|---|
| `SSO_ALLOW_INSECURE_IDP` | `false` | Allow `http://` and private-network identity providers (no SSRF guard). Development and CI only; refused in production. |
| `SSO_DNS_DRIVER` | `dns` | `dns` looks up TXT records; `config` reads them from `SSO_DNS_RECORDS` (development and CI only; refused in production). |
| `SSO_DNS_RECORDS` | empty | JSON object `{"<name>": ["<value>"]}` for the `config` driver. |
| `SSO_SAML_SP_CERTIFICATE`, `SSO_SAML_SP_PRIVATE_KEY` | empty | Optional PEM key pair of the SAML service provider: signs authentication requests and is published in the metadata. |
| *fixed* | TXT name `_form-schema-app-verification.<domain>`; unverify after 3 failed daily checks; IdP timeouts 10 s (connect 5 s); metadata cache 1 h; clock skew 60 s; misconfiguration notices at most every 6 h; login flows valid 10 minutes | |

## Files and storage

`config/filesystems.php`

| Variable | Default | Meaning |
|---|---|---|
| `FILESYSTEM_DISK` | `local` | Default disk (`storage/app/private`). |

Only local disks are supported by the image: there is no S3 adapter installed, so the `AWS_*` variables in
`.env.example` have no effect. Attachments always use the `local` disk. Nothing is stored on the public
disk; `php artisan storage:link` is not needed.

## Other

| Variable | Default | Meaning |
|---|---|---|
| `ACTIVITYLOG_ENABLED` | `true` | The audit trail. Keep it enabled. |
| `API_VERSION` | `1.0.0` | Version shown in the OpenAPI document. |
| `SCRAMBLE_DEV_TOOLS` | `APP_DEBUG` | Developer tools on the API documentation pages. |
| `AUTH_PASSWORD_TIMEOUT` | `10800` | Seconds before a password confirmation is asked again. |
| `VITE_APP_NAME` | `APP_NAME` | Build time only. |

> TODO-VERIFY (M8): add the variables introduced by the hardening tasks (audit retention, privacy
> retention, security headers, trusted proxies, error reporting, `/platform`) once they land.
