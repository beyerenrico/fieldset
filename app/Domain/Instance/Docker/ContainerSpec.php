<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

/**
 * A `docker run` outside the compose project: the detached supervisor (KTD5) and throwaway probes (port checks).
 * Environment values travel in the CLI's process environment (`-e NAME`), never in argv.
 */
final readonly class ContainerSpec
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $labels
     * @param  array<string, string>  $env
     * @param  list<string>  $volumes  `source:target[:ro]`
     * @param  list<string>  $ports  `[ip:]host:container`
     * @param  list<string>  $groupAdd
     */
    public function __construct(
        public string $image,
        public array $command = [],
        public ?string $name = null,
        public array $labels = [],
        public array $env = [],
        public array $volumes = [],
        public array $ports = [],
        public ?string $network = null,
        public ?string $user = null,
        public ?string $entrypoint = null,
        public ?string $workdir = null,
        public array $groupAdd = [],
        public bool $detach = false,
        public bool $remove = true,
        public ?string $restart = null,
    ) {}

    /**
     * The same container, named, labelled, detached, removed on exit and never restarted.
     *
     * @param  array<string, string>  $labels
     */
    public function detachedAs(string $name, array $labels): self
    {
        return new self(
            image: $this->image, command: $this->command, name: $name, labels: [...$this->labels, ...$labels],
            env: $this->env, volumes: $this->volumes, ports: $this->ports, network: $this->network, user: $this->user,
            entrypoint: $this->entrypoint, workdir: $this->workdir, groupAdd: $this->groupAdd,
            detach: true, remove: true, restart: 'no',
        );
    }
}
