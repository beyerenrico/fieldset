<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

/**
 * A `docker run` outside the compose project: the detached supervisor (KTD5) and throwaway probes (port checks).
 * Environment values travel in the CLI's process environment (`-e NAME`), never in argv. `enginePlatform` runs the
 * image as the engine's own platform even when DOCKER_DEFAULT_PLATFORM selects another (ADR 0070): for tools whose
 * platform does not matter (cosign), since Docker's classic image store keeps one platform per by-digest reference.
 * Hardening (M12 gate S6, RunArguments): every container runs with `no-new-privileges`; `capabilities` (when not null)
 * is all it keeps (`--cap-drop ALL` plus these), `readOnlyRoot` makes its root file system read-only, `tmpfs` mounts.
 */
final readonly class ContainerSpec
{
    /** Capabilities of a one-off that runs as root over files of other owners (a numeric-owner copy). */
    public const array OWNER_COPY = ['CHOWN', 'DAC_OVERRIDE', 'FOWNER', 'FSETID'];

    /** A one-off that runs as root and only reads files of other owners (du, pg_controldata, an archive). */
    public const array READ_ANY = ['DAC_READ_SEARCH'];

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $labels
     * @param  array<string, string>  $env
     * @param  list<string>  $volumes  `source:target[:ro]`
     * @param  list<string>  $ports  `[ip:]host:container`
     * @param  list<string>  $groupAdd
     * @param  list<string>|null  $capabilities  null: Docker's default set
     * @param  list<string>  $tmpfs
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
        public bool $enginePlatform = false,
        public ?array $capabilities = null,
        public bool $readOnlyRoot = false,
        public array $tmpfs = [],
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
            detach: true, remove: true, restart: 'no', enginePlatform: $this->enginePlatform,
            capabilities: $this->capabilities, readOnlyRoot: $this->readOnlyRoot, tmpfs: $this->tmpfs,
        );
    }

    /**
     * The same container with only `$capabilities`, a read-only root file system and a tmpfs `/tmp` (a one-off that
     * parses input from a backup or another install, M12 gate S6).
     *
     * @param  list<string>  $capabilities
     */
    public function hardened(array $capabilities = [], bool $readOnlyRoot = true): self
    {
        return new self(
            image: $this->image, command: $this->command, name: $this->name, labels: $this->labels, env: $this->env, volumes: $this->volumes,
            ports: $this->ports, network: $this->network, user: $this->user, entrypoint: $this->entrypoint, workdir: $this->workdir,
            groupAdd: $this->groupAdd, detach: $this->detach, remove: $this->remove, restart: $this->restart, enginePlatform: $this->enginePlatform,
            capabilities: $capabilities, readOnlyRoot: $readOnlyRoot, tmpfs: $readOnlyRoot ? ['/tmp'] : $this->tmpfs,
        );
    }
}
