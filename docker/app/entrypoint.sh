#!/bin/sh
set -e
# Cache config/routes/views on start so env changes apply without a rebuild.
php artisan optimize --quiet || true
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  php artisan migrate --force --no-interaction
fi
exec "$@"
