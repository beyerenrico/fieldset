<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

use App\Domain\Instance\InstanceException;

/** Decodes Docker's JSON outputs: one document (`--format '{{json .}}'`) or a list (NDJSON lines or an array). */
final class DockerJson
{
    /** @return array<string, mixed> */
    public static function object(string $stdout, string $command): array
    {
        $decoded = json_decode(trim($stdout), true, 512, JSON_BIGINT_AS_STRING);
        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new InstanceException("Unexpected output from `docker {$command}`.", errorCode: 'docker_output_invalid');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Compose ≥ 2.21 and the list commands print one JSON object per line; older Compose printed an array.
     *
     * @return list<array<string, mixed>>
     */
    public static function lines(string $stdout, string $command): array
    {
        $stdout = trim($stdout);
        if ($stdout === '') {
            return [];
        }
        if (str_starts_with($stdout, '[')) {
            $decoded = json_decode($stdout, true, 512, JSON_BIGINT_AS_STRING);
            if (! is_array($decoded) || ! array_is_list($decoded)) {
                throw new InstanceException("Unexpected output from `docker {$command}`.", errorCode: 'docker_output_invalid');
            }

            return array_map(static fn (mixed $item): array => is_array($item) ? self::object((string) json_encode($item), $command) : [], $decoded);
        }

        return array_map(static fn (string $line): array => self::object($line, $command), preg_split('/\R/', $stdout) ?: []);
    }
}
