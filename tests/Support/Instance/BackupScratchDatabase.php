<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Committed data for the backup tests (ADR 0084). A backup reads the database from other sessions (an exported snapshot,
 * pg_dump), which never see the rows of RefreshDatabase's open transaction. Each test gets a scratch database
 * `<test database>_bw`, copied from a migrated template `<test database>_bwt` (made once per process), as the default
 * connection `backup_scratch`: factories and the audit logger commit into it. `restored()` gives another one for
 * pg_restore. Every database is dropped again: the scratch ones by `drop()`, the template when the process ends.
 */
final class BackupScratchDatabase
{
    public const string CONNECTION = 'backup_scratch';

    public const string RESTORED = 'backup_restored';

    /** A second session on the scratch database: writes while a backup's snapshot transaction is open. */
    public const string CONCURRENT = 'backup_concurrent';

    private static ?string $template = null;

    public static function base(): string
    {
        return (string) config('database.connections.pgsql.database');
    }

    public static function name(): string
    {
        return self::base().'_bw';
    }

    /** A fresh scratch database as the default connection. */
    public static function create(): void
    {
        self::template();
        self::recreate(self::name(), self::$template);
        self::connect(self::CONNECTION, self::name());
        self::connect(self::CONCURRENT, self::name());
        DB::setDefaultConnection(self::CONNECTION);
    }

    /** An empty database for pg_restore, as the connection `backup_restored`. */
    public static function restored(): string
    {
        $name = self::base().'_bwr';
        self::recreate($name, null);
        self::connect(self::RESTORED, $name);

        return $name;
    }

    /** @return array<string, string> libpq's variables for a pg_restore/pg_dump of the scratch databases */
    public static function libpq(string $database): array
    {
        $config = config('database.connections.pgsql');

        return ['PGHOST' => (string) $config['host'], 'PGPORT' => (string) $config['port'], 'PGDATABASE' => $database, 'PGUSER' => (string) $config['username'], 'PGPASSWORD' => (string) $config['password']];
    }

    public static function drop(): void
    {
        DB::setDefaultConnection('pgsql');
        foreach ([self::CONNECTION, self::CONCURRENT, self::RESTORED] as $connection) {
            DB::purge($connection);
        }
        foreach ([self::name(), self::base().'_bwr'] as $name) {
            self::admin()->exec('DROP DATABASE IF EXISTS "'.$name.'" WITH (FORCE)');
        }
    }

    private static function template(): void
    {
        if (self::$template !== null) {
            return;
        }
        $template = self::base().'_bwt';
        self::recreate($template, null);
        self::connect('backup_template', $template);
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('backup_template');

        try {
            Artisan::call('migrate', ['--database' => 'backup_template', '--force' => true]);
        } finally {
            DB::setDefaultConnection($default);
            DB::purge('backup_template');
        }
        self::$template = $template;
        $credentials = config('database.connections.pgsql');
        register_shutdown_function(static function () use ($template, $credentials): void {
            self::admin($credentials)->exec('DROP DATABASE IF EXISTS "'.$template.'" WITH (FORCE)');
        });
    }

    private static function recreate(string $name, ?string $template): void
    {
        $admin = self::admin();
        $admin->exec('DROP DATABASE IF EXISTS "'.$name.'" WITH (FORCE)');
        $admin->exec('CREATE DATABASE "'.$name.'"'.($template === null ? '' : ' TEMPLATE "'.$template.'"'));
    }

    private static function connect(string $connection, string $database): void
    {
        config(["database.connections.{$connection}" => [...config('database.connections.pgsql'), 'database' => $database]]);
        DB::purge($connection);
    }

    /** @param  array<string, mixed>|null  $credentials */
    private static function admin(?array $credentials = null): PDO
    {
        $config = $credentials ?? config('database.connections.pgsql');

        return new PDO("pgsql:host={$config['host']};port={$config['port']};dbname=postgres", (string) $config['username'], (string) $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
