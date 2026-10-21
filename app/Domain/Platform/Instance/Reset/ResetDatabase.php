<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Reset;

use App\Domain\Platform\Instance\Restore\RestoreConnections;
use App\Domain\Platform\Instance\Restore\RestoreRefused;
use Illuminate\Database\Connection;

/**
 * Recreates the instance's database for `fieldset reset` (plan U23, ADR 0088) from the maintenance database, as the
 * instance's Postgres superuser in a socket-less one-off (`instance:reset recreate`): the append-only audit triggers
 * forbid truncating, so the database is dropped (its sessions terminated) and created again, empty, under the same
 * name and owner. Only ever the database the one-off's DB_DATABASE names, never postgres or a template. Both
 * statements are repeatable: a reset killed between them creates the database on its next run.
 */
final readonly class ResetDatabase
{
    public function __construct(private Connection $admin) {}

    /**
     * @return array{database: string, dropped: bool, created: true}
     *
     * @throws RestoreRefused `refused_database` for a reserved database
     */
    public function recreate(string $database): array
    {
        if (in_array($database, RestoreConnections::RESERVED, true)) {
            throw new RestoreRefused('refused_database', "{$database} is a system database; a reset recreates only the instance's own.");
        }
        $quoted = '"'.RestoreConnections::name($database, 'database').'"';
        $existed = $this->admin->selectOne('SELECT 1 AS found FROM pg_database WHERE datname = ?', [$database]) !== null;
        $this->admin->statement('DROP DATABASE IF EXISTS '.$quoted.' WITH (FORCE)');
        $this->admin->statement('CREATE DATABASE '.$quoted);

        return ['database' => $database, 'dropped' => $existed, 'created' => true];
    }
}
