# form-schema-app

Free, open-source, self-hostable digital forms for small and medium-sized businesses.
Office staff design structured forms in an admin dashboard; field workers fill them on a phone or
tablet (also offline) to produce reports, invoices, checklists and other documents.

- **Admin dashboard** — Laravel 13 + Filament 5 at `/admin`
- **Field App** — offline-first React PWA at `/app` (from milestone M2)
- **Form Schema Spec** — open, versioned JSON format for form definitions (`spec/`, MIT)

> Status: under active development (milestone M0 — foundation). See `docs/agent/PLAN.md`.

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
php artisan test --compact
```

Demo users (seeded, password `password`): `owner@demo.test`, `admin@demo.test`, `designer@demo.test`,
`reviewer@demo.test`, `coordinator@demo.test`, `worker@demo.test`, `viewer@demo.test`.
Mailpit UI: http://localhost:18025.

## Architecture

One Laravel monolith organized by domain (`app/Domain/*`). Row-level multi-tenancy by
`organization_id`; the tenant travels in Laravel's `Context`, so queued jobs restore it before
models are unserialized. See `docs/adr/` for every decision.

## Licenses

- Application: **AGPL-3.0-only** (`LICENSE`)
- Form Schema Spec (`spec/`) and SDK packages (`packages/form-schema-php`, `packages/form-schema-js`): **MIT**

## Security

See [SECURITY.md](SECURITY.md) for how to report vulnerabilities.
