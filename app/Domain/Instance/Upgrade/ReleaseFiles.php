<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Backup\BackupStore;
use App\Domain\Instance\Compose\Services\EdgeService;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\UnsafePath;

/**
 * The files a release renders into the instance directory and an upgrade or a rollback replaces (plan U18, ADR 0086,
 * review U18 A3, A10, S2): the compose file, the env files of its services, the edge's directory (the Caddyfile) and the
 * backup service's `staging/backup/instance.json`. Nothing else of a stage is ever taken (state, journals, locks, backups,
 * releases), and modes are fsa's own: 0600 for the compose and env files and the backup service's file, 0644 for the
 * edge's (Caddy reads it as another user).
 *
 * Snapshots (`operations/<name>/`, 0700, files 0600) keep the live files before a stage replaces them, so a way back puts
 * exactly those back, whichever image runs it, without rendering a release with another release's renderer:
 * `files-before-upgrade-<id>` (step 7, kept as `previous-release` once the upgrade committed, for `fsa rollback`) and
 * `files-before-rollback-<id>` (a rollback's swap). A snapshot is written to a temporary name and renamed, so a kill never
 * leaves half of one; the first complete one of a run is kept.
 */
final readonly class ReleaseFiles
{
    public const string PREVIOUS = 'previous-release';

    private const string ENV = '#^'.InstanceDirectory::ENV_DIR.'/([a-z][a-z0-9-]{0,62})\.env$#D';

    private const string EDGE = '#^'.EdgeService::DIRECTORY.'/[A-Za-z0-9][A-Za-z0-9._-]{0,127}$#D';

    private const string META = BackupStore::META;

    public function __construct(private InstanceDirectory $directory) {}

    /**
     * Whether a staged file is one fsa takes; `runtime/` is skipped, never taken (the gate stays as the supervisor set it).
     *
     * @param  list<string>  $services  the staged compose file's services
     */
    public static function allowed(string $file, array $services): bool
    {
        if ($file === InstanceDirectory::COMPOSE_FILE || $file === self::META || preg_match(self::EDGE, $file) === 1) {
            return true;
        }

        return preg_match(self::ENV, $file, $match) === 1 && in_array($match[1], $services, true);
    }

    /** Whether a file is a release file of any service (a snapshot's own files). */
    private function known(string $file): bool
    {
        return $file === InstanceDirectory::COMPOSE_FILE || $file === self::META || preg_match(self::EDGE, $file) === 1 || preg_match(self::ENV, $file) === 1;
    }

    public static function runtime(string $file): bool
    {
        return str_starts_with($file, InstanceDirectory::RUNTIME_DIR.'/');
    }

    /** fsa's mode for a release file. */
    public static function mode(string $file): int
    {
        return str_starts_with($file, EdgeService::DIRECTORY.'/') ? 0644 : 0600;
    }

    /** @return list<string> the release files the instance has now */
    public function live(): array
    {
        $files = [];
        foreach ([InstanceDirectory::COMPOSE_FILE, self::META] as $file) {
            if (is_file($this->directory->path($file)) && ! is_link($this->directory->path($file))) {
                $files[] = $file;
            }
        }
        foreach (new EnvFiles($this->directory)->services() as $service) {
            $files[] = InstanceDirectory::ENV_DIR.'/'.$service.'.env';
        }
        foreach ($this->directory->exists(EdgeService::DIRECTORY) ? $this->directory->files()->list(EdgeService::DIRECTORY) : [] as $name) {
            if (preg_match(self::EDGE, EdgeService::DIRECTORY.'/'.$name) === 1 && is_file($this->directory->path(EdgeService::DIRECTORY.'/'.$name))) {
                $files[] = EdgeService::DIRECTORY.'/'.$name;
            }
        }

        return $files;
    }

    /** Copies the live release files into `operations/<name>/` once (an existing complete snapshot is kept). */
    public function snapshot(string $name): void
    {
        $target = $this->path($name);
        if (is_dir($target) && ! is_link($target)) {
            return;
        }
        $temporary = $target.'.tmp';
        UpgradeFiles::remove($temporary);
        $copy = InstanceDirectory::initialize($temporary);
        foreach ($this->live() as $file) {
            $dir = dirname($file);
            if ($dir !== '.') {
                $copy->ensureDirectory($dir);
            }
            $copy->write($file, (string) $this->directory->read($file), 0600);
        }
        if (! @rename($temporary, $target)) {
            throw new UnsafePath($target, 'the snapshot cannot be moved into place');
        }
    }

    public function exists(string $name): bool
    {
        return is_dir($this->path($name)) && ! is_link($this->path($name));
    }

    /**
     * Puts a snapshot's files back: each with fsa's mode, release files the snapshot lacks removed.
     *
     * @return bool whether there was a snapshot
     */
    public function restore(string $name): bool
    {
        if (! $this->exists($name)) {
            return false;
        }
        $copy = InstanceDirectory::open($this->path($name));
        $files = array_values(array_filter(StageRenderer::list($copy->root), $this->known(...)));
        foreach ($files as $file) {
            $this->put($file, (string) $copy->read($file));
        }
        foreach (array_diff($this->live(), $files) as $stale) {
            $this->directory->files()->delete($stale);
        }

        return true;
    }

    /** Writes one release file with fsa's mode (the env and staging directories 0700, the edge's 0755). */
    public function put(string $file, string $contents): void
    {
        $dir = dirname($file);
        if ($dir !== '.') {
            $this->directory->ensureDirectory($dir, $dir === EdgeService::DIRECTORY ? 0755 : 0700);
        }
        $this->directory->write($file, $contents, self::mode($file));
    }

    /** Moves a snapshot to another name (replacing it); nothing when it does not exist. */
    public function promote(string $name, string $as): void
    {
        if (! $this->exists($name)) {
            return;
        }
        UpgradeFiles::remove($this->path($as));
        if (! @rename($this->path($name), $this->path($as))) {
            throw new UnsafePath($this->path($as), 'the snapshot cannot be moved into place');
        }
    }

    public function discard(string $name): void
    {
        UpgradeFiles::remove($this->path($name));
        UpgradeFiles::remove($this->path($name).'.tmp');
    }

    /** @return array<string, array<string, string>> service => env values of a snapshot (empty without one) */
    public function envOf(string $name): array
    {
        if (! $this->exists($name)) {
            return [];
        }
        $env = new EnvFiles(InstanceDirectory::open($this->path($name)));

        return array_combine($env->services(), array_map($env->read(...), $env->services()));
    }

    public function path(string $name): string
    {
        return $this->directory->path(InstanceDirectory::OPERATIONS_DIR.'/'.$name);
    }
}
