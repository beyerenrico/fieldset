#!/bin/sh
set -e
# One image, several roles (ADR 0065). The app, queue and scheduler cache config/routes/views on start, so env
# changes apply without a rebuild, and migrate only with RUN_MIGRATIONS=true. The ops role (`fsa <command>` or
# `php artisan fsa:<command>`) and the backup daemon (`fsa backup-daemon`) run as other users, often without a
# database, and must not write bootstrap/cache: they skip both.
is_ops() {
	case "${1##*/}" in
	fsa) return 0 ;;
	php) [ "${2:-}" = artisan ] && case "${3:-}" in fsa:*) return 0 ;; esac ;;
	esac
	return 1
}

if ! is_ops "$@"; then
	php artisan optimize --quiet || true
	if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
		php artisan migrate --force --no-interaction
	fi
fi
exec "$@"
