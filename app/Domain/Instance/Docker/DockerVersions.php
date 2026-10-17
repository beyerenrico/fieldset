<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

/** Docker client, daemon and Compose versions, for doctor's "too old" check (exit 10). */
final readonly class DockerVersions
{
    public function __construct(
        public string $client,
        public string $server,
        public string $compose,
    ) {}
}
