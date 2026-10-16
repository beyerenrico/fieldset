#!/bin/sh
# Runs the PHP suite with coverage inside the CI image against the compose Postgres.
set -e
docker build -q -t form-schema-app-ci -f docker/ci/Dockerfile docker/ci > /dev/null
mkdir -p storage/coverage
docker run --rm --network form-schema-app_default -v "$PWD":/app -w /app \
  -e DB_HOST=postgres -e DB_PORT=5432 -e REDIS_HOST=redis \
  form-schema-app-ci sh -c 'php -d pcov.enabled=1 -d memory_limit=2G artisan test --compact --coverage-clover=storage/coverage/clover.xml --min=80; status=$?; php scripts/coverage-thresholds.php storage/coverage/clover.xml; exit $status'
