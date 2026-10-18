<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor\Checks;

use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\DoctorCheck;
use App\Domain\Instance\Doctor\DoctorContext;

/** The engine's architecture must be one the release ships (R1, ADR 0051: linux/amd64 and linux/arm64). */
final readonly class ArchitectureCheck implements DoctorCheck
{
    private const array DEFAULT_PLATFORMS = ['linux/amd64', 'linux/arm64'];

    public function key(): string
    {
        return 'architecture';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $info = $context->info();
        $platform = 'linux/'.$info->platformArchitecture();
        $supported = $context->platforms === [] ? self::DEFAULT_PLATFORMS : $context->platforms;
        $details = ['platform' => $platform, 'release_platforms' => $supported, 'os' => $info->operatingSystem];

        return in_array($platform, $supported, true)
            ? CheckResult::ok($this->key(), "The engine runs {$platform}.", $details)
            : CheckResult::fail($this->key(), "The engine runs {$platform}; this release ships ".implode(' and ', $supported).'.', 'Use an amd64 or arm64 Linux host (or Docker Desktop on one).', $details);
    }
}
