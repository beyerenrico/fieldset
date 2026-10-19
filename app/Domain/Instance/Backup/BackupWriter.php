<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use Closure;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Writes one backup inside the backup service (R38, KTD16, ADR 0084), as `backups/<name>.partial`, renamed when complete:
 *
 * 1. one exported snapshot: `pg_dump --snapshot` into `database.dump`, and in the same transaction the audit chain heads
 *    (`audit-heads.json`), the file references and the migration set;
 * 2. then the storage volume into `storage.tar.gz` (a referenced file missing from it is recorded, not a failure);
 * 3. `pg_restore --list` reads the dump back; `settings.json` (portable settings), `manifest.json`, with
 *    `--include-secrets` `secrets.env`;
 * 4. `SHA256SUMS` and its HMAC last, under temporary names, renamed at the end;
 * 5. with recipients the whole set becomes one age file (`backup.tar.age`) and the plain files go;
 * 6. everything belongs to the owner, 0700/0600, and the directory is renamed.
 *
 * Any failure removes the partial directory and throws BackupFailed with a stable reason.
 */
final readonly class BackupWriter
{
    public const string DATABASE = 'database.dump';

    public const string STORAGE = 'storage.tar.gz';

    public const string SETTINGS = 'settings.json';

    public const string HEADS = 'audit-heads.json';

    public const string SECRETS = 'secrets.env';

    /** @param  (Closure(string): void)|null  $afterSnapshot  runs once the snapshot is exported (tests: concurrent writes) */
    public function __construct(
        private BackupStore $store,
        private ConnectionInterface $db,
        private BackupTools $tools,
        private AgeCli $age,
        private BackupConfig $config,
        private ?Closure $afterSnapshot = null,
    ) {}

    /** @param  string|null  $secrets  `secrets.env` content (`--include-secrets`, encryption required) */
    public function write(BackupName $name, #[SensitiveParameter] ?string $secrets = null): BackupResult
    {
        if ($secrets !== null && ! $this->config->encrypts()) {
            throw new BackupFailed('encryption_required', 'Secrets go only into an encrypted backup.');
        }
        [$name, $partial] = $this->store->begin($name);

        try {
            return $this->writeSet($name, $partial, $secrets);
        } catch (Throwable $e) {
            $this->store->discard($name);
            throw $e instanceof BackupFailed ? $e : new BackupFailed('write_failed', $e->getMessage(), $e);
        }
    }

    private function writeSet(BackupName $name, string $partial, ?string $secrets): BackupResult
    {
        $database = $this->dumpDatabase($partial);
        $storage = $this->archiveStorage($partial, $database['references']);
        $this->verifyDump($partial);
        $meta = $this->store->meta();
        $this->json($partial.'/'.self::SETTINGS, [...$meta, 'schema_version' => '1.0']);
        if ($secrets !== null) {
            $this->file($partial.'/'.self::SECRETS, $secrets);
        }
        $files = $this->files($partial);
        $instance = is_array($meta['instance'] ?? null) ? $meta['instance'] : [];
        $manifest = BackupManifest::build($name, $this->config, $instance, $database, $storage, $this->described($partial, $files), $this->config->encrypts(), $secrets !== null);
        $this->json($partial.'/'.BackupManifest::FILE, $manifest);
        BackupChecksums::write($partial, [...$files, BackupManifest::FILE], $this->config->hmacKey);
        if ($this->config->encrypts()) {
            $this->encrypt($partial);
        }
        $path = $this->store->complete($name);
        $warnings = $storage['changed'] ? [['code' => 'storage_changed', 'message' => 'Some files changed while the storage volume was archived; the archive holds the state tar read.']] : [];
        if ($storage['missing'] > 0) {
            $warnings[] = ['code' => 'files_missing', 'message' => "{$storage['missing']} file(s) the database references were not in storage; manifest.json lists them."];
        }

        return new BackupResult($name, $path, BackupStore::bytes($path), $this->config->encrypts(), $secrets !== null, $storage['referenced'], $storage['missing'], $storage['sample'], $warnings, $this->config->fingerprints);
    }

    /**
     * The dump and everything read in its snapshot. References go into a set keyed by path (each path once).
     *
     * @return array{migrations: list<string>, server: string, dump: string, epoch: int, chains: int, references: array<string, true>}
     */
    private function dumpDatabase(string $partial): array
    {
        $snapshot = new DatabaseSnapshot($this->db);

        try {
            $id = $snapshot->open();
            $heads = $snapshot->heads();
            $references = [];
            $snapshot->fileReferences(static function (string $file) use (&$references): void {
                $references[$file] = true;
            });
            $migrations = $snapshot->migrations();
            $server = $snapshot->serverVersion();
        } catch (Throwable $e) {
            $snapshot->close();
            throw new BackupFailed('snapshot_failed', 'Reading the database snapshot failed: '.$e->getMessage(), $e);
        }

        try {
            $this->afterSnapshot?->__invoke($id);
            $this->json($partial.'/'.self::HEADS, ['epoch' => $heads['epoch'], 'heads' => (object) $heads['heads']]);
            $dumped = $this->tools->dump($id, $partial.'/'.self::DATABASE, $this->config->libpqEnvironment());
        } finally {
            $snapshot->close();
        }
        if (! $dumped->successful() || ! str_starts_with((string) @file_get_contents($partial.'/'.self::DATABASE, false, null, 0, 5), 'PGDMP')) {
            throw new BackupFailed('pg_dump_failed', 'pg_dump failed (exit '.$dumped->exitCode.'): '.($dumped->errorTail() ?: 'no output'));
        }

        return ['migrations' => $migrations, 'server' => $server, 'dump' => $this->tools->dumpVersion(), 'epoch' => $heads['epoch'], 'chains' => count($heads['heads']), 'references' => $references];
    }

    /**
     * @param  array<string, true>  $references
     * @return array{referenced: int, missing: int, sample: list<string>, changed: bool}
     */
    private function archiveStorage(string $partial, array $references): array
    {
        $file = $partial.'/'.self::STORAGE;
        $archived = $this->tools->archive($this->config->storagePath, $file);
        if (! in_array($archived->exitCode, [0, 1], true) || ! is_file($file)) {
            throw new BackupFailed('storage_failed', 'Archiving the storage volume failed (exit '.$archived->exitCode.'): '.($archived->errorTail() ?: 'no output'));
        }
        $referenced = count($references);
        $listed = $this->tools->members($file, static function (string $member) use (&$references): void {
            unset($references[$member]);
        });
        if (! $listed->successful()) {
            throw new BackupFailed('storage_failed', 'The storage archive cannot be read back: '.($listed->errorTail() ?: 'no output'));
        }
        $missing = array_keys($references);
        sort($missing);

        return ['referenced' => $referenced, 'missing' => count($missing), 'sample' => array_slice($missing, 0, BackupManifest::MISSING_SAMPLE), 'changed' => $archived->exitCode === 1];
    }

    private function verifyDump(string $partial): void
    {
        $listed = $this->tools->listDump($partial.'/'.self::DATABASE);
        if (! $listed->successful() || ! str_contains($listed->stdout, 'TABLE DATA')) {
            throw new BackupFailed('verify_failed', 'pg_restore --list cannot read the dump: '.($listed->errorTail() ?: 'no table data listed'));
        }
    }

    private function encrypt(string $partial): void
    {
        $files = $this->files($partial);
        $output = $partial.'/'.BackupStore::ENCRYPTED_FILE;

        try {
            $this->age->encryptDirectory($partial, $files, $this->config->recipients, $output);
        } catch (RuntimeException $e) {
            throw new BackupFailed('encrypt_failed', $e->getMessage(), $e);
        }
        foreach ($files as $file) {
            @unlink($partial.'/'.$file);
        }
    }

    /** @return list<string> the regular files of the partial set, sorted */
    private function files(string $partial): array
    {
        $files = array_values(array_filter(scandir($partial) ?: [], static fn (string $entry): bool => ! str_starts_with($entry, '.') && is_file($partial.'/'.$entry)));
        sort($files);

        return $files;
    }

    /**
     * @param  list<string>  $files
     * @return array<string, array{bytes: int, sha256: string}>
     */
    private function described(string $partial, array $files): array
    {
        $described = [];
        foreach ($files as $file) {
            $described[$file] = ['bytes' => (int) filesize($partial.'/'.$file), 'sha256' => (string) hash_file('sha256', $partial.'/'.$file)];
        }

        return $described;
    }

    /** @param  array<string, mixed>  $data */
    private function json(string $path, array $data): void
    {
        $this->file($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }

    private function file(string $path, #[SensitiveParameter] string $content): void
    {
        $handle = @fopen($path, 'x');
        if ($handle === false || ! chmod($path, 0600) || fwrite($handle, $content) !== strlen($content) || ! fflush($handle) || ! fsync($handle)) {
            throw new RuntimeException('Cannot write '.basename($path).'.');
        }
        fclose($handle);
    }
}
