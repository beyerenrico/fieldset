#!/usr/bin/env bash
# Restore drill for a backup made by scripts/backup.sh (docs/guide/backup-restore.md).
#
# Restores the database dump into a NEW scratch database (name must start with restore_drill_) on the
# Compose project's postgres service and runs smoke checks:
#   1. checksums of the backup files (SHA256SUMS)
#   2. pg_restore into the scratch database without errors
#   3. row counts of the main tables (at least one organization)
#   4. storage: every attachment and rendition row has its file in storage.tar.gz, and the newest
#      files match their recorded SHA-256 (read from the archive, nothing is written to the volume)
#   5. app check (one-off container of the app image against the scratch database): pending or unknown
#      migrations, and whether the APP_KEY in .env decrypts the encrypted columns
#
# It never writes to the production database or the storage volume, refuses an existing database, and
# drops only the scratch database it created itself (unless --keep).
set -euo pipefail

usage() {
    cat <<'EOF'
Usage: scripts/restore-drill.sh --backup DIR --scratch-db restore_drill_NAME [options]

  --backup DIR        A complete backup directory written by scripts/backup.sh.
  --scratch-db NAME   New database to restore into; must match restore_drill_[a-z0-9_]+ and must not exist.
  --sample N          Files per table whose SHA-256 is verified (default 50; 0 skips the hash check).
  --skip-app-check    Skip the app check (migrations, APP_KEY).
  --keep              Keep the scratch database and extracted files for inspection.
  -h, --help          Show this help.
EOF
}

die() { echo "restore-drill: error: $*" >&2; exit 2; }
info() { echo "restore-drill: $*"; }
pass() { echo "PASS  $*"; }
fail() { echo "FAIL  $*"; FAILURES=$((FAILURES + 1)); }
note() { echo "NOTE  $*"; }

is_uint() { case "$1" in '' | *[!0-9]*) return 1 ;; *) return 0 ;; esac; }

sha256_check() {
    if command -v sha256sum >/dev/null 2>&1; then sha256sum --quiet -c SHA256SUMS; else shasum -a 256 -q -c SHA256SUMS; fi
}
sha256_of() {
    if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi
}

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
BACKUP=""
SCRATCH=""
SAMPLE=50
APP_CHECK=1
KEEP=0

while [ $# -gt 0 ]; do
    case "$1" in
        --backup) [ $# -ge 2 ] || die "--backup needs a directory"; BACKUP="$2"; shift 2 ;;
        --scratch-db) [ $# -ge 2 ] || die "--scratch-db needs a name"; SCRATCH="$2"; shift 2 ;;
        --sample) [ $# -ge 2 ] || die "--sample needs a number"; SAMPLE="$2"; shift 2 ;;
        --skip-app-check) APP_CHECK=0; shift ;;
        --keep) KEEP=1; shift ;;
        -h | --help) usage; exit 0 ;;
        *) usage >&2; die "unknown option: $1" ;;
    esac
done

if [ -z "$BACKUP" ] || [ -z "$SCRATCH" ]; then usage >&2; die "--backup and --scratch-db are required"; fi
[[ "$SCRATCH" =~ ^restore_drill_[a-z0-9_]{1,40}$ ]] || die "--scratch-db must match restore_drill_[a-z0-9_]{1,40}"
is_uint "$SAMPLE" || die "--sample must be a whole number"
[ -d "$BACKUP" ] || die "backup directory not found: $BACKUP"
BACKUP="$(cd "$BACKUP" && pwd -P)"
case "$BACKUP" in *.partial) die "$BACKUP is an incomplete backup" ;; esac
if [ ! -f "$BACKUP/database.dump" ] || [ ! -f "$BACKUP/SHA256SUMS" ]; then die "$BACKUP has no database.dump or SHA256SUMS"; fi
command -v docker >/dev/null 2>&1 || die "docker not found"

cd "$ROOT"
docker compose ps --status running --services | grep -qx postgres || die "the postgres service is not running (docker compose up -d postgres)"

# psql on the postgres service. $1 = database, $2 = SQL (passed as arguments of `sh -c`).
# shellcheck disable=SC2016  # $POSTGRES_USER expands inside the container
psql_on() { docker compose exec -T postgres sh -c 'exec psql -X -A -t -q -v ON_ERROR_STOP=1 --username="$POSTGRES_USER" --dbname="$1" -c "$2"' sh "$1" "$2"; }
# shellcheck disable=SC2016
PROD_DB="$(docker compose exec -T postgres sh -c 'printf %s "$POSTGRES_DB"')"
[ "$SCRATCH" != "$PROD_DB" ] || die "--scratch-db must not be the production database"
EXISTS="$(psql_on postgres "SELECT 1 FROM pg_database WHERE datname = '$SCRATCH'")"
[ -z "$EXISTS" ] || die "database $SCRATCH already exists; choose a new name (this script never overwrites a database)"

FAILURES=0
CREATED=0
TMP_BASE="${TMPDIR:-/tmp}"
TMP="$(mktemp -d "${TMP_BASE%/}/restore-drill.XXXXXX")"
cleanup() {
    if [ "$KEEP" -eq 1 ]; then
        info "kept database $SCRATCH and files in $TMP"
        info "remove them later: docker compose exec postgres sh -c 'dropdb -U \"\$POSTGRES_USER\" $SCRATCH'; rm -rf $TMP"
        return
    fi
    if [ "$CREATED" -eq 1 ]; then psql_on postgres "DROP DATABASE IF EXISTS \"$SCRATCH\"" >/dev/null || true; fi
    rm -rf -- "$TMP"
}
trap cleanup EXIT

# 1. Checksums.
if (cd "$BACKUP" && sha256_check); then pass "checksums match SHA256SUMS"; else fail "checksum mismatch in $BACKUP"; exit 1; fi

# 2. Restore into the scratch database.
psql_on postgres "CREATE DATABASE \"$SCRATCH\"" >/dev/null
CREATED=1
info "restoring into $SCRATCH …"
# shellcheck disable=SC2016
if docker compose exec -T postgres sh -c 'exec pg_restore --username="$POSTGRES_USER" --dbname="$1" --no-owner --no-privileges --exit-on-error' sh "$SCRATCH" <"$BACKUP/database.dump"; then
    pass "pg_restore into $SCRATCH"
else
    fail "pg_restore reported errors"
    exit 1
fi

# 3. Row counts.
for table in organizations users forms form_versions submissions attachments renditions mail_messages; do
    printf '      %-15s %s\n' "$table" "$(psql_on "$SCRATCH" "SELECT count(*) FROM $table")"
done
ORGS="$(psql_on "$SCRATCH" 'SELECT count(*) FROM organizations')"
if [ "$ORGS" -ge 1 ]; then pass "$ORGS organization(s) restored"; else fail "no organizations in the restored database"; fi

# 4. Storage.
FILES_SQL="SELECT 'private/' || path FROM attachments WHERE disk = 'local' UNION ALL SELECT 'private/' || path FROM renditions WHERE disk = 'local'"
if [ -f "$BACKUP/storage.tar.gz" ]; then
    tar -tzf "$BACKUP/storage.tar.gz" | sed 's|^\./||' | sort -u >"$TMP/archive.txt"
    psql_on "$SCRATCH" "$FILES_SQL" | sort -u >"$TMP/expected.txt"
    missing="$(comm -23 "$TMP/expected.txt" "$TMP/archive.txt" | wc -l | tr -d ' ')"
    total="$(wc -l <"$TMP/expected.txt" | tr -d ' ')"
    if [ "$missing" -eq 0 ]; then pass "all $total attachment and rendition files are in the archive"; else
        fail "$missing of $total attachment/rendition files are missing from the archive (first: $(comm -23 "$TMP/expected.txt" "$TMP/archive.txt" | head -n 1))"
    fi
    if [ "$SAMPLE" -gt 0 ] && [ "$total" -gt 0 ]; then
        psql_on "$SCRATCH" "(SELECT 'private/' || path, sha256 FROM attachments WHERE disk = 'local' ORDER BY created_at DESC LIMIT $SAMPLE)
            UNION ALL (SELECT 'private/' || path, file_sha256 FROM renditions WHERE disk = 'local' ORDER BY created_at DESC LIMIT $SAMPLE)" >"$TMP/sample.txt"
        cut -d'|' -f1 "$TMP/sample.txt" | grep -Fxf "$TMP/archive.txt" | sed 's|^|./|' >"$TMP/extract.txt" || true
        mkdir -p "$TMP/files"
        if [ -s "$TMP/extract.txt" ]; then tar -xzf "$BACKUP/storage.tar.gz" -C "$TMP/files" -T "$TMP/extract.txt"; fi
        bad=0
        checked=0
        while IFS='|' read -r path expected; do
            [ -f "$TMP/files/$path" ] || continue
            checked=$((checked + 1))
            [ "$(sha256_of "$TMP/files/$path")" = "$expected" ] || { bad=$((bad + 1)); echo "      hash mismatch: $path"; }
        done <"$TMP/sample.txt"
        if [ "$bad" -eq 0 ]; then pass "SHA-256 of $checked newest files matches the database"; else fail "$bad of $checked sampled files do not match their SHA-256"; fi
    fi
else
    note "no storage.tar.gz in this backup (made with --no-storage): files not checked"
fi

# 5. App check: one-off container of the app image (no entrypoint, so no migrations and no config cache).
if [ "$APP_CHECK" -eq 1 ]; then
    [ -f "$ROOT/.env" ] || die ".env not found in $ROOT (restore it first; the app check needs its APP_KEY)"
    info "running the app check …"
    if docker compose run --rm --no-deps -T -e DB_DATABASE="$SCRATCH" -e RUN_MIGRATIONS=false --entrypoint php queue /dev/stdin <<'PHP'; then
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$database = DB::connection()->getDatabaseName();
if (! str_starts_with($database, 'restore_drill_')) {
    fwrite(STDERR, "Refusing: connected to {$database}, not a restore_drill_ database.\n");
    exit(2);
}
$failures = 0;

$migrator = $app->make('migrator');
$files = array_keys($migrator->getMigrationFiles([database_path('migrations')]));
$ran = DB::table('migrations')->pluck('migration')->all();
$pending = array_diff($files, $ran);
$unknown = array_diff($ran, $files);
echo $pending === [] ? "PASS  no pending migrations\n" : 'NOTE  '.count($pending)." pending migration(s): `php artisan migrate --force` would run them\n";
if ($unknown !== []) {
    echo 'FAIL  '.count($unknown)." migration(s) in the backup are unknown to this image (backup is newer than the code)\n";
    $failures++;
}

$columns = [
    ['mail_messages', 'payload'], ['mail_settings', 'smtp_password'], ['webhook_endpoints', 'secret'],
    ['identity_providers', 'client_secret'], ['identity_providers', 'config'], ['users', 'two_factor_secret'],
];
$tested = 0;
foreach ($columns as [$table, $column]) {
    if (! Schema::hasColumn($table, $column)) {
        continue;
    }
    $value = DB::table($table)->whereNotNull($column)->value($column);
    if (! is_string($value)) {
        continue;
    }
    $tested++;
    try {
        Crypt::decrypt($value, false);
        echo "PASS  APP_KEY decrypts {$table}.{$column}\n";
    } catch (Illuminate\Contracts\Encryption\DecryptException) {
        echo "FAIL  APP_KEY cannot decrypt {$table}.{$column} (wrong or missing APP_KEY / APP_PREVIOUS_KEYS)\n";
        $failures++;
    }
}
if ($tested === 0) {
    echo "NOTE  no encrypted values in this backup: APP_KEY not tested\n";
}
exit($failures > 0 ? 1 : 0);
PHP
        :
    else
        fail "app check (see the lines above)"
    fi
else
    note "app check skipped (--skip-app-check)"
fi

echo
if [ "$FAILURES" -eq 0 ]; then info "drill passed for $(basename "$BACKUP")"; else info "drill FAILED ($FAILURES check(s))"; exit 1; fi
