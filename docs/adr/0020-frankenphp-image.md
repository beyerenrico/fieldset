# 0020: Single FrankenPHP-based image for app, queue and scheduler
- Status: accepted
- Date: 2026-10-16

## Decision
`docker/app/Dockerfile` builds one image on `dunglas/frankenphp:1-php8.4-bookworm` (MIT) in classic
mode (no Octane), with pdo_pgsql, intl, gd, zip, bcmath, exif, pcntl, redis and the qpdf CLI. The
`app` service serves HTTP on 8080 and runs migrations on start (`RUN_MIGRATIONS=true`); `queue` runs
Horizon; `scheduler` runs `schedule:work`. Dev ports and Mailpit live in `compose.dev.yaml`.

## Consequences
No nginx/php-fpm pair to maintain. Long-running worker safety relies on scoped bindings (§6.2).
