<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor;

use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\DockerInfo;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Install\InstallRecord;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;

/**
 * What the doctor checks look at: host facts, the instance directory and state, the Docker engine, and what the
 * caller is about to do. `fsa install` selects ports (`selectPorts`) and passes the release it verified;
 * `fsa doctor` checks an existing instance (with `post`, its running services too). Mutable only for memos that
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
    ) {}

    public function info(): DockerInfo
    {
        return $this->info ??= $this->docker->info();
    }

    /** The UID instance files must belong to as containers see them (KTD2, ADR 0070). */
    public function ownerUid(): int
    {
        return $this->state?->containerUid() ?? $this->facts->ownerUidInContainers();
    }

    public function installed(): bool
    {
        return $this->state instanceof InstanceState && InstallRecord::isInstalled($this->state);
    }
}
