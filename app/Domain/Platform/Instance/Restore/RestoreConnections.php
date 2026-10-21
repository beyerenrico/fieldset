<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Database connections of the restore one-offs (plan U17, ADR 0085). `fsa` starts them as socket-less one-offs of the
 * app service, whose database user is the instance's Postgres superuser; it points DB_DATABASE at the database the
 * command works on and names the others by option. Each extra connection copies the default connection's settings
 * (host, port, user, password) and swaps only the database: the maintenance database `postgres` for creating,
 * renaming and dropping databases, the pre-restore database for the R64 carry.
 */
final class RestoreConnections
{
    /** Database and role names fsa passes: lowercase identifiers that never need quoting rules beyond "…". */
    public const string NAME = '/^[a-z_][a-z0-9_]{0,62}$/D';

    public const string MAINTENANCE_DATABASE = 'postgres';

    /** Databases that are never created, renamed or dropped by a restore. */
    public const array RESERVED = ['postgres', 'template0', 'template1'];

    /** @throws InvalidArgumentException */
    public static function name(mixed $value, string $option): string
    {
        if (! is_string($value) || preg_match(self::NAME, $value) !== 1) {
            throw new InvalidArgumentException("--{$option} must be a lowercase database or role name (letters, digits, underscores).");
        }

        return $value;
    }

    /** The database the default connection uses (DB_DATABASE of the one-off). */
    public static function current(): string
    {
        return (string) DB::connection()->getDatabaseName();
    }

    /** A connection to another database with the default connection's credentials. */
    public static function to(string $database, string $connection): Connection
    {
        $default = config('database.connections.'.DB::getDefaultConnection());
        config(["database.connections.{$connection}" => [...(is_array($default) ? $default : []), 'database' => $database]]);
        DB::purge($connection);
        $db = DB::connection($connection);
        if (! $db instanceof Connection) {
            throw new InvalidArgumentException("No database connection for {$database}.");
        }

        return $db;
    }

    public static function maintenance(): Connection
    {
        return self::to(self::MAINTENANCE_DATABASE, 'fieldset_restore_maintenance');
    }

    /** Disconnects and forgets a connection made with to(). */
    public static function close(string $connection): void
    {
        DB::purge($connection);
    }
}
