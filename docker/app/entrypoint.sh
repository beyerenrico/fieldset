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

# App containers that start together (replicas, a restart during a slow migration) take turns (ADR 0079):
# `--isolated` holds a Postgres advisory lock that dies with its session, and exits 75 while another container
# holds it. Wait up to five minutes (the stack file's start period), then fail, so the app never starts on a
# schema that is still being migrated.
migrate() {
	attempt=1
	while :; do
		status=0
		php artisan migrate --force --isolated=75 --no-interaction || status=$?
		[ "$status" = 75 ] || return "$status"
		if [ "$attempt" -ge 60 ]; then
			echo 'entrypoint: another container is still migrating after 5 minutes; giving up.' >&2
			return 75
		fi
		attempt=$((attempt + 1))
		sleep 5
	done
}

if ! is_ops "$@"; then
	php artisan optimize --quiet || true
	if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
		migrate
	fi
fi
exec "$@"
