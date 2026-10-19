<?php

declare(strict_types=1);

namespace App\Domain\Instance\Lifecycle;

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Compose\Services\AppRoles;
use App\Domain\Instance\Compose\Services\PostgresService;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\DockerUnavailable;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Restore\AppKeys;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use Carbon\CarbonImmutable;
use Closure;
use Throwable;

/**
 * The last backup `fsa uninstall --keep-backups` writes before it removes anything (§13.3 "back up before
 * destroying", ADR 0073): `backups/final-<UTC timestamp>/` (0700) with
 *
 * - `database.dump`: `pg_dump --format=custom` from a socket-less one-off of the app service (KTD25; the image
 *   carries the PostgreSQL 16 client), streamed to the file, never through memory;
 * - `app-storage.tar`: the app-storage volume, archived by a one-off that mounts it;
 * - `app-key.env`: APP_KEY (and APP_PREVIOUS_KEYS), without which the dump's encrypted columns cannot be read;
 * - `backup.json`: what was saved, with sizes and SHA-256 sums.
 *
 * Every file is 0600. Any failure removes the partial backup and is exit 4 (`final_backup_failed`): the caller
 * then removes nothing.
 */
final readonly class FinalBackup
{
    public const string PREFIX = 'final-';

    public const float DUMP_TIMEOUT = 3600.0;

    private const float START_TIMEOUT = 120.0;

    private const string DATABASE = 'database.dump';

    private const string STORAGE = 'app-storage.tar';

    private const string APP_KEY = 'app-key.env';

    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
    ) {}

    /**
     * @param  list<DockerResource>  $volumes  the instance's labelled volumes
     * @param  bool  $withKey  false for an adopted instance (plan U19, ADR 0087 review S1): its APP_KEY is the 1.0 checkout's,
     *                         which keeps it in its own `.env`; backup.json names it by fingerprint instead
     * @return array{dir: string, files: list<string>}|null null when there is nothing to save (no key, no volume)
     *
     * @throws InstanceException exit 4; no part of the backup is left behind
     */
    public function write(?InstanceState $state, ?string $uuid, array $volumes, bool $withKey = true): ?array
    {
        $env = new EnvFiles($this->directory);
        $hasKey = ($env->read('app')['APP_KEY'] ?? '') !== '';
        $database = $this->find($volumes, PostgresService::VOLUME);
        $storage = $this->find($volumes, AppRoles::STORAGE_VOLUME);
        if (! $hasKey && ! $database && ! $storage) {
            return null;
        }
        $relative = InstanceDirectory::BACKUPS_DIR.'/'.self::PREFIX.CarbonImmutable::now('UTC')->format('Ymd\THis\Z');

        try {
            $this->directory->ensureDirectory(InstanceDirectory::BACKUPS_DIR);
            if (! $this->directory->files()->createExclusive($relative)) {
                throw new InstanceException("{$relative} exists already.");
            }
            $files = $this->save($relative, $env, $state, $database, $storage, $withKey);
            $this->manifest($relative, $files, $uuid, $state, $withKey ? null : $env);
        } catch (Throwable $e) {
            $this->directory->files()->delete($relative);
            if ($e instanceof DockerUnavailable) {
                throw $e;
            }

            $detail = $e instanceof DockerCommandFailed ? trim($e->getMessage().' '.$e->outcome->stderrTail(3)) : $e->getMessage();

            throw new InstanceException(
                'The final backup failed, so nothing was removed: '.$detail,
                ExitCode::HealthCheckFailed,
                'final_backup_failed',
                'Fix the cause (`fsa status`, `fsa logs postgres`), then run `fsa uninstall --keep-backups` again; or run `fsa uninstall` without --keep-backups to delete everything without a backup.',
                true,
                ['backup_dir' => $relative],
                $e,
            );
        }

        return ['dir' => $relative, 'files' => [...$files, 'backup.json']];
    }

    /** @return list<string> */
    private function save(string $relative, EnvFiles $env, ?InstanceState $state, bool $database, bool $storage, bool $withKey): array
    {
        $files = $withKey && $this->saveAppKey($relative, $env) ? [self::APP_KEY] : [];
        if ($database || $storage) {
            $project = $state instanceof InstanceState
                ? ComposeProject::forInstance($state, $this->directory)
                : throw new InstanceException('The instance state is unreadable, so its services cannot be run.');
            if ($database) {
                $this->dumpDatabase($project, $relative, $env);
                $files[] = self::DATABASE;
            }
            if ($storage) {
                $this->archiveStorage($project, $relative);
                $files[] = self::STORAGE;
            }
        }

        return $files;
    }

    private function saveAppKey(string $relative, EnvFiles $env): bool
    {
        $app = $env->read('app');
        $keys = array_filter(['APP_KEY' => $app['APP_KEY'] ?? '', 'APP_PREVIOUS_KEYS' => $app['APP_PREVIOUS_KEYS'] ?? ''], static fn (string $value): bool => $value !== '');
        if (! isset($keys['APP_KEY'])) {
            return false;
        }
        $header = '# The key that decrypts the encrypted columns of '.self::DATABASE.". Keep it as safe as the dump.\n";
        $this->directory->write($relative.'/'.self::APP_KEY, (string) preg_replace('/\A#[^\n]*\n/', $header, EnvFiles::encode($keys)), 0600);

        return true;
    }

    private function dumpDatabase(ComposeProject $project, string $relative, EnvFiles $env): void
    {
        $app = $env->read('app');
        if (($app['DB_PASSWORD'] ?? '') === '') {
            throw new InstanceException('env/app.env holds no database password.');
        }
        $this->startPostgres($project);
        $file = $this->directory->path($relative.'/'.self::DATABASE);
        $outcome = $this->docker->composeRunToFile($project, ComposeRenderer::ONE_OFF_SERVICE, ['--format=custom', '--no-password'], $file, [
            'PGHOST' => $app['DB_HOST'] ?? 'postgres', 'PGPORT' => $app['DB_PORT'] ?? '5432', 'PGDATABASE' => $app['DB_DATABASE'] ?? '',
            'PGUSER' => $app['DB_USERNAME'] ?? '', 'PGPASSWORD' => $app['DB_PASSWORD'],
        ], 'pg_dump', self::DUMP_TIMEOUT);
        $this->assertWritten($outcome, $file, 'pg_dump', static fn (string $head): bool => str_starts_with($head, 'PGDMP'));
    }

    private function archiveStorage(ComposeProject $project, string $relative): void
    {
        $file = $this->directory->path($relative.'/'.self::STORAGE);
        $outcome = $this->docker->composeRunToFile($project, ComposeRenderer::ONE_OFF_SERVICE, ['-C', AppRoles::STORAGE_PATH, '-cf', '-', '.'], $file, [], 'tar', self::DUMP_TIMEOUT);
        $this->assertWritten($outcome, $file, 'tar', static fn (string $head): bool => substr($head, 257, 5) === 'ustar');
    }

    /** pg_dump needs the database server; a stopped instance gets only Postgres started. */
    private function startPostgres(ComposeProject $project): void
    {
        $running = array_any($this->docker->composePs($project), static fn (ContainerInfo $c): bool => $c->service() === PostgresService::NAME && $c->running());
        if (! $running) {
            $this->docker->composeUp($project, [PostgresService::NAME], (int) self::START_TIMEOUT);
        }
    }

    /** @param  Closure(string): bool  $looksRight  checks the first 512 bytes (format magic) */
    private function assertWritten(ProcessOutcome $outcome, string $file, string $tool, Closure $looksRight): void
    {
        $head = is_file($file) ? (string) file_get_contents($file, false, null, 0, 512) : '';
        if (! $outcome->successful() || ! $looksRight($head)) {
            $why = $outcome->timedOut ? 'timed out' : 'exit '.$outcome->exitCode;
            throw new InstanceException("{$tool} failed ({$why}): ".($outcome->stderrTail(3) ?: 'unexpected output'));
        }
    }

    /**
     * @param  list<string>  $files
     * @param  EnvFiles|null  $keptOut  the env files whose APP keys were left out: named by fingerprint
     */
    private function manifest(string $relative, array $files, ?string $uuid, ?InstanceState $state, ?EnvFiles $keptOut = null): void
    {
        $entries = [];
        foreach ($files as $name) {
            $path = $this->directory->path($relative.'/'.$name);
            $entries[$name] = ['bytes' => (int) filesize($path), 'sha256' => (string) hash_file('sha256', $path)];
        }
        $app = $keptOut?->read('app') ?? [];
        $keys = array_values(array_filter([$app['APP_KEY'] ?? '', ...explode(',', $app['APP_PREVIOUS_KEYS'] ?? '')], static fn (string $key): bool => trim($key) !== ''));
        $manifest = [
            'schema_version' => '1.0', 'kind' => 'final', 'created_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
            'uuid' => $uuid, 'version' => $state?->version(), 'files' => $entries,
            ...($keptOut instanceof EnvFiles ? ['app_key' => 'not included: the adopted 1.0 checkout\'s .env holds it', 'app_key_fingerprints' => AppKeys::of($keys)->fingerprints()] : []),
        ];
        $this->directory->write($relative.'/backup.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", 0600);
    }

    /** @param  list<DockerResource>  $volumes */
    private function find(array $volumes, string $volume): bool
    {
        // A restored storage volume (ADR 0085) is fsa's own, labelled with its role instead of Compose's volume label.
        return array_any($volumes, static fn (DockerResource $v): bool => ($v->labels['com.docker.compose.volume'] ?? null) === $volume
            || ($v->labels[Labels::ROLE] ?? null) === $volume || str_ends_with($v->name, '_'.$volume));
    }
}
