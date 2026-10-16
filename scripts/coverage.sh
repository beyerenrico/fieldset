#!/bin/sh
# Runs the PHP suite with coverage inside the CI image against the compose Postgres.
set -e
docker build -q -t form-schema-app-ci -f docker/ci/Dockerfile docker/ci > /dev/null
mkdir -p storage/coverage
docker run --rm --network form-schema-app_default -v "$PWD":/app -w /app \
  -e DB_HOST=postgres -e DB_PORT=5432 -e REDIS_HOST=redis \
  -e GOTENBERG_URL=http://gotenberg:3000 -e MAILPIT_API_URL=http://mailpit:8025 \
  -e MAILPIT_SMTP_HOST=mailpit -e MAILPIT_SMTP_PORT=1025 \
  form-schema-app-ci sh -c 'php -d pcov.enabled=1 -d pcov.directory=/app -d pcov.exclude="~/(vendor|node_modules)/~" -d memory_limit=2G vendor/bin/pest --compact --coverage-clover=storage/coverage/clover.xml --min=80; status=$?; php scripts/coverage-thresholds.php storage/coverage/clover.xml || status=1; exit $status'
