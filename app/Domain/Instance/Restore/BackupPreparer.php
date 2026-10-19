<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use App\Domain\Instance\Backup\AgeCli;
use App\Domain\Instance\Backup\BackupChecksums;
use App\Domain\Instance\Backup\BackupManifest;
use App\Domain\Instance\Backup\BackupTools;
use App\Domain\Instance\Backup\BackupWriter;
use RuntimeException;
use SensitiveParameter;

/**
 * The first step of every restore, drill and `--from-backup` (R41, R67, KTD16, KTD18, plan U17, ADR 0085), run by
 * `fsa restore-prepare` in a one-off of the app image without the Docker socket and without a network:
 *
 * 1. an encrypted backup is decrypted (the identity file opened with the passphrase through batchpass, then
 *    `backup.tar.age` with the identity) into `<scratch>/backup.tar`, judged member by member and extracted into
 *    `<scratch>/set` (SafeExtractor); a plain backup directory is read in place once every entry is a regular file;
 * 2. `SHA256SUMS` must match the files; the HMAC is checked with the keys fsa passed from outside the archive (the
 *    instance's APP keys or a key file, as derived HMAC keys), never with a secret inside it;
 * 3. the storage archive is copied into the scratch directory (0640, so GNU tar can read it as the app UID later) and
 *    judged member by member; its regular files are listed in `<scratch>/storage-members.txt`;
 * 4. the dump's table of contents is judged (DumpInspector) and its migration set read from the dump itself.
 *
 * It reports; fsa decides (PreparedBackup). Nothing here connects anywhere or writes outside the scratch directory.
 */
final readonly class BackupPreparer
{
    public const string SET_DIR = 'set';

    public const string MEMBERS = 'storage-members.txt';

    /** The dump's table of contents without the extension, for a restore as the restricted role (review S1). */
    public const string RESTORE_LIST = 'restore.list';

    public function __construct(private AgeCli $age, private BackupTools $tools) {}

    /**
     * @param  list<array{fingerprint: string, key: string}>  $keys  derived HMAC keys (raw bytes) with their APP key fingerprints
     * @param  list<string>  $imageMigrations  the migration names this image ships
     *
     * @throws UnsafeArchive|RuntimeException
     */
    public function prepare(string $source, string $scratch, array $keys, array $imageMigrations, ?string $identityFile = null, #[SensitiveParameter] ?string $passphrase = null): PreparedBackup
    {
        $detected = BackupSet::detect($source);
        $set = $detected['encrypted'] ? $this->decrypt((string) $detected['file'], $scratch, $identityFile, $passphrase) : $source;
        if (! $detected['encrypted']) {
            BackupSet::assertRegularFiles($set);
        }
        $legacy = $detected['format'] === BackupSet::LEGACY;
        $problems = BackupChecksums::verifySums($set);
        [$hmac, $fingerprint] = $legacy || $problems !== [] ? [$legacy ? 'absent' : 'unchecked', null] : $this->hmac($set, $keys);
        $manifest = $legacy ? [] : $this->json($set.'/'.BackupManifest::FILE);
        $storage = $problems === [] ? $this->storage($set, $scratch) : null;
        $dump = $problems === [] ? $this->dump($set, $scratch, $hmac !== 'verified') : ['entries' => 0, 'types' => [], 'rejected' => []];
        $migrations = $problems === [] ? MigrationSet::compare($this->migrations($set), $imageMigrations) : MigrationSet::unknown();

        return new PreparedBackup(
            format: $detected['format'], encrypted: $detected['encrypted'], source: $source, set: $set, name: $this->name($source),
            hmac: $hmac, keyFingerprint: $fingerprint, problems: $problems, strays: BackupChecksums::strays($set),
            manifest: $legacy ? $this->legacyManifest($set) : $this->manifestSummary($manifest),
            settings: $legacy ? [] : $this->json($set.'/'.BackupWriter::SETTINGS), storage: $storage, dump: $dump, migrations: $migrations,
            secrets: is_file($set.'/'.BackupWriter::SECRETS), legacyEnv: $legacy && is_file($set.'/'.BackupSet::LEGACY_ENV),
        );
    }

    private function decrypt(string $file, string $scratch, ?string $identityFile, #[SensitiveParameter] ?string $passphrase): string
    {
        if ($identityFile === null || $passphrase === null) {
            throw new RuntimeException('identity_required');
        }
        $identity = $this->age->decryptWithPassphrase($identityFile, $passphrase);
        $tar = $scratch.'/backup.tar';
        $this->age->decryptFileWithIdentity($identity, $file, $tar);
        $set = $scratch.'/'.self::SET_DIR;
        if (! @mkdir($set, 0750)) {
            throw new RuntimeException('Cannot create the scratch directory for the backup set.');
        }

        try {
            SafeExtractor::extractSet($tar, $set, BackupSet::FILES);
        } finally {
            @unlink($tar);
        }

        return $set;
    }

    /**
     * @param  list<array{fingerprint: string, key: string}>  $keys
     * @return array{0: string, 1: string|null} verified|mismatch|absent and the fingerprint of the key that verified
     */
    private function hmac(string $set, array $keys): array
    {
        if (! is_file($set.'/'.BackupChecksums::HMAC)) {
            return ['absent', null];
        }
        foreach ($keys as $key) {
            if (BackupChecksums::verify($set, $key['key']) === []) {
                return ['verified', $key['fingerprint']];
            }
        }

        return ['mismatch', null];
    }

    /** @return array{files: int, directories: int, bytes: int, archive: string, listing: string}|null */
    private function storage(string $set, string $scratch): ?array
    {
        $archive = $set.'/'.BackupWriter::STORAGE;
        if (! is_file($archive)) {
            return null;
        }
        $readable = $archive;
        if (! str_starts_with($archive, $scratch.'/')) { // a plain backup is read in place; its archive is 0600
            $readable = $scratch.'/'.BackupWriter::STORAGE;
            if (! @copy($archive, $readable)) {
                throw new RuntimeException('Cannot copy the storage archive into the scratch directory.');
            }
        }
        @chmod($readable, 0640);
        $counts = SafeExtractor::checkStorage($readable, $scratch.'/'.self::MEMBERS);

        return [...$counts, 'archive' => $readable, 'listing' => $scratch.'/'.self::MEMBERS];
    }

    /** @return array{entries: int, types: array<string, int>, rejected: list<string>, list?: string} */
    private function dump(string $set, string $scratch, bool $strict): array
    {
        $listed = $this->tools->listDump($set.'/'.BackupWriter::DATABASE);
        if (! $listed->successful()) {
            throw new UnsafeArchive('pg_restore cannot read the dump: '.($listed->errorTail() ?: 'no output'));
        }
        $inspected = DumpInspector::inspect($listed->stdout, $strict);
        $list = $scratch.'/'.self::RESTORE_LIST;
        if (file_put_contents($list, DumpInspector::restoreList($listed->stdout)) === false) { // used only when restricted
            return $inspected;
        }
        @chmod($list, 0640);

        return [...$inspected, 'list' => $list];
    }

    /** @return list<string>|null the dump's migration names, null when it has no migrations table */
    private function migrations(string $set): ?array
    {
        $rows = $this->tools->migrationRows($set.'/'.BackupWriter::DATABASE);

        return $rows->successful() ? MigrationSet::fromCopy($rows->stdout) : null;
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true, 32) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function manifestSummary(array $manifest): array
    {
        $versions = is_array($manifest['versions'] ?? null) ? $manifest['versions'] : [];
        $audit = is_array($manifest['audit'] ?? null) ? $manifest['audit'] : [];

        return [
            'format' => $manifest['format'] ?? null, 'schema_version' => $manifest['schema_version'] ?? null, 'kind' => $manifest['kind'] ?? null,
            'name' => $manifest['name'] ?? null, 'created_at' => $manifest['created_at'] ?? null, 'version' => $versions['app'] ?? null,
            'postgres' => $versions['postgres'] ?? null, 'epoch' => $audit['epoch'] ?? null, 'instance' => $manifest['instance'] ?? null,
            'app_key_fingerprints' => array_values(array_filter((array) ($manifest['app_key_fingerprints'] ?? []), is_string(...))),
            'includes_secrets' => ($manifest['includes_secrets'] ?? false) === true, 'storage' => $manifest['storage'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function legacyManifest(string $set): array
    {
        $values = BackupSet::legacyManifest($set);

        // `fsa adopt` names the APP keys instead of copying them into `env` (plan U19, ADR 0087); 1.0's script names none.
        $fingerprints = array_values(array_filter(explode(',', $values['app_key_fingerprints'] ?? ''), static fn (string $fingerprint): bool => preg_match('/^[0-9a-f]{4}(-[0-9a-f]{4}){3}$/D', $fingerprint) === 1));

        return ['format' => 'scripts/backup.sh', 'kind' => 'legacy', 'created_at' => $values['created_at'] ?? null, 'version' => null, 'postgres' => $values['postgres'] ?? null,
            // Secrets are included only when the `env` holds an APP key (the adopt backup's holds none, review S1).
            'app_key_fingerprints' => $fingerprints, 'includes_secrets' => ($values['env'] ?? '') === 'included' && AppKeys::valid(LegacyEnv::read((string) @file_get_contents($set.'/'.BackupSet::LEGACY_ENV))['APP_KEY'] ?? ''),
            'storage' => $values['storage'] ?? null];
    }

    private function name(string $source): string
    {
        $base = basename(str_ends_with($source, '.age') ? dirname($source) : $source);

        return preg_replace('/[^A-Za-z0-9._-]/', '_', $base) ?? 'backup';
    }
}
