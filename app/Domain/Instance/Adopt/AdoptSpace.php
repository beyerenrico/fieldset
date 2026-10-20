<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\Backup\BackupStore;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Restore\RestoreSpace;
use Closure;

/**
 * The space check before adopt changes anything (plan U19 "doctor first checks for twice the data's space", ADR 0087; exit
 * 3): the 1.0 volumes' bytes (`du` in a one-off of the app image that mounts both read-only, without a network) twice, once
 * for the 1.0-format backup and once for the copy, plus 1 GiB of headroom, on the file system of the instance directory.
 * Docker keeps the copy in its own data root, which is that disk on most single-disk hosts (the restore check's estimate,
 * ADR 0085). Unknown sizes or free space pass with a warning, like the restore check.
 */
final readonly class AdoptSpace
{
    public function __construct(private DockerGateway $docker, private BackupStore $store) {}

    /**
     * @return array{data_bytes: int|null, required: int|null, free: int|null}
     *
     * @throws InstanceException exit 3 `low_disk_space`
     */
    public function assert(LegacyInstall $legacy, string $appImage, string $uuid): array
    {
        $bytes = $this->dataBytes($legacy, $appImage, $uuid);
        $probe = app()->bound(RestoreSpace::FREE_BYTES) ? app(RestoreSpace::FREE_BYTES) : null;
        $free = $probe instanceof Closure ? $probe() : $this->store->freeBytes();
        $required = $bytes === null ? null : 2 * $bytes + RestoreSpace::HEADROOM;
        if ($required !== null && is_int($free) && $free < $required) {
            throw new InstanceException(
                sprintf('Not enough free disk space to adopt: %d MiB needed (twice the 1.0 data for the backup and the copy, and headroom), %d MiB free.', intdiv($required, 1024 ** 2), intdiv($free, 1024 ** 2)),
                ExitCode::PreflightFailed, 'low_disk_space',
                'Free space on the disk that holds the instance directory and Docker, then run adopt again; nothing was changed and 1.0 keeps running.',
                false, ['required_bytes' => $required, 'free_bytes' => $free, 'data_bytes' => $bytes],
            );
        }

        return ['data_bytes' => $bytes, 'required' => $required, 'free' => is_int($free) ? $free : null];
    }

    private function dataBytes(LegacyInstall $legacy, string $appImage, string $uuid): ?int
    {
        $volumes = [];
        foreach ($legacy->volumes as $key => $name) {
            $volumes[] = $name.':/legacy/'.$key.':ro';
        }
        $outcome = $this->docker->runContainer(new ContainerSpec(
            image: $appImage, command: ['-s', '-B1', '--', ...array_map(static fn (string $key): string => '/legacy/'.$key, array_keys($legacy->volumes))],
            labels: Labels::forInstance($uuid, DatabaseProbe::ROLE), volumes: $volumes, network: 'none', user: '0:0', entrypoint: 'du',
        )->hardened(ContainerSpec::READ_ANY), null, 600.0);
        if (! $outcome->successful() || preg_match_all('/^(\d+)\s/m', $outcome->stdout, $sizes) !== count($legacy->volumes)) {
            return null;
        }

        return array_sum(array_map(intval(...), $sizes[1]));
    }
}
