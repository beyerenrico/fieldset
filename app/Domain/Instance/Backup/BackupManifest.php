<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use Carbon\CarbonImmutable;

/**
 * `manifest.json` of a backup (R38, KTD16, KTD18, ADR 0084): what the set holds and what a restore needs to judge it, the
 * instance and its versions (the app, the Postgres server, the pg_dump client), the migration set ("newer" is decided by
 * comparing it, U17), the audit chain epoch, the referenced files missing from storage, the fingerprints of the APP
 * keys the HMAC key derives from, and how the HMAC is keyed. Never a secret: `includes_secrets` only says whether
 * `secrets.env` is in the set.
 */
final readonly class BackupManifest
{
    public const string FILE = 'manifest.json';

    public const string FORMAT = 'fieldset-backup';

    public const string SCHEMA_VERSION = '1.0';

    /** How many missing files the manifest names (the count is always complete). */
    public const int MISSING_SAMPLE = 100;

    /**
     * @param  array<string, mixed>  $instance  uuid, project, version, mode (staging/backup/instance.json)
     * @param  array{migrations: list<string>, server: string, dump: string, epoch: int, chains: int}  $database
     * @param  array{referenced: int, missing: int, sample: list<string>, changed: bool}  $storage
     * @param  array<string, array{bytes: int, sha256: string}>  $files
     * @return array<string, mixed>
     */
    public static function build(BackupName $name, BackupConfig $config, array $instance, array $database, array $storage, array $files, bool $encrypted, bool $secrets): array
    {
        $migrations = $database['migrations'];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'format' => self::FORMAT,
            'kind' => $name->kind,
            'name' => $name->name(),
            'created_at' => $name->at->toIso8601ZuluString(),
            'completed_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
            'instance' => array_intersect_key($instance, array_flip(['uuid', 'project', 'version', 'mode'])),
            'versions' => ['app' => $config->appVersion, 'postgres' => $database['server'], 'pg_dump' => $database['dump']],
            'database' => [
                'migrations' => count($migrations),
                'last_migration' => $migrations === [] ? null : $migrations[array_key_last($migrations)],
                'migration_set_sha256' => hash('sha256', implode("\n", $migrations)),
                'migration_names' => $migrations,
            ],
            'audit' => ['epoch' => $database['epoch'], 'chains' => $database['chains']],
            'storage' => [
                'referenced_files' => $storage['referenced'], 'missing_files' => $storage['missing'],
                'missing_sample' => $storage['sample'], 'changed_while_archived' => $storage['changed'],
            ],
            'app_key_fingerprints' => $config->fingerprints,
            'hmac' => ['algorithm' => 'hmac-sha256', 'file' => BackupChecksums::HMAC, 'key' => 'hkdf-sha256(APP_KEY, "fieldset backup hmac v1")'],
            'encrypted' => $encrypted,
            'recipients' => $encrypted ? $config->recipients : [],
            'includes_secrets' => $secrets,
            'files' => $files,
        ];
    }
}
