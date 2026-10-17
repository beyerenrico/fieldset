# Install guide (Docker Compose, production)

This guide installs form-schema-app on one Linux server with Docker Compose. For development on your
own machine, see the README quick start instead.

Contents:
- [What runs where](#what-runs-where)
- [Requirements](#requirements)
- [1. Get the code](#1-get-the-code)
- [2. Create the .env file](#2-create-the-env-file)
- [3. Build and start](#3-build-and-start)
- [4. Create the first organization and owner](#4-create-the-first-organization-and-owner)
- [5. Reverse proxy and TLS](#5-reverse-proxy-and-tls)
- [6. Check the installation](#6-check-the-installation)
- [Settings that must never reach production](#settings-that-must-never-reach-production)
- [Next steps](#next-steps)

## What runs where

`compose.yaml` defines the production stack. All three application services use the same image,
built from `docker/app/Dockerfile` (FrankenPHP with PHP 8.4, ADR 0020):

| Service | What it does |
|---|---|
| `app` | Web server for the admin dashboard (`/admin`), the Field App (`/app`) and the API (`/api/v1`), plain HTTP on port 8080. Runs the database migrations on every start. |
| `queue` | Horizon queue workers: PDFs, emails, workflows, notifications (`default` queue) and report exports (`exports` queue, one at a time). |
| `scheduler` | `php artisan schedule:work`: scheduled workflows and reminders, the PDF safety sweep, scheduled report emails, SSO health checks, clean-up jobs. |
| `postgres` | PostgreSQL 16 (volume `pg-data`). |
| `redis` | Valkey 8: queues, cache, locks. No published port. |
| `gotenberg` | PDF rendering with Chromium. It sits on the internal network `renderer`, shared only with `app` and `queue`, has no internet access, and may not fetch any private or public address. |

Uploaded files, PDFs and logos live in the volume `app-storage` (`/var/www/html/storage/app`), which
`app`, `queue` and `scheduler` share.

`compose.dev.yaml` is for development only: it publishes the database, Redis and Gotenberg ports, adds
Mailpit and the Keycloak identity provider, and switches on SSO test settings. Never use it in
production (see [below](#settings-that-must-never-reach-production)).

There is no prebuilt image in a registry: you build the image from the release you check out.

## Requirements

- A Linux server with Docker Engine and the Compose plugin v2 (`docker compose version`). Building the
  image needs internet access to download base images and packages.
- A DNS name for the instance, for example `forms.example.com`, and a reverse proxy that terminates
  TLS (Caddy, nginx, Traefik, a load balancer). The app itself only speaks HTTP.
- An SMTP server or relay for outgoing mail. The image ships Laravel's SMTP transport (plus `sendmail`
  and `log`); API transports such as Postmark, Resend or SES need extra Composer packages and are not
  included.
- PostgreSQL 15 or newer if you replace the bundled database (the migrations use `NULLS NOT
  DISTINCT`); the stack and the tests use PostgreSQL 16.
- A place outside the server for backups ([backup guide](backup-restore.md)).

## 1. Get the code

```bash
git clone <repository URL> form-schema-app
cd form-schema-app
git tag                      # list releases
git checkout v1.0.0          # the release you install
```

Keep this directory: Compose uses it as the project directory, and upgrades start from it.

## 2. Create the .env file

```bash
cp .env.example .env
chmod 600 .env
```

`.env.example` is set up for **development**. Change at least the following lines. Every service reads
`.env`, and its values override the defaults built into the image.

```dotenv
# Use only the production stack (the example file adds compose.dev.yaml).
COMPOSE_FILE=compose.yaml

APP_ENV=production
APP_DEBUG=false
APP_URL=https://forms.example.com
# Listen on the loopback interface only; your reverse proxy forwards to it.
APP_PORT=127.0.0.1:8080

# Logs as JSON on stderr, so `docker compose logs` shows them.
LOG_CHANNEL=stderr
LOG_LEVEL=info

DB_DATABASE=form_schema
DB_USERNAME=form_schema
DB_PASSWORD=<a long random password>

SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=forms.example.com

MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=<user>
MAIL_PASSWORD=<password>
MAIL_FROM_ADDRESS="forms@example.com"
MAIL_FROM_NAME="Example Forms"

# Who may open the Horizon queue dashboard at /horizon.
PLATFORM_ADMIN_EMAILS=it@example.com
```

Then generate the application key:

```bash
sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" .env
```

**Store a copy of `APP_KEY` somewhere safe, outside the server** (for example in a password manager).
The key encrypts SMTP passwords, identity provider secrets, webhook secrets, two-factor secrets and
queued emails. If it is lost, these values cannot be read again and every user with two-factor
authentication is locked out. See [Backup and restore](backup-restore.md#what-to-back-up).

Notes on these settings:

| Setting | Why |
|---|---|
| `COMPOSE_FILE=compose.yaml` | Without it, Compose also loads `compose.dev.yaml`, and the app refuses to boot in production because of the SSO test settings in that file. |
| `APP_ENV=production`, `APP_DEBUG=false` | The example file has `local` and `true`. In `local`, the Horizon dashboard is open to everyone and errors show stack traces. |
| `APP_URL` | The public `https://` address. Links in emails and everything else generated in the background (queue, scheduler, console) use it. |
| `APP_PORT=127.0.0.1:8080` | Compose publishes `${APP_PORT}:8080`. This value binds it to the loopback interface so that only the reverse proxy on the same server can reach the app. Use a plain port such as `8080` if the proxy runs elsewhere, and firewall it. |
| `LOG_CHANNEL=stderr` | The example file logs to a file inside the container, which is lost when the container is recreated. The image formats stderr logs as JSON. |
| `DB_USERNAME` | Keep `form_schema`. `docker/postgres/init/01-test-db.sql` creates test databases owned by `form_schema` when the database volume is first created; with another user name the first start of `postgres` fails. |
| `DB_PASSWORD`, `DB_DATABASE` | Used to create the database on the first start only. Changing them later does not change the existing database. `DB_HOST` and `DB_PORT` in `.env` are ignored by the containers (Compose sets `postgres:5432`). |
| `SESSION_SECURE_COOKIE=true` | Session cookies only over HTTPS. |
| `SANCTUM_STATEFUL_DOMAINS` | The host name of `APP_URL` (no scheme, add `:port` if not 443). The Field App's sign-in cookie only works for hosts in this list; the example file lists only `localhost`. |
| `MAIL_*` | The instance-wide mail transport. `MAIL_FROM_ADDRESS` should be on your own domain with SPF/DKIM set up; organizations set their own sender name and reply-to in the dashboard. Port 465 uses implicit TLS; other ports use STARTTLS when the server offers it. |
| `PLATFORM_ADMIN_EMAILS` | Comma-separated emails of operators. They can open `/horizon` (after signing in at `/admin/login`) and the API documentation. |

Leave these as they are in the example file: `SESSION_DRIVER=database`, `SESSION_LIFETIME=43200`
(30 days, needed for offline field work), `SESSION_ENCRYPT=true`, `QUEUE_CONNECTION=redis` (Horizon
needs Redis), `CACHE_STORE=redis`, `APP_ALLOW_REGISTRATION=false`, `SSO_ALLOW_INSECURE_IDP=false` and
`SSO_DNS_DRIVER=dns`. All other settings are listed in the [configuration reference](configuration.md).

## 3. Build and start

```bash
docker compose build
docker compose up -d --wait
docker compose ps
```

On every start the `app` container caches the configuration and runs `php artisan migrate --force`.
`queue` and `scheduler` do not wait for the migrations; on the very first start they may restart once
until the tables exist.

After changing `.env`, recreate the containers so that they read it again (a plain `restart` keeps the
old environment):

```bash
docker compose up -d --force-recreate app queue scheduler
```

## 4. Create the first organization and owner

```bash
docker compose exec app php artisan app:install
```

The command asks for the organization name, the owner's name, email and password (at least 10
characters). You can pass them as options instead:

```bash
docker compose exec app php artisan app:install \
  --organization="Example GmbH" --name="Erika Muster" --email=erika@example.com --locale=de
```

`--locale` is `de` (default) or `en`. Without `--password` the command prompts for it, which keeps the
password out of your shell history. The owner's email address is marked as verified.

The command only works once: if an organization exists, it changes nothing. Further organizations are
created by enabling self-registration (`APP_ALLOW_REGISTRATION=true`, only for a public instance); each
registering user then creates their own organization at `/admin`.

Sign in at `https://forms.example.com/admin/login` and continue with the [admin guide](admin-guide.md):
branding, members, forms. Set up two-factor authentication for the owner account in the profile.

## 5. Reverse proxy and TLS

The reverse proxy terminates TLS and forwards all paths to `http://127.0.0.1:8080`. Requirements:
- forward the original `Host` header and set `X-Forwarded-For`, `X-Forwarded-Proto` and
  `X-Forwarded-Host`;
- allow request bodies of at least 30 MB (fillable PDF imports are up to 20 MB, attachments up to 25 MB;
  PHP accepts up to 30 MB);
- allow responses to take up to about 60 seconds (PDF previews are rendered while you wait);
- do not cache `/app/`, `/api/` or `/admin/` responses. The Field App's service worker handles offline
  caching itself.

> **TODO-VERIFY (trusted proxies, M8-T03):** at the time of writing the application does not trust
> `X-Forwarded-*` headers from any proxy (no `trustProxies` setting in `bootstrap/app.php`, no
> `config/trustedproxy.php`). Behind a TLS-terminating proxy it then builds `http://` URLs for assets
> and redirects, so the browser blocks the admin dashboard's scripts as mixed content, SSO redirect URIs
> start with `http://`, and per-IP rate limits apply to the proxy's address. Document the setting that
> fixes this (for example a `TRUSTED_PROXIES` variable) here once it exists.

Example with Caddy on the same server (Caddy obtains and renews the certificate):

```caddyfile
forms.example.com {
    request_body {
        max_size 30MB
    }
    reverse_proxy 127.0.0.1:8080
}
```

Example with nginx:

```nginx
server {
    listen 443 ssl;
    http2 on;
    server_name forms.example.com;
    ssl_certificate     /etc/letsencrypt/live/forms.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/forms.example.com/privkey.pem;

    client_max_body_size 30m;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_read_timeout 90s;
    }
}
```

The Field App needs HTTPS: browsers only install web apps and run service workers on secure origins.

## 6. Check the installation

```bash
docker compose ps                                   # app and queue "healthy", scheduler running
curl -fsS https://forms.example.com/up              # 200: the application boots
docker compose exec queue php artisan horizon:status
docker compose exec app php artisan schedule:list   # the scheduled tasks
docker compose logs -f app queue scheduler
```

`/up` only shows that the application boots, not that the database or Redis are reachable. Then, in the
dashboard:
1. "E-Mail-Einstellungen" ("Mail settings") → "Test-E-Mail senden" ("Send test email"), and check that
   it arrives and is "Gesendet" ("Sent") in "E-Mail-Protokoll" ("Mail log"). This also proves that the
   queue runs.
2. Create a form from a template, open "PDF-Vorschau" ("Preview PDF"): this proves that Gotenberg works.
3. Open `https://forms.example.com/app` on a phone, sign in and install the app.

## Settings that must never reach production

| Setting | Where it comes from | What happens in production |
|---|---|---|
| `SSO_ALLOW_INSECURE_IDP=true` | `compose.dev.yaml` (for the local Keycloak) | Allows `http://` and private-network identity providers without the SSRF guard. The app refuses to boot when `APP_ENV=production`. |
| `SSO_DNS_DRIVER=config` with `SSO_DNS_RECORDS` | `compose.dev.yaml` and CI | Domain verification reads fake TXT records from the configuration instead of DNS. The app refuses to boot when `APP_ENV=production`. |
| The `keycloak` service (profile `sso`) | `compose.dev.yaml` | A development identity provider with the fixed admin password `admin` and demo users. For development and E2E tests only; use your real identity provider. |
| `mailpit` | `compose.dev.yaml` | Catches all mail instead of delivering it. |
| `MESSAGING_SMTP_ALLOW_PRIVATE_HOSTS=true`, `WORKFLOWS_WEBHOOK_ALLOW_PRIVATE_HOSTS=true` | development | Lift the SSRF guards for organization SMTP servers and webhooks, so tenants could reach internal services. |
| `APP_ENV=local`, `APP_DEBUG=true` | `.env.example` | Stack traces for visitors; Horizon dashboard open to everyone. |
| Demo data (`php artisan db:seed`) | development | Creates demo users with the password `password`. Never seed a production database. |

## Next steps

- Back up the database, the storage volume and `APP_KEY`: [Backup and restore](backup-restore.md).
- Tune limits and optional settings: [Configuration reference](configuration.md).
- Upgrading later: [Upgrade guide](upgrade.md).
- If something does not work: [Troubleshooting](troubleshooting.md).
