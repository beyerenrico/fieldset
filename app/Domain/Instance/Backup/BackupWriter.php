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
 *    `--include-secrets` `secrets.env`, which stays on the tmpfs (the set links to it);
 * 4. `SHA256SUMS` and its HMAC last;
 * 5. with recipients the whole set becomes one age file (`backup.tar.age`) and the plain files go; a safety backup stays
 *    plain until its operation completed (BackupSealer);
 * 6. everything goes to the owner, 0700/0600, and the directory is renamed.
 *
 * The set being written stays root's (0700) and is tracked by PartialSet (M12 gate S1): every hash in `SHA256SUMS` is
 * the hash of what the writer produced, read through its own descriptor, and before the sums are written the set must
 * hold exactly those files, unchanged, in the directory the run created; anything else fails the run (`set_changed`).
 * The plain set of an encrypted backup is written in `staging/backup/`, so no plaintext is ever in `backups/` (gate S3).
 *
 * All programs share one deadline (BackupTools::DEADLINE_SECONDS). Any failure removes the partial directories and
 * throws BackupFailed with a stable reason.
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

    /**
     * Whether a backup of this kind is encrypted: with recipients, every kind but a safety backup, which stays plain until
     * its operation completed (a rollback restores it without the owner's identity) and is sealed then (BackupSealer).
     */
    public function encrypts(BackupName $name): bool
    {
        return $this->config->encrypts() && ! $name->isSafety();
    }

    /**
     * What a first backup needs at most, when there is no earlier one to go by: the database's size (`pg_database_size`)
     * and the storage volume's; 0 when it cannot be told (the run then fails on its own, with a reason).
     */
    public function estimateBytes(): int
    {
        try {
            $database = (int) $this->db->scalar('SELECT pg_database_size(current_database())');
        } catch (Throwable) {
            $database = 0;
        }

        return $database + (is_dir($this->config->storagePath) ? BackupStore::bytes($this->config->storagePath) : 0);
    }

    /** @param  string|null  $secrets  `secrets.env` content (`--include-secrets`, encryption required) */
    public function write(BackupName $name, #[SensitiveParameter] ?string $secrets = null): BackupResult
    {
        if ($secrets !== null && ! $this->encrypts($name)) {
            throw new BackupFailed('encryption_required', 'Secrets go only into an encrypted backup.');
        }
        [$name, $partial] = $this->store->begin($name);
        $deadline = microtime(true) + BackupTools::DEADLINE_SECONDS;
        $secretFile = null;

        try {
            $target = new PartialSet($partial);
            $set = $this->encrypts($name) ? new PartialSet($this->store->stage($name)) : $target;
            $secretFile = $secrets === null ? null : $this->secretsOnTmpfs($secrets);

            return $this->writeSet($name, $set, $target, $secretFile, $secrets, $deadline);
        } catch (Throwable $e) {
            try {
                $this->store->discard($name);
            } catch (Throwable) {
                // the next run removes a leftover `.partial` under the lock; the step that failed is the news
            }
            throw $e instanceof BackupFailed ? $e : new BackupFailed('write_failed', $e->getMessage(), $e);
        } finally {
            if ($secretFile !== null) {
                @unlink($secretFile);
                @rmdir(dirname($secretFile));
            }
        }
    }

    /** @param  PartialSet  $target  `backups/<name>.partial`: the set itself, or the encrypted set's age file */
    private function writeSet(BackupName $name, PartialSet $set, PartialSet $target, ?string $secretFile, #[SensitiveParameter] ?string $secrets, float $deadline): BackupResult
    {
        $database = $this->dumpDatabase($set, $deadline);
        $storage = $this->archiveStorage($set, $database['references'], $deadline);
        $this->verifyDump($set, $deadline);
        $meta = $this->store->meta();
        $set->write(self::SETTINGS, $this->encode([...$meta, 'schema_version' => '1.0']));
        if ($secretFile !== null) {
            $set->link(self::SECRETS, $secretFile, (string) $secrets);
        }
        $instance = is_array($meta['instance'] ?? null) ? $meta['instance'] : [];
        $set->write(BackupManifest::FILE, $this->encode(BackupManifest::build($name, $this->config, $instance, $database, $storage, $set->described(), $this->encrypts($name), $secretFile !== null)));
        $set->assertIntact();
        BackupChecksums::seal($set, $this->config->hmacKey);
        $set->assertIntact(deep: false);
        if ($set !== $target) {
            $this->encrypt($set, $target);
        }
        $path = $this->store->complete($name);
        $warnings = $storage['changed'] ? [['code' => 'storage_changed', 'message' => 'Some files changed while the storage volume was archived; the archive holds the state tar read.']] : [];
        if ($storage['missing'] > 0) {
            $warnings[] = ['code' => 'files_missing', 'message' => "{$storage['missing']} file(s) the database references were not in storage; manifest.json lists them."];
        }

        return new BackupResult($name, $path, BackupStore::bytes($path), $this->encrypts($name), $secretFile !== null, $storage['referenced'], $storage['missing'], $storage['sample'], $warnings, $this->config->fingerprints);
    }

    /**
     * `secrets.env` on the container's tmpfs, never in `backups/`: the set only links to it, age reads it through the link,
     * and a run killed at any point leaves no plaintext secret on the disk (`/tmp` is the backup service's tmpfs).
     */
    private function secretsOnTmpfs(#[SensitiveParameter] string $secrets): string
    {
        $directory = sys_get_temp_dir().'/fieldset-secrets-'.bin2hex(random_bytes(8));
        if (! @mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot create a private temporary directory for secrets.env.');
        }
        try {
            ProducedFile::written($directory.'/'.self::SECRETS, $secrets);
        } catch (Throwable $e) {
            @unlink($directory.'/'.self::SECRETS);
            @rmdir($directory);
            throw $e;
        }

        return $directory.'/'.self::SECRETS;
    }

    /**
     * The dump and everything read in its snapshot. References go into a set keyed by path (each path once).
     *
     * @return array{migrations: list<string>, server: string, dump: string, epoch: int, chains: int, references: array<string, true>}
     */
    private function dumpDatabase(PartialSet $set, float $deadline): array
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
            $set->write(self::HEADS, $this->encode(['epoch' => $heads['epoch'], 'heads' => (object) $heads['heads']]));
            $dumped = $this->tools->dump($id, $set->path.'/'.self::DATABASE, $this->config->libpqEnvironment(), BackupTools::remaining($deadline));
        } finally {
            $snapshot->close();
        }
        if (! $dumped->successful() || ! $dumped->produced instanceof ProducedFile || ! str_starts_with((string) @file_get_contents($set->path.'/'.self::DATABASE, false, null, 0, 5), 'PGDMP')) {
            throw new BackupFailed('pg_dump_failed', 'pg_dump failed (exit '.$dumped->exitCode.'): '.($dumped->errorTail() ?: 'no output'));
        }
        $set->record(self::DATABASE, $dumped->produced);

        return ['migrations' => $migrations, 'server' => $server, 'dump' => $this->tools->dumpVersion(), 'epoch' => $heads['epoch'], 'chains' => count($heads['heads']), 'references' => $references];
    }

    /**
     * @param  array<string, true>  $references
     * @return array{referenced: int, missing: int, sample: list<string>, changed: bool}
     */
    private function archiveStorage(PartialSet $set, array $references, float $deadline): array
    {
        $file = $set->path.'/'.self::STORAGE;
        $archived = $this->tools->archive($this->config->storagePath, $file, BackupTools::remaining($deadline));
        if (! in_array($archived->exitCode, [0, 1], true) || ! $archived->produced instanceof ProducedFile) {
            throw new BackupFailed('storage_failed', 'Archiving the storage volume failed (exit '.$archived->exitCode.'): '.($archived->errorTail() ?: 'no output'));
        }
        $set->record(self::STORAGE, $archived->produced);
        $set->assertIntact(deep: false); // the listing below reads the archive by its path
        $referenced = count($references);
        $listed = $this->tools->members($file, static function (string $member) use (&$references): void {
            unset($references[$member]);
        }, BackupTools::remaining($deadline));
        if (! $listed->successful()) {
            throw new BackupFailed('storage_failed', 'The storage archive cannot be read back: '.($listed->errorTail() ?: 'no output'));
        }
        $missing = array_keys($references);
        sort($missing);

        return ['referenced' => $referenced, 'missing' => count($missing), 'sample' => array_slice($missing, 0, BackupManifest::MISSING_SAMPLE), 'changed' => $archived->exitCode === 1];
    }

    private function verifyDump(PartialSet $set, float $deadline): void
    {
        $set->assertIntact(deep: false);
        $listed = $this->tools->listDump($set->path.'/'.self::DATABASE, BackupTools::remaining($deadline));
        if (! $listed->successful() || ! str_contains($listed->stdout, 'TABLE DATA')) {
            throw new BackupFailed('verify_failed', 'pg_restore --list cannot read the dump: '.($listed->errorTail() ?: 'no table data listed'));
        }
    }

    /** The staged plain set as one age file in `backups/<name>.partial`; the plain set goes once that file is synced. */
    private function encrypt(PartialSet $set, PartialSet $target): void
    {
        $files = array_keys($set->hashes());
        sort($files);
        $output = $target->path.'/'.BackupStore::ENCRYPTED_FILE;

        try {
            $this->age->encryptDirectory($set->path, $files, $this->config->recipients, $output);
            $target->record(BackupStore::ENCRYPTED_FILE, ProducedFile::read($output));
        } catch (RuntimeException $e) {
            throw new BackupFailed('encrypt_failed', $e->getMessage(), $e);
        }
        $target->assertIntact(deep: false);
        foreach ($files as $file) {
            @unlink($set->path.'/'.$file); // the secrets.env link goes too; its target lives on the tmpfs
        }
    }

    /** @param  array<string, mixed>  $data */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }
}
