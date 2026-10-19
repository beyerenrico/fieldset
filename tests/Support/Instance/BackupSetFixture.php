<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use App\Domain\Instance\Backup\BackupChecksums;
use App\Domain\Instance\Backup\BackupToolResult;
use App\Domain\Instance\Backup\BackupTools;
use App\Domain\Instance\Backup\KeyFingerprint;
use App\Domain\Instance\Restore\MigrationSet;

/**
 * Backup sets on disk for the restore tests (plan U17): an fsa backup (plain or encrypted with tests/Fixtures/bin/
 * fake-age), or a 1.0 `scripts/backup.sh` directory, with a crafted storage archive and an HMAC keyed like ADR 0084
 * (HKDF of the APP key). `tools()` answers pg_restore's table of contents and migration rows without a real dump.
 */
final class BackupSetFixture
{
    public const string APP_KEY = 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    public const string OTHER_KEY = 'base64:ICEiIyQlJicoKSorLC0uLzAxMjM0NTY3ODk6Ozw9Pj8=';

    public const string PASSPHRASE = 'a passphrase for the identity';

    public const string IDENTITY = 'AGE-SECRET-KEY-1FIXTUREIDENTITYFORRESTORETESTS0000000000000000000000';

    /** The HMAC key a backup service derives from an APP key (ServiceEnvironments::backupHmacKey). */
    public static function hmacKey(string $appKey): string
    {
        return hash_hkdf('sha256', (string) base64_decode(substr($appKey, 7), true), 32, 'form-schema-app backup hmac v1');
    }

    public static function recipient(): string
    {
        return 'age1'.substr(hash('sha256', self::IDENTITY), 0, 58);
    }

    /**
     * A plain fsa backup in `$dir` (created).
     *
     * @param  array<string, mixed>  $manifest  merged over the defaults
     * @param  array<string, string>  $extra  more files in the set (listed in SHA256SUMS)
     */
    public static function fsa(string $dir, array $manifest = [], ?TarBuilder $storage = null, string $appKey = self::APP_KEY, array $extra = [], array $settings = []): string
    {
        @mkdir($dir, 0700, true);
        file_put_contents($dir.'/database.dump', "PGDMP\x01fixture dump");
        ($storage ?? new TarBuilder()->directory('./')->file('./private/a.txt', 'a'))->writeTo($dir.'/storage.tar.gz', gzip: true);
        file_put_contents($dir.'/audit-heads.json', '{"epoch":1,"heads":{}}');
        file_put_contents($dir.'/settings.json', json_encode(['schema_version' => '1.0', 'mode' => 'local', 'url' => 'http://localhost:8080', 'recipients' => [], 'settings' => $settings], JSON_UNESCAPED_SLASHES));
        file_put_contents($dir.'/manifest.json', json_encode([
            'schema_version' => '1.0', 'format' => 'fsa-backup', 'kind' => 'manual', 'name' => basename($dir), 'created_at' => '2026-10-19T12:00:00Z',
            'versions' => ['app' => InstanceCommandFixture::VERSION, 'postgres' => '16.15'], 'audit' => ['epoch' => 1, 'chains' => 1],
            'app_key_fingerprints' => [KeyFingerprint::of($appKey)], 'includes_secrets' => isset($extra['secrets.env']), ...$manifest,
        ], JSON_UNESCAPED_SLASHES));
        foreach ($extra as $name => $content) {
            file_put_contents($dir.'/'.$name, $content);
        }
        $files = ['audit-heads.json', 'database.dump', 'manifest.json', 'settings.json', 'storage.tar.gz', ...array_keys($extra)];
        BackupChecksums::write($dir, $files, self::hmacKey($appKey));

        return $dir;
    }

    /** The same set as one fake-age file (`backup.tar.age`) in `$dir`; the plain files go. */
    public static function encrypt(string $dir): string
    {
        $tar = new TarBuilder;
        foreach (array_values(array_diff(scandir($dir) ?: [], ['.', '..'])) as $name) {
            $tar->file($name, (string) file_get_contents($dir.'/'.$name), 0600);
            unlink($dir.'/'.$name);
        }
        file_put_contents($dir.'/backup.tar.age', 'fake-age:to:'.self::recipient()."\n".$tar->bytes());

        return $dir.'/backup.tar.age';
    }

    /** The passphrase-protected identity file fake-age reads (`-j batchpass`). */
    public static function identityFile(string $path): string
    {
        file_put_contents($path, 'fake-age:pass:'.hash('sha256', self::PASSPHRASE)."\n".self::IDENTITY."\n");

        return $path;
    }

    /** A 1.0 `scripts/backup.sh` directory, with `env` when given. */
    public static function legacy(string $dir, ?string $env = null): string
    {
        @mkdir($dir, 0700, true);
        file_put_contents($dir.'/database.dump', "PGDMP\x01legacy dump");
        new TarBuilder()->directory('./')->file('./private/legacy.txt', 'l')->writeTo($dir.'/storage.tar.gz', gzip: true);
        file_put_contents($dir.'/manifest.txt', "created_at=20261001T020000Z\npostgres=postgres (PostgreSQL) 16.4\nmigrations=40\nlast_migration=2026_10_25_100000_add_is_demo_to_organizations\nstorage=included\nenv=".($env === null ? 'skipped' : 'included')."\n");
        $files = ['database.dump', 'manifest.txt', 'storage.tar.gz'];
        if ($env !== null) {
            file_put_contents($dir.'/env', $env);
            $files[] = 'env';
        }
        $sums = '';
        foreach ($files as $file) {
            $sums .= hash_file('sha256', $dir.'/'.$file).'  '.$file."\n";
        }
        file_put_contents($dir.'/SHA256SUMS', $sums);

        return $dir;
    }

    /**
     * BackupTools that answer pg_restore from fixtures: the real dump's table of contents (plus `$extraEntries`) and a
     * migrations table holding `$migrations` (null: none).
     *
     * @param  list<string>|null  $migrations
     */
    public static function tools(?array $migrations = null, string $extraEntries = ''): BackupTools
    {
        $migrations ??= MigrationSet::image(base_path('database/migrations'));

        return new class($migrations, $extraEntries) extends BackupTools
        {
            /** @param  list<string>|null  $migrations */
            public function __construct(private readonly ?array $migrations, private readonly string $extra) {}

            public function listDump(string $file, float $timeout = self::DEADLINE_SECONDS): BackupToolResult
            {
                return new BackupToolResult(0, file_get_contents(base_path('tests/Fixtures/restore/pg-restore-list.txt')).$this->extra);
            }

            public function migrationRows(string $file, float $timeout = 300.0): BackupToolResult
            {
                if ($this->migrations === null) {
                    return new BackupToolResult(1, '', 'pg_restore: error: could not find table "migrations"');
                }
                $rows = implode('', array_map(static fn (int $i, string $name): string => ($i + 1)."\t{$name}\t1\n", array_keys($this->migrations), $this->migrations));

                return new BackupToolResult(0, "--\nSET client_encoding = 'UTF8';\n\nCOPY public.migrations (id, migration, batch) FROM stdin;\n{$rows}\\.\n\n");
            }
        };
    }
}
