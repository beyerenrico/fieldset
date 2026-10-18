<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose;

use SensitiveParameter;

/**
 * One compose service as a ServiceDefinition describes it. The renderer adds what every service gets: its env
 * file, the instance labels, the memory limit from ResourceBudget, the log cap and `restart: unless-stopped`
 * (a definition may set its own `restart`, as the tunnel's "no"). Top-level volumes and networks the service
 * needs are declared here too; the renderer labels them.
 */
final readonly class ServiceSpec
{
    /**
     * @param  array<string, mixed>  $definition  compose service keys, without env_file, labels, mem_limit
     * @param  array<string, string>  $environment  the whole environment, written to env/<name>.env (0600)
     * @param  array<string, array<string, mixed>>  $volumes  top-level named volumes by name
     * @param  array<string, array<string, mixed>>  $networks  top-level networks by name (default is implicit)
     * @param  array<string, string>  $files  instance-directory files the service reads (path => contents, no secret;
     *                                        written 0644 in 0755 folders, e.g. the edge's Caddyfile)
     */
    public function __construct(
        public string $name,
        public array $definition,
        #[SensitiveParameter] public array $environment = [],
        public array $volumes = [],
        public array $networks = [],
        public array $files = [],
    ) {}
}
