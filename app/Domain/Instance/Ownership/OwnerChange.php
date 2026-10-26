<?php

declare(strict_types=1);

namespace App\Domain\Instance\Ownership;

use App\Domain\Instance\Docker\DockerRuntime;
use App\Domain\Instance\Docker\OwnerSource;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\MeasuredOwner;
use Carbon\CarbonImmutable;

/**
 * What `fieldset repair-ownership` changes (2.1.1 U4, R28, KTD12): the in-container owner saved with the instance (`from`)
 * becomes the one the shim measured in this run (`to`); files get `to` and its group (0 for root, else the host user's
 * group, as everywhere the owner is used). Only a measurement of this run counts: a saved owner or the product-name rules
 * are what may be wrong.
 */
final readonly class OwnerChange
{
    private function __construct(
        public int $from,
        public int $to,
        public int $group,
        public DockerRuntime $runtime,
        public ?int $socketGroup,
    ) {}

    /** @throws InstanceException exit 3 `owner_not_measured` when the launcher did not measure the owner in this run */
    public static function plan(InstanceState $state, HostFacts $facts): self
    {
        $to = $facts->measuredOwnerUid();
        if ($to === null || ! $facts->runtime instanceof DockerRuntime) {
            throw new InstanceException(
                'fieldset did not measure who owns the instance files inside containers in this run, so it cannot repair them.',
                ExitCode::PreflightFailed,
                MeasuredOwner::NOT_MEASURED,
                'Run the instance\'s own script once Docker can start containers (`<dir>/fieldset repair-ownership`): it measures the owner first.',
            );
        }

        return new self($state->containerUid(), $to, $to === 0 ? 0 : $state->hostGid(), $facts->runtime, $facts->socketGroup);
    }

    public function needed(): bool
    {
        return $this->from !== $this->to;
    }

    /** The state with the measured owner saved: `container_uid`, runtime, socket group and time (KTD5). */
    public function applyTo(InstanceState $state, CarbonImmutable $measuredAt): InstanceState
    {
        return $state->withOwnerMeasurement($this->runtime, $this->socketGroup, $measuredAt, $this->to);
    }

    public function question(): string
    {
        return "Give the instance files from UID {$this->from} to UID {$this->to} (as containers see them) and save UID {$this->to} as the instance owner?"
            .' Modes, symlinks and Docker volumes stay as they are; the backup service restarts.';
    }

    /** @return array{owner: array{from: int, to: int, group: int, runtime: string, source: string}} */
    public function toArray(): array
    {
        return ['owner' => ['from' => $this->from, 'to' => $this->to, 'group' => $this->group, 'runtime' => $this->runtime->value, 'source' => OwnerSource::Measured->value]];
    }
}
