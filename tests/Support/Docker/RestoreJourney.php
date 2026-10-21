<?php

declare(strict_types=1);

namespace Tests\Support\Docker;

use App\Domain\Instance\Backup\BackupChecksums;
use Symfony\Component\Process\Process;
use Tests\Support\Instance\TarBuilder;

/**
 * Helpers for the restore half of J8 (plan U17, ADR 0085): SQL against an instance's Postgres container, copies of a
 * backup turned hostile (a changed file with recomputed sums, a `../` member with a valid HMAC), and a 1.0-format
 * archive written the way `scripts/backup.sh` writes one (pg_dump custom format from the postgres container, the storage
 * volume as tar.gz from the app container, `manifest.txt`, `SHA256SUMS`, an `env` file).
 */
final class RestoreJourney
{
    /** @param  list<string>  $command */
    public static function docker(array $command, ?string $input = null, int $timeout = 300): Process
    {
        $process = new Process(['docker', ...$command]);
        $process->setTimeout($timeout);
        $process->setInput($input);
        $process->run();

        return $process;
    }

    /** The ID of the instance's service container of a role (app, postgres, backup …). */
    public static function container(string $uuid, string $role): string
    {
        return trim(self::docker(['ps', '-q', '--filter', "label=app.fieldset.instance={$uuid}", '--filter', "label=app.fieldset.role={$role}", '--filter', 'label=com.docker.compose.oneoff=False'])->getOutput());
    }

    /** One value (tuples only, unaligned) from the instance's live database. */
    public static function sql(string $uuid, string $sql, string $database = 'form_schema'): string
    {
        return trim(self::docker(['exec', self::container($uuid, 'postgres'), 'psql', '-U', 'form_schema', '-d', $database, '-tAc', $sql])->getOutput());
    }

    /** The storage volume the app container mounts. */
    public static function storageVolume(string $uuid): string
    {
        $mounts = json_decode(self::docker(['inspect', '-f', '{{json .Mounts}}', self::container($uuid, 'app')])->getOutput(), true) ?: [];

        return (string) (array_find($mounts, static fn (array $mount): bool => ($mount['Destination'] ?? '') === '/var/www/html/storage/app')['Name'] ?? '');
    }

    /** A copy of a plain backup with settings.json changed and SHA256SUMS recomputed, as someone without the key would. */
    public static function tampered(string $source, string $target): string
    {
        self::copy($source, $target);
        file_put_contents($target.'/settings.json', '{"schema_version":"1.0","url":"https://attacker.example","settings":{}}'."\n");
        self::sums($target, null);

        return $target;
    }

    /** A copy whose storage archive escapes its root, with sums and an HMAC an attacker who had the key would write. */
    public static function escaping(string $source, string $target, string $hmacKey): string
    {
        self::copy($source, $target);
        new TarBuilder()->directory('./')->file('./../../../fsa-test-j08-escaped', 'pwned')->writeTo($target.'/storage.tar.gz', gzip: true);
        self::sums($target, $hmacKey);

        return $target;
    }

    /**
     * A 1.0 `scripts/backup.sh` archive of a running instance, with an `env` that carries unsafe 1.0 values.
     *
     * @return string the archive directory
     */
    public static function legacy(string $uuid, string $directory, string $appKey): string
    {
        @mkdir($directory, 0700, true);
        $dump = self::docker(['exec', self::container($uuid, 'postgres'), 'pg_dump', '-U', 'form_schema', '-d', 'form_schema', '--format=custom', '--no-owner', '--no-privileges'], timeout: 600);
        file_put_contents($directory.'/database.dump', $dump->getOutput());
        $storage = self::docker(['exec', self::container($uuid, 'app'), 'tar', '-C', '/var/www/html/storage/app', '-czf', '-', '.'], timeout: 600);
        file_put_contents($directory.'/storage.tar.gz', $storage->getOutput());
        file_put_contents($directory.'/env', implode("\n", [
            'APP_NAME=form-schema-app', "APP_KEY={$appKey}", 'APP_DEBUG=true', 'APP_URL=http://localhost:8080', 'SSO_ALLOW_INSECURE_IDP=true',
            'TRUSTED_PROXIES=*', 'MAIL_FROM_ADDRESS=legacy@example.test', 'MAIL_PASSWORD=never-read-by-fsa', '',
        ]));
        file_put_contents($directory.'/manifest.txt', "created_at=20261019T020000Z\npostgres=postgres (PostgreSQL) 16\nstorage=included\nenv=included\n");
        $sums = '';
        foreach (['database.dump', 'manifest.txt', 'storage.tar.gz', 'env'] as $file) {
            chmod($directory.'/'.$file, 0600);
            $sums .= hash_file('sha256', $directory.'/'.$file).'  '.$file."\n";
        }
        file_put_contents($directory.'/SHA256SUMS', $sums);

        return $directory;
    }

    private static function copy(string $source, string $target): void
    {
        new Process(['cp', '-R', $source, $target])->mustRun();
    }

    private static function sums(string $directory, ?string $hmacKey): void
    {
        $files = array_values(array_diff(scandir($directory) ?: [], ['.', '..', BackupChecksums::SUMS, BackupChecksums::HMAC]));
        $sums = BackupChecksums::sums($directory, $files);
        file_put_contents($directory.'/'.BackupChecksums::SUMS, $sums);
        if ($hmacKey !== null) {
            file_put_contents($directory.'/'.BackupChecksums::HMAC, BackupChecksums::hmac($sums, $hmacKey)."\n");
        }
    }
}
