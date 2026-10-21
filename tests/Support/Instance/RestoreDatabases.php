<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Committed databases for the restore tests (plan U17, ADR 0085; LEARNINGS 133). The restore one-offs open their own
 * sessions (the pre-restore database, the maintenance database), which never see RefreshDatabase's open transaction.
 * `target()` gives a fresh database `<test db>_rt` from a migrated template (made once per process) as the default
 * connection: the "restored" database. `sourceFromTarget()` copies it as it stands into `<test db>_rs`, the
 * pre-restore database, so a test seeds the backup's state first and then changes the source the way the live
 * instance changed after the backup. `drop()` removes every database (and role) a test made.
 */
final class RestoreDatabases
{
    public const string TARGET = 'restore_target';

    public const string SOURCE = 'restore_source';

    private static ?string $template = null;

    /** @var list<string> */
    private static array $roles = [];

    public static function base(): string
    {
        return (string) config('database.connections.pgsql.database');
    }

    public static function name(string $suffix): string
    {
        return self::base().'_'.$suffix;
    }

    /** A fresh, migrated, committed database as the default connection (`<test db>_<suffix>`, default `_rt`). */
    public static function target(string $suffix = 'rt'): string
    {
        self::template();
        self::recreate(self::name($suffix), self::$template);
        self::connect(self::TARGET, self::name($suffix));
        DB::setDefaultConnection(self::TARGET);

        return self::name($suffix);
    }

    /** The pre-restore database: a copy of the target as it stands now, as the connection `restore_source`. */
    public static function sourceFromTarget(): string
    {
        DB::purge(self::TARGET); // CREATE DATABASE … TEMPLATE needs the template without sessions
        self::recreate(self::name('rs'), self::name('rt'));
        self::connect(self::SOURCE, self::name('rs'));

        return self::name('rs');
    }

    /** An empty database (no schema) under a suffix, dropped by drop(). */
    public static function empty(string $suffix): string
    {
        self::recreate(self::name($suffix), null);

        return self::name($suffix);
    }

    /** A role name of this test process; dropped by drop(). */
    public static function role(string $prefix): string
    {
        $role = $prefix.'_'.bin2hex(random_bytes(4));
        self::$roles[] = $role;

        return $role;
    }

    public static function exists(string $database): bool
    {
        $statement = self::admin()->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $statement->execute([$database]);

        return $statement->fetchColumn() !== false;
    }

    public static function roleExists(string $role): bool
    {
        $statement = self::admin()->prepare('SELECT 1 FROM pg_roles WHERE rolname = ?');
        $statement->execute([$role]);

        return $statement->fetchColumn() !== false;
    }

    public static function connect(string $connection, string $database): void
    {
        config(["database.connections.{$connection}" => [...config('database.connections.pgsql'), 'database' => $database]]);
        DB::purge($connection);
    }

    /** @param  list<string>  $suffixes  further databases made by the test (by suffix) */
    public static function drop(array $suffixes = []): void
    {
        DB::setDefaultConnection('pgsql');
        foreach ([self::TARGET, self::SOURCE, 'fieldset_restore_source', 'fieldset_restore_maintenance', 'fieldset_restore_adopt', 'fieldset_restore_create', 'fieldset_restore_audit', 'fieldset_restore_reference'] as $connection) {
            DB::purge($connection);
        }
        $admin = self::admin();
        foreach (array_unique(['rt', 'rs', ...$suffixes]) as $suffix) {
            $admin->exec('DROP DATABASE IF EXISTS "'.self::name($suffix).'" WITH (FORCE)');
        }
        foreach (self::$roles as $role) {
            $admin->exec('DROP ROLE IF EXISTS '.$role);
        }
        self::$roles = [];
    }

    public static function admin(): PDO
    {
        $config = config('database.connections.pgsql');

        return new PDO("pgsql:host={$config['host']};port={$config['port']};dbname=postgres", (string) $config['username'], (string) $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private static function template(): void
    {
        if (self::$template !== null) {
            return;
        }
        $template = self::name('rtt');
        self::recreate($template, null);
        self::connect('restore_template', $template);
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('restore_template');

        try {
            Artisan::call('migrate', ['--database' => 'restore_template', '--force' => true]);
        } finally {
            DB::setDefaultConnection($default);
            DB::purge('restore_template');
        }
        self::$template = $template;
        $config = config('database.connections.pgsql');
        register_shutdown_function(static function () use ($template, $config): void {
            new PDO("pgsql:host={$config['host']};port={$config['port']};dbname=postgres", (string) $config['username'], (string) $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])
                ->exec('DROP DATABASE IF EXISTS "'.$template.'" WITH (FORCE)');
        });
    }

    private static function recreate(string $name, ?string $template): void
    {
        $admin = self::admin();
        $admin->exec('DROP DATABASE IF EXISTS "'.$name.'" WITH (FORCE)');
        $admin->exec('CREATE DATABASE "'.$name.'"'.($template === null ? '' : ' TEMPLATE "'.$template.'"'));
    }
}
