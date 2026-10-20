<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\Backup\BackupChecksums;
use App\Domain\Instance\Backup\BackupEntry;
use App\Domain\Instance\Backup\BackupName;
use App\Domain\Instance\Backup\BackupStore;
use App\Domain\Instance\Compose\Services\AppRoles;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Restore\BackupSet;
use App\Domain\Instance\Restore\LegacyEnv;
use App\Domain\Instance\State\InstanceDirectory;
use Carbon\CarbonImmutable;
use SensitiveParameter;
use Throwable;

/**
 * The backup `fsa adopt` takes of the stopped 1.0 install before it copies anything (KTD20 step 2, R46, plan U19, ADR 0087),
 * in the format of 1.0's `scripts/backup.sh`, which the 1.0 full-restore procedure, the restore drill and `fsa restore`
 * read (BackupSet::LEGACY): `backups/safety-adopt-<UTC>/` (R57: a safety backup, never pruned, 0700/0600) with
 *
 * - `database.dump`: `pg_dump --format=custom --no-owner --no-privileges`, run in a one-off of the manifest's Postgres
 *   image in 1.0's Postgres container's network namespace (DatabaseProbe), streamed to the file; `pg_restore --list` must
 *   read it;
 * - `storage.tar.gz`: 1.0's app-storage volume, mounted read-only into a one-off of the app image without a network
 *   (GNU tar, numeric owners, `./` members as 1.0 writes them); tar's exit 1 (a file changed) is a warning, as in 1.0;
 * - `env`: the restore allow-list's values without the APP keys (URL, sender, SMTP server; review S1): the keys stay out of
 *   `backups/`, which is neither encrypted nor pruned here (ADR 0084: app keys reach backups only encrypted);
 * - `manifest.txt` (`key=value`: created_at, postgres, migrations, last_migration, row counts, storage, env, source,
 *   `app_key_fingerprints`, which name the keys the data needs, and `journal`, the adopt's journal ID) and
 *   `SHA256SUMS`, whose digest the adopt records: it makes exactly this set the instance's own for `fsa restore`.
 *
 * The snapshot (counts and chain heads) is read right after the dump: 1.0's writers are stopped, so both see one state.
 * The `.partial` directory of a run killed earlier, and a complete set of the same journal (killed after the set was
 * complete but before the phase was journaled), are removed first (review A7).
 */
final readonly class LegacyBackupWriter
{
    public const string DATABASE = 'database.dump';

    public const string STORAGE = 'storage.tar.gz';

    public const float TIMEOUT = 21600.0;

    /** Never written to the backup (review S1). */
    public const array KEYS = ['APP_KEY', 'APP_PREVIOUS_KEYS'];

    public function __construct(private InstanceDirectory $directory, private DockerGateway $docker, private DatabaseProbe $probe) {}

    /**
     * @param  array{app: string, postgres: string}  $images
     * @param  array<string, string>  $env  the allow-listed `.env` values (the APP keys among them are left out)
     * @param  list<string>  $fingerprints  the APP keys' fingerprints, current first
     * @param  string  $journal  the adopt's journal ID, in the manifest: a resumed run removes the set it made before
     * @return array{name: string, path: string, bytes: int, sums: string, snapshot: array<string, mixed>, warnings: list<string>}
     *
     * @throws InstanceException exit 4 `legacy_backup_failed`; nothing of the partial backup is left
     */
    public function write(LegacyInstall $legacy, array $images, string $uuid, #[SensitiveParameter] array $env, array $fingerprints, string $journal): array
    {
        $store = new BackupStore($this->directory);
        foreach ([...$this->partials($store), ...$this->madeBy($store, $journal)] as $leftover) {
            $this->directory->files()->delete(InstanceDirectory::BACKUPS_DIR.'/'.$leftover);
        }
        [$name, $partial] = $store->begin(BackupName::safety('adopt', CarbonImmutable::now('UTC')));

        try {
            $this->dump($legacy, $images, $uuid, $partial);
            $snapshot = $this->probe->snapshot($images['postgres'], $legacy->postgres(), $legacy->database, $legacy->username, $uuid);
            $warnings = $this->storage($legacy, $images['app'], $uuid, $partial);
            $this->directory->write($this->relative($name->partial(), BackupSet::LEGACY_ENV), $this->env($env), 0600);
            $this->directory->write($this->relative($name->partial(), BackupSet::LEGACY_MANIFEST), $this->manifest($name, $legacy, $snapshot, isset($legacy->volumes[LegacyInstall::STORAGE_VOLUME]), $fingerprints, $journal), 0600);
            $files = array_values(array_filter([self::DATABASE, self::STORAGE, BackupSet::LEGACY_ENV, BackupSet::LEGACY_MANIFEST], static fn (string $file): bool => is_file($partial.'/'.$file)));
            $this->directory->write($this->relative($name->partial(), BackupChecksums::SUMS), BackupChecksums::sums($partial, $files), 0600);
            $path = $store->complete($name);
        } catch (Throwable $e) {
            $store->discard($name);
            throw $e instanceof InstanceException ? $e : new InstanceException('The 1.0 backup failed: '.$e->getMessage(), ExitCode::HealthCheckFailed, 'legacy_backup_failed', 'Read the output; 1.0 was not changed.', true, [], $e);
        }

        // The digest of SHA256SUMS identifies exactly this set for a restore of this instance (review R1).
        return ['name' => $name->name(), 'path' => $path, 'bytes' => BackupStore::bytes($path), 'sums' => (string) hash_file('sha256', $path.'/'.BackupChecksums::SUMS),
            'snapshot' => $snapshot->toArray(), 'warnings' => $warnings];
    }

    /** @param  array{app: string, postgres: string}  $images */
    private function dump(LegacyInstall $legacy, array $images, string $uuid, string $partial): void
    {
        $file = $partial.'/'.self::DATABASE;
        $outcome = $this->docker->runContainerToFile(new ContainerSpec(
            image: $images['postgres'],
            command: ['-h', '127.0.0.1', '-U', $legacy->username, '-d', $legacy->database, '--format=custom', '--no-owner', '--no-privileges'],
            labels: Labels::forInstance($uuid, DatabaseProbe::ROLE),
            network: 'container:'.$legacy->postgres(),
            entrypoint: 'pg_dump',
        )->hardened(), $file, self::TIMEOUT);
        $this->assertFile($outcome, $file, 'pg_dump', 'PGDMP');
        $listed = $this->docker->runContainer(new ContainerSpec(
            image: $images['app'], command: ['--list', '--', '/backup/'.self::DATABASE], labels: Labels::forInstance($uuid, DatabaseProbe::ROLE),
            volumes: [$partial.':/backup:ro'], network: 'none', user: '0:0', entrypoint: 'pg_restore',
        )->hardened(ContainerSpec::READ_ANY), null, 600.0);
        if (! $listed->successful()) {
            throw new InstanceException('The dump of the 1.0 database is not a readable pg_dump archive: '.$listed->stderrTail(3), ExitCode::HealthCheckFailed, 'legacy_backup_failed', 'Read the output; 1.0 was not changed.', true);
        }
    }

    /** @return list<string> warnings */
    private function storage(LegacyInstall $legacy, string $appImage, string $uuid, string $partial): array
    {
        $volume = $legacy->volumes[LegacyInstall::STORAGE_VOLUME] ?? null;
        if ($volume === null) {
            return ['1.0 has no app-storage volume; the backup holds the database only.'];
        }
        $file = $partial.'/'.self::STORAGE;
        $outcome = $this->docker->runContainerToFile(new ContainerSpec(
            image: $appImage, command: ['-C', AppRoles::STORAGE_PATH, '--numeric-owner', '-czf', '-', '.'], labels: Labels::forInstance($uuid, DatabaseProbe::ROLE),
            volumes: [$volume.':'.AppRoles::STORAGE_PATH.':ro'], network: 'none', user: '0:0', entrypoint: 'tar',
        )->hardened(ContainerSpec::READ_ANY), $file, self::TIMEOUT);
        $changed = $outcome->exitCode === 1 && ! $outcome->timedOut;
        $this->assertFile($changed ? new ProcessOutcome(0) : $outcome, $file, 'tar', "\x1f\x8b");

        return $changed ? ['Some files of 1.0\'s storage changed while they were archived.'] : [];
    }

    private function assertFile(ProcessOutcome $outcome, string $file, string $tool, string $magic): void
    {
        $head = is_file($file) ? (string) file_get_contents($file, false, null, 0, 16) : '';
        if (! $outcome->successful() || ! str_starts_with($head, $magic)) {
            $why = $outcome->timedOut ? 'timed out' : 'exit '.$outcome->exitCode;
            throw new InstanceException("{$tool} of the 1.0 install failed ({$why}): ".($outcome->stderrTail(3) ?: 'unexpected output'), ExitCode::HealthCheckFailed, 'legacy_backup_failed', 'Read the output; 1.0 was not changed.', true);
        }
    }

    /** @param  list<string>  $fingerprints */
    private function manifest(BackupName $name, LegacyInstall $legacy, DataSnapshot $snapshot, bool $storage, array $fingerprints, string $journal): string
    {
        $lines = [
            'created_at' => $name->at->format('Ymd\THis\Z'),
            'postgres' => 'postgres (PostgreSQL) '.($snapshot->postgres ?? 'unknown'),
            'migrations' => (string) $snapshot->migrations,
            'last_migration' => (string) $snapshot->lastMigration,
            ...array_map(strval(...), $snapshot->counts),
            'storage' => $storage ? 'included' : 'skipped',
            'env' => 'included',
            'source' => 'fsa adopt',
            'version' => $legacy->version,
            'project' => $legacy->project,
            'app_key_fingerprints' => implode(',', $fingerprints),
            'journal' => $journal,
        ];

        return implode('', array_map(static fn (string $key, string $value): string => $key.'='.preg_replace('/[\x00-\x1F\x7F]/', '', $value)."\n", array_keys($lines), $lines));
    }

    /**
     * The allow-listed values without the APP keys, single-quoted (literal for both LegacyEnv and 1.0's dotenv),
     * double-quoted when a value holds a single quote.
     *
     * @param  array<string, string>  $env
     */
    private function env(#[SensitiveParameter] array $env): string
    {
        $lines = "# Written by fsa adopt: the URL, sender and SMTP server of the 1.0 .env. The APP keys are not here: their\n"
            ."# fingerprints are in manifest.txt; restore elsewhere with --app-key-file (the 1.0 .env's APP_KEY).\n";
        foreach (array_diff_key(array_intersect_key($env, array_flip(LegacyEnv::ALLOWED)), array_flip(self::KEYS)) as $key => $value) {
            $lines .= $key.'='.(str_contains($value, "'") ? '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"' : "'".$value."'")."\n";
        }

        return $lines;
    }

    /** @return list<string> the `safety-adopt-<UTC>.partial` directories a killed run left */
    private function partials(BackupStore $store): array
    {
        return array_values(array_filter($store->partials(), static fn (string $name): bool => str_starts_with($name, BackupName::SAFETY.'-adopt-')));
    }

    /**
     * Complete sets of this journal: a run killed after the set was complete but before the phase was journaled (review A7).
     *
     * @return list<string>
     */
    private function madeBy(BackupStore $store, string $journal): array
    {
        $names = array_map(static fn (BackupEntry $entry): string => $entry->name->name(), $store->entries());

        return array_values(array_filter($names, fn (string $name): bool => str_starts_with($name, BackupName::SAFETY.'-adopt-')
            && (BackupSet::legacyManifest($store->path($name))['journal'] ?? null) === $journal));
    }

    private function relative(string $partial, string $file): string
    {
        return InstanceDirectory::BACKUPS_DIR.'/'.$partial.'/'.$file;
    }
}
