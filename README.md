# Fieldset

Free, open-source, self-hostable digital forms for small and medium-sized businesses.
Office staff design structured forms in an admin dashboard; field workers fill them on a phone or
tablet (also offline) to produce reports, invoices, checklists and other documents.

- **Admin dashboard** — Laravel 13 + Filament 5 at `/admin`: form builders, designs, workflows,
  submissions inbox, review tasks, reports and dashboards, team, SSO, audit log and privacy tools
- **Field App** — React PWA at `/app`: fill (also offline), sign, photograph, submit, review, send the PDF
- **Fieldset Spec** — open, versioned JSON format for form definitions (`spec/`, MIT) with PHP and
  JavaScript SDKs (`packages/`, MIT)

> Status: **2.0** — distributed as signed Docker images and installed, configured, backed up and upgraded with
> one command-line tool, `fieldset`. See [CHANGELOG.md](CHANGELOG.md) and the [guides](docs/guide/index.md). 1.0 installs
> from a clone of the repository move to 2.0 with `fieldset adopt`.

## What you can do

1. A designer creates a form from a template (service report, invoice, checklist, delivery note,
   timesheet, handover protocol), or imports a fillable PDF, previews it on a phone frame and publishes v1.
2. A field worker fills it in the Field App, signs it and submits; the server assigns the number and
   renders the branded PDF.
3. A workflow assigns a review; the reviewer approves or requests changes; afterwards the customer gets
   the PDF by email. Webhooks notify other systems.

## Features

- **Forms** — six templates, import of fillable PDFs and JSON, a keyboard-operable drag-and-drop canvas
  builder (the default) and the classic builder, logic and exact money calculations, live phone/tablet
  preview in a sandbox, versions with diff, rollback and retire.
- **Field App** — offline-first PWA: drafts on the device, an outbox that syncs exactly once, photos,
  signatures, gap-free numbering by the server, review tasks, notifications, German and English.
- **Documents and email** — branded, versioned designs; immutable PDFs rendered by Gotenberg; mail log
  with retries; "send to customer".
- **Workflows** — triggers, conditions, reviews with SLA reminders, emails, signed webhooks.
- **Reports** — submission explorer across versions, XLSX/CSV/PDF-ZIP exports, saved and scheduled
  reports, role-aware dashboards.
- **Access** — roles and groups, two-factor authentication, single sign-on (OpenID Connect and SAML)
  with domain verification, just-in-time accounts and enforcement.
- **Audit log** — append-only in the database, a hash chain per organization checked by `audit:verify`
  against chain heads kept outside the database, filters, CSV export, a history on key records,
  retention (365 days by default).
- **Privacy (GDPR)** — retention per form (delete or anonymize), legal holds, personal data export and
  account erasure.
- **Integrations** — REST API `/api/v1` with OpenAPI docs; personal API tokens with abilities and their
  own rate limit.
- **Security and operations** — a Content Security Policy per area, HSTS, admin session timeouts,
  trusted proxies and a host allow-list, optional ClamAV upload scanning, JSON logs, Sentry-compatible error reporting, the
  `/platform` operator panel with health checks and audited impersonation, scheduled and encrypted backups,
  restore drills, upgrades with automatic rollback.

## Quick start

On a server or your own computer with Docker:

```bash
curl --proto '=https' --tlsv1.2 -fsSL https://github.com/beyerenrico/fieldset/releases/latest/download/install.sh | sh -s -- --org "Example Ltd" --owner-email owner@example.com
```

This verifies the release signature, installs a local instance and prints its URL and a one-time sign-in link for the
owner. Add `--domain forms.example.com --acme-email ops@example.com` for a public instance with HTTPS, or `--demo`
for sample data. The [install guide](docs/guide/install.md) covers every mode; AI agents and scripts follow the
[agent guide](docs/guide/agent-guide.md). Afterwards:

```bash
./fieldset status                       # in the instance directory (or <dir>/fieldset, or fieldset once linked into PATH)
./fieldset login-link owner@example.com # a new sign-in link
./fieldset config set mail smtp …       # deliver mail instead of capturing it
./fieldset backup                       # a backup now (daily backups run by themselves)
./fieldset upgrade                      # to the newest release, with automatic rollback
```

## Development

Try the source locally with Docker (development settings, Mailpit catches all mail):

```bash
cp .env.example .env
sed -i.bak "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" .env   # once
docker compose up -d --build --wait                        # app on http://localhost:8080
docker compose exec app php artisan app:install            # first organization + owner
```

Development with PHP 8.4 on the host:

```bash
cp .env.example .env && composer install && php artisan key:generate
docker compose up -d postgres redis gotenberg mailpit      # services on non-default host ports
php artisan migrate --seed                                 # demo data, password "password"
php artisan serve --port=8080
php artisan horizon                                        # queue workers: `default` and `exports` (report exports, one at a time)
php artisan test --compact
```

Demo users (seeded with `php artisan db:seed`, password `password`): `owner@demo.test`,
`admin@demo.test`, `designer@demo.test`, `reviewer@demo.test`, `coordinator@demo.test`,
`worker@demo.test`, `mobile@demo.test` and `tablet@demo.test` (more field workers), `viewer@demo.test`. The demo organization has a published service report and a
"review, then email the PDF to the customer" workflow. Mailpit UI: http://localhost:18025.

| URL | What |
|---|---|
| http://localhost:8080/admin | Admin dashboard |
| http://localhost:8080/app | Field App (use a phone-sized window) |
| http://localhost:8080/docs/api | OpenAPI documentation (admins) |
| http://localhost:8080/platform | Operator panel (after `php artisan app:platform-admin <email>`) |

Checks: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --memory-limit=1G`,
`vendor/bin/rector --dry-run`, `./scripts/coverage.sh`, `npm run typecheck lint test`,
`npm run conformance`, `npm run e2e` (against the Docker stack). Releases: [RELEASING.md](RELEASING.md).

## Documentation

Guides in [`docs/guide/`](docs/guide/index.md):

- [Install](docs/guide/install.md) · [Agent guide](docs/guide/agent-guide.md) ·
  [Upgrade](docs/guide/upgrade.md) · [Backup and restore](docs/guide/backup-restore.md) ·
  [Configuration](docs/guide/configuration.md) · [Troubleshooting](docs/guide/troubleshooting.md) ·
  [1.0 archive](docs/guide/archive/1.0-install.md)
- [Admin guide](docs/guide/admin-guide.md) · [Field App guide](docs/guide/field-app-guide.md) ·
  [API and integrations](docs/guide/api.md)
- [Changelog](CHANGELOG.md) · [Release notes](docs/releases/1.0.0.md) ·
  [Architecture decisions](docs/adr/)

## Architecture

One Laravel monolith organized by domain (`app/Domain/*`). Row-level multi-tenancy by
`organization_id`; the tenant travels in Laravel's `Context`, so queued jobs restore it before
models are unserialized. See `docs/adr/` for every decision.

## Licenses

- Application: **AGPL-3.0-only** (`LICENSE`)
- Fieldset Spec (`spec/`) and SDK packages (`packages/fieldset-php`, `packages/fieldset-js`, `packages/fieldset-react`): **MIT**

## Security

See [SECURITY.md](SECURITY.md) for how to report vulnerabilities.
