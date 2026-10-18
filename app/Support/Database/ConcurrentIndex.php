<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * `CREATE INDEX CONCURRENTLY IF NOT EXISTS` for migrations that must not block writes (review L3), made safe to
 * rerun (ADR 0079). A concurrent build that is interrupted (a container killed during `migrate` on start) leaves the
 * index behind marked INVALID; IF NOT EXISTS would then skip it forever. An invalid index of that name is dropped
 * first, so a rerun builds it again. Runs outside a transaction (the migration sets `$withinTransaction = false`).
 * Two migrators never build at once: `migrate --isolated` takes the MigrationMutex advisory lock on app start.
 */
final class ConcurrentIndex
{
    private const string IDENTIFIER = '/^[a-z_][a-z0-9_]{0,62}$/D';

    /** @param  string  $definition  everything after the index name, e.g. `ON submissions (organization_id, updated_at)` */
    public static function create(string $name, string $definition, ?Connection $connection = null): void
    {
        if (preg_match(self::IDENTIFIER, $name) !== 1) {
            throw new InvalidArgumentException("Index name [{$name}] is not a plain identifier.");
        }
        $connection ??= DB::connection();
        $invalid = $connection->selectOne(
            'SELECT 1 AS invalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid '
            .'JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relname = ? AND n.nspname = current_schema() AND NOT i.indisvalid',
            [$name],
        );
        if ($invalid !== null) {
            $connection->statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
        $connection->statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} {$definition}");
    }
}
