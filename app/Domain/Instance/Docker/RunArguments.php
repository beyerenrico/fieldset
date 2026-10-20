<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

/**
 * `docker run` flags for a ContainerSpec (environment flags and the image are added by the gateway). Every container
 * gets `no-new-privileges` (M12 gate S6): nothing in it gains privileges through a setuid binary. A spec that names its
 * capabilities drops all others (`--cap-drop ALL`); a read-only root file system and tmpfs mounts where the spec asks.
 */
final class RunArguments
{
    public const string NO_NEW_PRIVILEGES = 'no-new-privileges';

    /** @return list<string> */
    public static function for(ContainerSpec $spec, bool $withInput): array
    {
        $args = [
            ...($spec->detach ? ['--detach'] : []),
            ...($withInput ? ['--interactive'] : []),
            ...($spec->remove ? ['--rm'] : []),
            ...self::optional('--name', $spec->name),
            ...self::optional('--restart', $spec->restart),
            ...self::optional('--network', $spec->network),
            ...self::optional('--user', $spec->user),
            ...self::optional('--entrypoint', $spec->entrypoint),
            ...self::optional('--workdir', $spec->workdir),
            ...self::hardening($spec),
        ];
        foreach ($spec->labels as $key => $value) {
            $args[] = '--label';
            $args[] = $key.'='.$value;
        }
        foreach ([['--volume', $spec->volumes], ['--tmpfs', $spec->tmpfs], ['--publish', $spec->ports], ['--group-add', $spec->groupAdd]] as [$flag, $values]) {
            foreach ($values as $value) {
                $args[] = $flag;
                $args[] = $value;
            }
        }

        return $args;
    }

    /** @return list<string> */
    private static function hardening(ContainerSpec $spec): array
    {
        $args = ['--security-opt', self::NO_NEW_PRIVILEGES];
        if ($spec->capabilities !== null) {
            $args = [...$args, '--cap-drop', 'ALL'];
            foreach ($spec->capabilities as $capability) {
                $args = [...$args, '--cap-add', $capability];
            }
        }

        return $spec->readOnlyRoot ? [...$args, '--read-only'] : $args;
    }

    /** @return list<string> */
    private static function optional(string $flag, ?string $value): array
    {
        return $value === null ? [] : [$flag, $value];
    }
}
