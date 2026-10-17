<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

/** `docker run` flags for a ContainerSpec (environment flags and the image are added by the gateway). */
final class RunArguments
{
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
        ];
        foreach ($spec->labels as $key => $value) {
            $args[] = '--label';
            $args[] = $key.'='.$value;
        }
        foreach ([['--volume', $spec->volumes], ['--publish', $spec->ports], ['--group-add', $spec->groupAdd]] as [$flag, $values]) {
            foreach ($values as $value) {
                $args[] = $flag;
                $args[] = $value;
            }
        }

        return $args;
    }

    /** @return list<string> */
    private static function optional(string $flag, ?string $value): array
    {
        return $value === null ? [] : [$flag, $value];
    }
}
