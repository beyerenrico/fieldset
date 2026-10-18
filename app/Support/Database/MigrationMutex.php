<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Console\CacheCommandMutex;
use Illuminate\Console\Command;
use Illuminate\Console\CommandMutex;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * The isolation mutex of `migrate --isolated` (the app entrypoint with RUN_MIGRATIONS, ADR 0079): a session-level
 * Postgres advisory lock on the default connection (the migrator's, unless `--database` names another; the lock
 * still serializes, since every migrator takes it there). Concurrent app starts take turns, and a migrator that
 * dies (container killed mid-migration) releases the lock with its session, so the next start migrates at once. The
 * framework's cache lock would outlive it for an hour, and the database cache store has no lock table on a fresh
 * database. Other isolated commands, and non-Postgres connections, keep the framework's cache mutex.
 */
final readonly class MigrationMutex implements CommandMutex
{
    /** hashtext() of this name is the advisory lock key. */
    public const string LOCK = 'form-schema-app:migrate';

    public function __construct(private CacheCommandMutex $fallback, private DatabaseManager $databases) {}

    /** @param  Command  $command */
    public function create($command): bool
    {
        $connection = $this->connection($command);
        if (! $connection instanceof Connection) {
            return $this->fallback->create($command);
        }

        return $this->tryLock($connection);
    }

    /** @param  Command  $command */
    public function exists($command): bool
    {
        $connection = $this->connection($command);
        if (! $connection instanceof Connection) {
            return $this->fallback->exists($command);
        }
        if (! $this->tryLock($connection)) {
            return true;
        }
        $this->unlock($connection);

        return false;
    }

    /** @param  Command  $command */
    public function forget($command): bool
    {
        $connection = $this->connection($command);

        return $connection instanceof Connection ? $this->unlock($connection) : $this->fallback->forget($command);
    }

    private function connection(Command $command): ?Connection
    {
        if ($command->getName() !== 'migrate') {
            return null;
        }
        $connection = $this->databases->connection();

        return $connection->getDriverName() === 'pgsql' ? $connection : null;
    }

    private function tryLock(Connection $connection): bool
    {
        return (bool) $connection->selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS locked', [self::LOCK])?->locked;
    }

    private function unlock(Connection $connection): bool
    {
        return (bool) $connection->selectOne('SELECT pg_advisory_unlock(hashtext(?)) AS unlocked', [self::LOCK])?->unlocked;
    }
}
