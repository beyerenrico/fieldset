<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

/**
 * The only way `fieldset` reaches Docker (KTD1). Bound only inside a `fieldset:*` command running in a marked instance
 * directory (InstanceServiceProvider); web, queue and scheduler code must never depend on it (arch test).
 *
 * Mutating calls throw DockerCommandFailed on a non-zero exit and DockerUnavailable when the daemon or CLI is
 * missing. `composeRun` and `runContainer` return the outcome instead, because one-offs and probes report
 * through their exit code.
 */
interface DockerGateway
{
    public function versions(): DockerVersions;

    /** The engine's view of the host: architecture, memory, rootless, clock (doctor). */
    public function info(): DockerInfo;

    /** @param  list<string>  $services  empty = all services */
    public function composeUp(ComposeProject $project, array $services = [], ?int $waitTimeout = null, bool $removeOrphans = false): void;

    /** @param  list<string>  $services  empty = all services */
    public function composeStop(ComposeProject $project, array $services = [], ?int $timeout = null): void;

    /** @param  list<string>  $services  empty = all stopped service containers */
    public function composeRm(ComposeProject $project, array $services = [], bool $volumes = false): void;

    public function composeDown(ComposeProject $project, bool $volumes = false): void;

    /**
     * A one-off container of a service (`compose run --rm -T`), e.g. migrate or app:install without the socket.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $env  passed by name; values never appear in argv
     */
    public function composeRun(
        ComposeProject $project,
        string $service,
        array $command = [],
        array $env = [],
        ?string $user = null,
        ?string $input = null,
        ?float $timeout = null,
        bool $withDependencies = false,
    ): ProcessOutcome;

    /**
     * A one-off like composeRun whose stdout streams into `$outputFile` (created exclusively, mode 0600) instead
     * of memory: database dumps and volume archives. `$entrypoint` replaces the image's entrypoint.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $env  passed by name; values never appear in argv
     */
    public function composeRunToFile(
        ComposeProject $project,
        string $service,
        array $command,
        string $outputFile,
        array $env = [],
        ?string $entrypoint = null,
        ?float $timeout = null,
    ): ProcessOutcome;

    /** @param  list<string>  $services */
    public function composeLogs(ComposeProject $project, array $services = [], ?string $since = null, ?int $tail = null): string;

    /** @return list<ContainerInfo> every container of the project, running or not */
    public function composePs(ComposeProject $project): array;

    /** @return array<string, mixed> the normalized model (`compose config --format json`); throws when invalid */
    public function composeConfig(ComposeProject $project): array;

    /** @param  bool  $enginePlatform  the engine's own platform, whatever DOCKER_DEFAULT_PLATFORM says (ContainerSpec) */
    public function pullImage(string $reference, bool $enginePlatform = false): void;

    public function inspectImage(string $reference): ?ImageInfo;

    public function inspectContainer(string $id): ?ContainerInfo;

    /**
     * The values of these keys in a container's environment (`Config.Env`), nothing else kept; null when there is no such
     * container. `fieldset adopt` compares what a 1.0 container runs with against its checkout's `.env` (plan U19).
     *
     * @param  list<string>  $keys
     * @return array<string, string>|null
     */
    public function containerEnv(string $id, array $keys): ?array;

    /** A network by name or ID with its subnets, gateways and attached containers; null when there is none. */
    public function inspectNetwork(string $name): ?NetworkInfo;

    /**
     * @param  array<string, string|null>  $labels  a null value matches any value of that label
     * @return list<ContainerInfo>
     */
    public function containers(array $labels, bool $all = true): array;

    /**
     * @param  array<string, string|null>  $labels
     * @return list<DockerResource>
     */
    public function volumes(array $labels): array;

    /**
     * @param  array<string, string|null>  $labels
     * @return list<DockerResource>
     */
    public function networks(array $labels): array;

    /**
     * The IPAM subnets of every Docker network on the engine, whoever created it: a fixed subnet (the edge
     * network, U8) must not overlap one of them.
     *
     * @return list<string> CIDR notation, IPv4 and IPv6
     */
    public function networkSubnets(): array;

    /** `docker run` (detached: stdout holds the container ID). */
    public function runContainer(ContainerSpec $spec, ?string $input = null, ?float $timeout = null): ProcessOutcome;

    public function removeContainer(string $id, bool $force = true): void;

    /** The last lines of a container's output (stdout and stderr), e.g. of an upgrade child that crashed (ADR 0086). */
    public function containerLogs(string $id, int $tail = 50): string;

    /**
     * A `docker run` like runContainer whose stdout streams into `$outputFile` (created exclusively, mode 0600) instead of
     * memory: the dump and the storage archive of a 1.0 install fieldset adopts (plan U19), read through one-offs of its own.
     */
    public function runContainerToFile(ContainerSpec $spec, string $outputFile, ?float $timeout = null): ProcessOutcome;

    /**
     * Stops containers by ID (`docker container stop`), for a project fieldset did not create and must not recreate: the 1.0
     * install `fieldset adopt` takes over (plan U19). `$timeout` is the grace before SIGKILL.
     *
     * @param  list<string>  $ids
     */
    public function stopContainers(array $ids, ?int $timeout = null): void;

    /**
     * Starts existing containers by ID (`docker container start`), unchanged: brings a 1.0 install back after a failed
     * adopt (plan U19), where `compose up` would recreate its containers from a file fieldset cannot render.
     *
     * @param  list<string>  $ids
     */
    public function startContainers(array $ids): void;

    /**
     * Creates a named volume with these labels (a restore's fresh storage volume, plan U17); an existing volume of that
     * name fails, so a restore never writes into a volume it did not just create.
     *
     * @param  array<string, string>  $labels
     */
    public function createVolume(string $name, array $labels): void;

    public function removeVolume(string $name): void;

    public function removeNetwork(string $name): void;
}
