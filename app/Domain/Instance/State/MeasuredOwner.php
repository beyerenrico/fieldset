<?php

declare(strict_types=1);

namespace App\Domain\Instance\State;

use App\Domain\Instance\Docker\DockerRuntime;
use App\Domain\Instance\Docker\OwnerSource;
use App\Domain\Instance\HostFacts;
use Carbon\CarbonImmutable;

/**
 * Saves the launcher's owner measurement with the instance (2.1.1 U3, KTD3, KTD5): only a measurement taken in this run,
 * under a known runtime, whose owner is the one the state already has. The shim then uses the saved owner for commands
 * that do not measure, until the runtime it detects changes. A differing owner is never written here: only the owner
 * repair changes `container_uid` (KTD6).
 */
final class MeasuredOwner
{
    public static function record(InstanceState $state, HostFacts $facts): InstanceState
    {
        if ($facts->ownerSource !== OwnerSource::Measured || ! $facts->runtime instanceof DockerRuntime || $facts->containerUid !== $state->containerUid()) {
            return $state;
        }

        return $state->withOwnerMeasurement($facts->runtime, $facts->socketGroup, CarbonImmutable::now('UTC'));
    }
}
