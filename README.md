# form-schema-app

Free, open-source, self-hostable digital forms for small and medium-sized businesses.
Office staff design structured forms in an admin dashboard; field workers fill them on a phone or
tablet (also offline) to produce reports, invoices, checklists and other documents.

- **Admin dashboard** — Laravel 13 + Filament 5 at `/admin`: form builder, designs, workflows,
  submissions inbox, review tasks, team and mail settings
- **Field App** — React PWA at `/app`: fill, sign, photograph, submit, review, send the PDF
- **Form Schema Spec** — open, versioned JSON format for form definitions (`spec/`, MIT) with PHP and
  JavaScript SDKs (`packages/`, MIT)

> Status: **0.1.0 (MVP)** — see [CHANGELOG.md](CHANGELOG.md) and
> [release notes](docs/releases/0.1.0.md). Full offline mode, reports, SSO and the canvas builder are
> planned for 1.0 (`docs/agent/PLAN.md`).

## What you can do

1. A designer creates a form from a template (service report, invoice, checklist, delivery note,
   timesheet, handover protocol), or imports a fillable PDF, previews it on a phone frame and publishes v1.
2. A field worker fills it in the Field App, signs it and submits; the server assigns the number and
   renders the branded PDF.
3. A workflow assigns a review; the reviewer approves or requests changes; afterwards the customer gets
   the PDF by email. Webhooks notify other systems.

## Quick start (Docker)

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
`worker@demo.test`, `mobile@demo.test` (second field worker), `viewer@demo.test`. The demo organization has a published service report and a
"review, then email the PDF to the customer" workflow. Mailpit UI: http://localhost:18025.

| URL | What |
|---|---|
| http://localhost:8080/admin | Admin dashboard |
| http://localhost:8080/app | Field App (use a phone-sized window) |
| http://localhost:8080/docs/api | OpenAPI documentation (admins) |

Checks: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --memory-limit=1G`,
`vendor/bin/rector --dry-run`, `./scripts/coverage.sh`, `npm run typecheck lint test`,
`npm run conformance`, `npm run e2e` (against the Docker stack).

## Architecture

One Laravel monolith organized by domain (`app/Domain/*`). Row-level multi-tenancy by
`organization_id`; the tenant travels in Laravel's `Context`, so queued jobs restore it before
models are unserialized. See `docs/adr/` for every decision.

## Licenses

- Application: **AGPL-3.0-only** (`LICENSE`)
- Form Schema Spec (`spec/`) and SDK packages (`packages/form-schema-php`, `packages/form-schema-js`): **MIT**

## Security

See [SECURITY.md](SECURITY.md) for how to report vulnerabilities.
