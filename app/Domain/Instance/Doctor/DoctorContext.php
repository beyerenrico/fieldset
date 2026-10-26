<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor;

use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\DockerInfo;
use App\Domain\Instance\Docker\OwnerSource;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Install\InstallRecord;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;

/**
 * What the doctor checks look at: host facts, the instance directory and state, the Docker engine, and what the
 * caller is about to do. `fieldset install` selects ports (`selectPorts`) and passes the release it verified;
 * `fieldset doctor` checks an existing instance (with `post`, its running services too). Mutable only for memos that
 * later checks reuse (engine info, the registry's clock).
 */
final class DoctorContext
{
    /** The `Date` header of the first registry that answered: the clock check's reference (no extra call). */
    public ?string $registryDate = null;

    private ?DockerInfo $info = null;

    /**
     * @param  list<string>  $images  image references the instance runs (pinned by digest)
     * @param  list<string>  $platforms  platforms of the release, e.g. linux/arm64
     * @param  bool  $publishesAppPort  false for an install on a shared proxy network: the app gets no loopback port
     * @param  string|null  $lanAddress  local-CA mode (ADR 0082): the edge publishes 80 and 443 on this address
     * @param  bool  $catcher  false for an install with --smtp-host (ADR 0080 amendment): no catcher UI port to choose
     */
    public function __construct(
        public readonly HostFacts $facts,
        public readonly InstanceDirectory $directory,
        public readonly DockerGateway $docker,
        public readonly DoctorProbes $probes,
        public readonly ?InstanceState $state = null,
        public readonly ?string $domain = null,
        public readonly ?string $appImage = null,
        public readonly array $images = [],
        public readonly array $platforms = [],
        public readonly ?int $requestedPort = null,
        public readonly bool $selectPorts = false,
        public readonly ?string $uuid = null,
        public readonly bool $post = false,
        public readonly bool $publishesAppPort = true,
        public readonly ?string $lanAddress = null,
        public readonly bool $catcher = true,
    ) {}

    public function info(): DockerInfo
    {
        return $this->info ??= $this->docker->info();
    }

    /**
     * The UID instance files must belong to as containers see them (KTD2, ADR 0070): the owner the launcher measured in this
     * run, which this run's ops user follows (2.1.1 KTD3), else the one saved with the instance, else the launcher's.
     */
    public function ownerUid(): int
    {
        return $this->measuredOwnerUid() ?? $this->state?->containerUid() ?? $this->facts->ownerUidInContainers();
    }

    /** The owner saved with the instance (state.json's `owner.container_uid`), or null before the state exists. */
    public function savedOwnerUid(): ?int
    {
        return $this->state?->containerUid();
    }

    /** The owner the launcher measured in a container during this run (install, doctor and repair always do), or null. */
    public function measuredOwnerUid(): ?int
    {
        return $this->facts->ownerSource === OwnerSource::Measured ? $this->facts->containerUid : null;
    }

    /** Whether the measured owner differs from the saved one: `fieldset repair-ownership` fixes it (2.1.1 U4). */
    public function ownerMismatch(): bool
    {
        $measured = $this->measuredOwnerUid();
        $saved = $this->savedOwnerUid();

        return $measured !== null && $saved !== null && $measured !== $saved;
    }

    public function installed(): bool
    {
        return $this->state instanceof InstanceState && InstallRecord::isInstalled($this->state);
    }
}
