<?php

declare(strict_types=1);

use App\Support\Database\ConcurrentIndex;
use App\Support\Database\MigrationMutex;
use Illuminate\Console\CommandMutex;
use Illuminate\Database\Connection;
use Illuminate\Database\Console\Migrations\MigrateCommand;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/*
 * M10 gate (ADR 0079): migrations that run on app start (the stack file's RUN_MIGRATIONS) are safe against an
 * interrupted index build and concurrent starts. CREATE INDEX CONCURRENTLY cannot run in the test's transaction, so
 * the DDL goes through a second session on the same database.
 */

/** A second session on the test database, outside RefreshDatabase's transaction. */
function migrationSession(string $name): Connection
{
    config(["database.connections.{$name}" => config('database.connections.pgsql')]);

    return DB::connection($name);
}

function indexValid(Connection $connection, string $index): ?bool
{
    $row = $connection->selectOne('SELECT i.indisvalid AS valid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?', [$index]);

    return $row === null ? null : (bool) $row->valid;
}

afterEach(function (): void {
    foreach (['fieldset_ddl', 'fieldset_lock_holder'] as $name) {
        if (array_key_exists($name, DB::getConnections())) {
            if ($name === 'fieldset_ddl') {
                DB::connection($name)->statement('DROP TABLE IF EXISTS fieldset_test_concurrent_index');
            }
            DB::purge($name);
        }
    }
});

it('rebuilds an index that an interrupted CREATE INDEX CONCURRENTLY left INVALID, which IF NOT EXISTS would skip', function (): void {
    $ddl = migrationSession('fieldset_ddl');
    $ddl->statement('CREATE TABLE fieldset_test_concurrent_index (id int, value text)');
    $ddl->statement("INSERT INTO fieldset_test_concurrent_index VALUES (1, 'a'), (2, 'a')");
    // A failed concurrent build leaves its index behind, marked invalid: the same state as a container killed mid-build.
    expect(fn () => $ddl->statement('CREATE UNIQUE INDEX CONCURRENTLY fieldset_test_concurrent_index_value_idx ON fieldset_test_concurrent_index (value)'))
        ->toThrow(UniqueConstraintViolationException::class);
    expect(indexValid($ddl, 'fieldset_test_concurrent_index_value_idx'))->toBeFalse();

    ConcurrentIndex::create('fieldset_test_concurrent_index_value_idx', 'ON fieldset_test_concurrent_index (value)', $ddl);
    ConcurrentIndex::create('fieldset_test_concurrent_index_value_idx', 'ON fieldset_test_concurrent_index (value)', $ddl); // a rerun is a no-op

    expect(indexValid($ddl, 'fieldset_test_concurrent_index_value_idx'))->toBeTrue();
});

it('refuses an index name that is not a plain identifier', function (): void {
    expect(fn () => ConcurrentIndex::create('x; DROP TABLE users', 'ON users (id)'))->toThrow(InvalidArgumentException::class);
});

it('serializes `migrate --isolated` with a Postgres advisory lock that dies with its session', function (): void {
    $mutex = app(CommandMutex::class);
    $migrate = app(MigrateCommand::class);
    $holder = migrationSession('fieldset_lock_holder');
    $holder->select('SELECT pg_advisory_lock(hashtext(?))', [MigrationMutex::LOCK]);

    $whileHeld = $mutex->create($migrate);
    $existsWhileHeld = $mutex->exists($migrate);
    DB::purge('fieldset_lock_holder'); // the holder's session ends (a killed container): Postgres releases the lock
    $afterHolderDied = $mutex->create($migrate);
    $mutex->forget($migrate);
    $again = migrationSession('fieldset_lock_holder')->selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS locked', [MigrationMutex::LOCK]);

    expect($mutex)->toBeInstanceOf(MigrationMutex::class)
        ->and($whileHeld)->toBeFalse()
        ->and($existsWhileHeld)->toBeTrue()
        ->and($afterHolderDied)->toBeTrue()
        ->and($again->locked)->toBeTrue();
});
