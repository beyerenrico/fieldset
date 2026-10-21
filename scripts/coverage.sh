#!/bin/sh
# Runs the PHP suite with coverage inside the CI image against the compose Postgres.
# Run it from the main checkout: the container reads the mounted .env (a worktree's .env symlink does not resolve in
# it). Never pass `--env-file`: real environment variables beat phpunit.xml's <env> (APP_ENV=local, CSRF 419s).
# Every host-mapped address of the dev .env is overridden with its in-network one. It runs as the caller, not root,
# like CI's runner: root ignores file modes, so permission tests (e.g. a backup that cannot be pruned) would fail.
set -e
docker build -q -t fieldset-ci -f docker/ci/Dockerfile docker/ci > /dev/null
mkdir -p storage/coverage
docker run --rm --network fieldset_default --user "$(id -u):$(id -g)" -e HOME=/tmp -v "$PWD":/app -w /app \
  -e DB_HOST=postgres -e DB_PORT=5432 -e REDIS_HOST=redis -e REDIS_PORT=6379 \
  -e MAIL_HOST=mailpit -e MAIL_PORT=1025 \
  -e GOTENBERG_URL=http://gotenberg:3000 -e MAILPIT_API_URL=http://mailpit:8025 \
  -e MAILPIT_SMTP_HOST=mailpit -e MAILPIT_SMTP_PORT=1025 \
  fieldset-ci sh -c 'php -d pcov.enabled=1 -d pcov.directory=/app -d pcov.exclude="~/(vendor|node_modules)/~" -d memory_limit=2G vendor/bin/pest --compact --coverage-clover=storage/coverage/clover.xml --min=80; status=$?; php scripts/coverage-thresholds.php storage/coverage/clover.xml || status=1; exit $status'
