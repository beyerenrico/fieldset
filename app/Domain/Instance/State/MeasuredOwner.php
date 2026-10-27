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
    /** The code of an owner the launcher could not measure: the doctor's warning and repair-ownership's error. */
    public const string NOT_MEASURED = 'owner_not_measured';

    public static function record(InstanceState $state, HostFacts $facts): InstanceState
    {
        if (! self::applies($state, $facts)) {
            return $state;
        }

        return $state->withOwnerMeasurement($facts->runtime, $facts->socketGroup, CarbonImmutable::now('UTC'));
    }

    /**
     * The launcher measured the owner in this run (FIELDSET_OWNER_SOURCE=measured) under a known runtime.
     *
     * @phpstan-assert-if-true DockerRuntime $facts->runtime
     */
    public static function inThisRun(HostFacts $facts): bool
    {
        return $facts->ownerSource === OwnerSource::Measured && $facts->runtime instanceof DockerRuntime;
    }

    /**
     * A measurement of this run under a known runtime, of the owner the state already has: the one that may be saved.
     *
     * @phpstan-assert-if-true DockerRuntime $facts->runtime
     */
    public static function applies(InstanceState $state, HostFacts $facts): bool
    {
        return self::inThisRun($facts) && $facts->measuredOwnerUid() === $state->containerUid();
    }
}
