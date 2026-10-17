# form-schema-app

Free, open-source, self-hostable digital forms for small and medium-sized businesses.
Office staff design structured forms in an admin dashboard; field workers fill them on a phone or
tablet (also offline) to produce reports, invoices, checklists and other documents.

- **Admin dashboard** — Laravel 13 + Filament 5 at `/admin`: form builders, designs, workflows,
  submissions inbox, review tasks, reports and dashboards, team, SSO, audit log and privacy tools
- **Field App** — React PWA at `/app`: fill (also offline), sign, photograph, submit, review, send the PDF
- **Form Schema Spec** — open, versioned JSON format for form definitions (`spec/`, MIT) with PHP and
  JavaScript SDKs (`packages/`, MIT)

> Status: **1.0.0** — the first stable release: everything from 0.2 plus single sign-on, the canvas
> builder, the audit log, GDPR tools and security hardening. See [CHANGELOG.md](CHANGELOG.md), the
> [1.0.0 release notes](docs/releases/1.0.0.md) and the [guides](docs/guide/index.md).

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
  `/platform` operator panel with health checks and audited impersonation, backup and restore-drill
  scripts.

## Quick start

**Production:** follow the [install guide](docs/guide/install.md). It starts from
`.env.production.example` and covers the reverse proxy, `TRUSTED_PROXIES`, mail and backups.

**Try it locally with Docker** (development settings, Mailpit catches all mail):

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
`npm run conformance`, `npm run e2e` (against the Docker stack).

## Documentation

Guides in [`docs/guide/`](docs/guide/index.md):

- [Install (production, Docker Compose)](docs/guide/install.md) ·
  [Upgrade from 0.x](docs/guide/upgrade.md) · [Backup and restore](docs/guide/backup-restore.md) · [Configuration](docs/guide/configuration.md) ·
  [Troubleshooting](docs/guide/troubleshooting.md)
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
- Form Schema Spec (`spec/`) and SDK packages (`packages/form-schema-php`, `packages/form-schema-js`): **MIT**

## Security

See [SECURITY.md](SECURITY.md) for how to report vulnerabilities.
