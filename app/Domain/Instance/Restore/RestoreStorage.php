<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use App\Domain\Instance\Compose\Services\AppRoles;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use Throwable;

/**
 * The storage side of a restore (KTD18, plan U17, ADR 0085): a fresh volume, labelled for the instance, filled from the
 * backup's storage archive by GNU tar as the app UID (33, www-data) with numeric owners, without a network and with
 * nothing but the volume and the archive mounted. SafeExtractor judged every member before. The compose file then
 * declares it under `app-storage` (InstanceSettings::storageVolume); the volume it replaces stays until the restored
 * instance passed its verification.
 */
final readonly class RestoreStorage
{
    public const string ROLE = 'restore';

    /** The app image's www-data (Debian, 33:33): the owner of everything on the storage volume. */
    public const string APP_USER = '33:33';

    private const float TIMEOUT = 21600.0;

    public function __construct(private DockerGateway $docker) {}

    /** The volume the stack mounts now: a restored one, or Compose's own. */
    public static function current(InstanceState $state): string
    {
        return InstanceSettings::of($state)->storageVolume() ?? $state->project().'_'.AppRoles::STORAGE_VOLUME;
    }

    /** A fresh volume's name for one restore (`<project>_app-storage-r<id>`). */
    public static function fresh(InstanceState $state, string $id): string
    {
        return $state->project().'_'.AppRoles::STORAGE_VOLUME.'-r'.$id;
    }

    /**
     * Creates the volume (or refuses an existing one) and extracts the archive into it; nothing else is mounted. With
     * `$create` false the volume exists already (`--from-backup` creates it with the state, because every Compose call
     * checks that an external volume exists).
     */
    public function fill(InstanceState $state, string $volume, ?string $archive, bool $create = true): void
    {
        if ($create) {
            $this->create($state, $volume);
        }
        if ($archive === null) {
            return; // a database-only backup (1.0 --no-storage): the volume starts with the image's empty layout
        }
        $group = (string) ($state->containerUid() === 0 ? 0 : $state->hostGid()); // reads the 0640 archive
        $outcome = $this->docker->runContainer(new ContainerSpec(
            image: $state->digests()['app'],
            command: ['-x', '-z', '-f', $archive, '-C', AppRoles::STORAGE_PATH, '--numeric-owner', '--no-same-owner', '--no-same-permissions', '--delay-directory-restore'],
            labels: Labels::forInstance($state->uuid(), self::ROLE),
            volumes: [$volume.':'.AppRoles::STORAGE_PATH, dirname($archive).':'.dirname($archive).':ro'],
            network: 'none',
            user: self::APP_USER,
            entrypoint: 'tar',
            groupAdd: [$group],
        )->hardened(), null, self::TIMEOUT);
        if (! $outcome->successful()) {
            throw new InstanceException('Extracting the storage archive failed: '.$outcome->stderrTail(5), ExitCode::HealthCheckFailed, 'storage_restore_failed', 'Read the output; the live storage volume was not touched.', true, ['output' => $outcome->stderrTail(10)]);
        }
    }

    /** A fresh, empty volume labelled for the instance (refused when the name is taken). */
    public function create(InstanceState $state, string $volume): void
    {
        $this->docker->createVolume($volume, Labels::forInstance($state->uuid(), AppRoles::STORAGE_VOLUME));
    }

    /**
     * Deletes the data-export directories of accounts erased after the backup from the fresh volume (R64: the erasure
     * carry scrubs the rows; their files come back with the storage archive). Only `data-exports/<org>/<id>` below the
     * `local` and `public` disks qualifies; anything else is returned as skipped.
     *
     * @param  list<array{disk: string, path: string}>  $directories
     * @return array{removed: int, skipped: list<string>}
     */
    public function removeErased(InstanceState $state, string $volume, array $directories): array
    {
        $roots = ['local' => 'private', 'public' => 'public'];
        $paths = [];
        $skipped = [];
        foreach ($directories as $directory) {
            $root = $roots[$directory['disk']] ?? null;
            if ($root === null || preg_match('#^data-exports/\d{1,19}/\d{1,19}$#D', $directory['path']) !== 1) {
                $skipped[] = $directory['disk'].':'.$directory['path'];

                continue;
            }
            $paths[] = AppRoles::STORAGE_PATH.'/'.$root.'/'.$directory['path'];
        }
        if ($paths !== []) {
            $outcome = $this->docker->runContainer(new ContainerSpec(
                image: $state->digests()['app'], command: ['-rf', '--', ...$paths], labels: Labels::forInstance($state->uuid(), self::ROLE),
                volumes: [$volume.':'.AppRoles::STORAGE_PATH], network: 'none', user: self::APP_USER, entrypoint: 'rm',
            )->hardened(), null, 300.0);
            if (! $outcome->successful()) {
                throw new InstanceException('Removing the data exports of erased accounts from the restored storage failed: '.$outcome->stderrTail(3), ExitCode::HealthCheckFailed, 'erased_exports_failed', 'Read the output; the live storage volume was not touched.', true);
            }
        }

        return ['removed' => count($paths), 'skipped' => $skipped];
    }

    /** Removes a volume this restore created (or the one it replaced, once the restore is verified); best effort. */
    public function remove(string $volume): bool
    {
        try {
            $this->docker->removeVolume($volume);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
