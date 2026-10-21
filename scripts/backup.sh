#!/usr/bin/env bash
# Back up a Fieldset Docker Compose installation (docs/guide/backup-restore.md):
#   database.dump    PostgreSQL, pg_dump custom format (run inside the postgres container)
#   storage.tar.gz   the app-storage volume (/var/www/html/storage/app: renditions, attachments, logos, …)
#   env              a copy of .env, only with --include-env (it holds APP_KEY and other secrets)
#   manifest.txt     what was backed up; SHA256SUMS: checksums of every file
#
# Each run writes <dest>/<UTC timestamp>/ (first as <timestamp>.partial, renamed when complete) and then
# prunes complete backups older than --keep-days, always keeping the newest --min-keep. It only reads
# production data; it never changes the database or the volume.
set -euo pipefail

usage() {
    cat <<'EOF'
Usage: scripts/backup.sh --dest DIR [options]

  --dest DIR        Directory for backups (or set BACKUP_DEST). Must be outside the repository.
  --keep-days N     Delete complete backups older than N days (default 14; 0 keeps everything).
  --min-keep N      Always keep the newest N backups, whatever their age (default 3).
  --no-storage      Skip the storage volume (database only).
  --include-env     Also copy .env (contains APP_KEY and secrets; store the backup accordingly).
  -h, --help        Show this help.

Run it from any directory; it uses the Compose project of this repository (compose.yaml and .env).
EOF
}

die() { echo "backup: error: $*" >&2; exit 1; }
warn() { echo "backup: warning: $*" >&2; }
info() { echo "backup: $*"; }

is_uint() { case "$1" in '' | *[!0-9]*) return 1 ;; *) return 0 ;; esac; }

sha256() {
    if command -v sha256sum >/dev/null 2>&1; then sha256sum "$@"; else shasum -a 256 "$@"; fi
}

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
DEST="${BACKUP_DEST:-}"
KEEP_DAYS=14
MIN_KEEP=3
WITH_STORAGE=1
WITH_ENV=0

while [ $# -gt 0 ]; do
    case "$1" in
        --dest) [ $# -ge 2 ] || die "--dest needs a directory"; DEST="$2"; shift 2 ;;
        --keep-days) [ $# -ge 2 ] || die "--keep-days needs a number"; KEEP_DAYS="$2"; shift 2 ;;
        --min-keep) [ $# -ge 2 ] || die "--min-keep needs a number"; MIN_KEEP="$2"; shift 2 ;;
        --no-storage) WITH_STORAGE=0; shift ;;
        --include-env) WITH_ENV=1; shift ;;
        -h | --help) usage; exit 0 ;;
        *) usage >&2; die "unknown option: $1" ;;
    esac
done

[ -n "$DEST" ] || { usage >&2; die "--dest is required"; }
is_uint "$KEEP_DAYS" || die "--keep-days must be a whole number"
is_uint "$MIN_KEEP" || die "--min-keep must be a whole number"
command -v docker >/dev/null 2>&1 || die "docker not found"

inside_repo() { case "$1/" in "$ROOT/"*) return 0 ;; *) return 1 ;; esac; }
REPO_MSG="--dest must be outside the repository ($ROOT): backups contain personal data and must never be committed"
case "$DEST" in /*) ;; *) DEST="$PWD/$DEST" ;; esac
if inside_repo "$DEST"; then die "$REPO_MSG"; fi
umask 077
mkdir -p "$DEST"
DEST="$(cd "$DEST" && pwd -P)"
if inside_repo "$DEST"; then die "$REPO_MSG"; fi

cd "$ROOT"
RUNNING="$(docker compose ps --status running --services)"
grep -qx postgres <<<"$RUNNING" || die "the postgres service is not running (docker compose up -d postgres)"

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
FINAL="$DEST/$STAMP"
WORK="$FINAL.partial"
if [ -e "$FINAL" ] || [ -e "$WORK" ]; then die "$FINAL already exists"; fi
mkdir "$WORK"
DONE=0
cleanup() { if [ "$DONE" -ne 1 ]; then rm -rf -- "$WORK"; fi; }
trap cleanup EXIT

# psql against the production database, read-only queries only. The SQL is passed as $1 of `sh -c`.
# shellcheck disable=SC2016  # $POSTGRES_* expand inside the container, not here
psql_prod() { docker compose exec -T postgres sh -c 'exec psql -X -A -t -v ON_ERROR_STOP=1 --username="$POSTGRES_USER" --dbname="$POSTGRES_DB" -c "$1"' sh "$1"; }

# 1. Database first: every file a row points to already exists when the storage copy starts.
info "dumping the database …"
# shellcheck disable=SC2016
docker compose exec -T postgres sh -c 'exec pg_dump --username="$POSTGRES_USER" --dbname="$POSTGRES_DB" --format=custom --no-owner --no-privileges' >"$WORK/database.dump"
docker compose exec -T postgres pg_restore --list <"$WORK/database.dump" >/dev/null || die "the dump is not a readable pg_dump archive"

# 2. Storage volume, read through the app image (exec when app runs, else a one-off container).
if [ "$WITH_STORAGE" -eq 1 ]; then
    info "archiving the storage volume …"
    TAR_ARGS=(tar -C /var/www/html/storage/app -czf - .)
    status=0
    if grep -qx app <<<"$RUNNING"; then
        docker compose exec -T app "${TAR_ARGS[@]}" >"$WORK/storage.tar.gz" || status=$?
    else
        docker compose run --rm --no-deps -T --entrypoint tar app "${TAR_ARGS[@]:1}" >"$WORK/storage.tar.gz" || status=$?
    fi
    case "$status" in
        0) ;;
        1) warn "some files changed while they were archived (normal on a running system)" ;;
        *) die "tar failed with exit code $status" ;;
    esac
fi

# 3. Optional .env copy.
if [ "$WITH_ENV" -eq 1 ]; then
    [ -f "$ROOT/.env" ] || die ".env not found in $ROOT"
    cp "$ROOT/.env" "$WORK/env"
    chmod 600 "$WORK/env"
else
    warn ".env was not copied. Keep APP_KEY somewhere safe: without it encrypted fields cannot be read."
fi

# 4. Manifest and checksums.
{
    echo "created_at=$STAMP"
    echo "postgres=$(docker compose exec -T postgres postgres --version)"
    echo "migrations=$(psql_prod 'SELECT count(*) FROM migrations')"
    echo "last_migration=$(psql_prod 'SELECT migration FROM migrations ORDER BY id DESC LIMIT 1')"
    echo "organizations=$(psql_prod 'SELECT count(*) FROM organizations')"
    echo "submissions=$(psql_prod 'SELECT count(*) FROM submissions')"
    echo "storage=$([ "$WITH_STORAGE" -eq 1 ] && echo included || echo skipped)"
    echo "env=$([ "$WITH_ENV" -eq 1 ] && echo included || echo skipped)"
} >"$WORK/manifest.txt"
FILES=(database.dump manifest.txt)
[ "$WITH_STORAGE" -eq 1 ] && FILES+=(storage.tar.gz)
[ "$WITH_ENV" -eq 1 ] && FILES+=(env)
(cd "$WORK" && sha256 "${FILES[@]}" >SHA256SUMS)

mv "$WORK" "$FINAL"
DONE=1
info "backup complete: $FINAL ($(du -sh "$FINAL" | cut -f1))"

# 5. Retention: only directories named like a complete backup are ever deleted.
PATTERN='[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z'
find "$DEST" -mindepth 1 -maxdepth 1 -type d -name "$PATTERN.partial" -mtime +1 -exec rm -rf -- {} +
if [ "$KEEP_DAYS" -gt 0 ]; then
    n=0
    while IFS= read -r dir; do
        n=$((n + 1))
        [ "$n" -le "$MIN_KEEP" ] && continue
        if [ -n "$(find "$dir" -maxdepth 0 -mtime +"$KEEP_DAYS")" ]; then
            rm -rf -- "$dir"
            info "pruned $(basename "$dir")"
        fi
    done < <(find "$DEST" -mindepth 1 -maxdepth 1 -type d -name "$PATTERN" | sort -r)
fi
