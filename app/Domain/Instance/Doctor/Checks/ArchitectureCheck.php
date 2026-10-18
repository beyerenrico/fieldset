<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor\Checks;

use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\DoctorCheck;
use App\Domain\Instance\Doctor\DoctorContext;

/**
 * The platform the instance runs must be one the release ships (R1, ADR 0051: linux/amd64 and linux/arm64): the
 * engine's own, or the one DOCKER_DEFAULT_PLATFORM selects (ADR 0070). Another architecture than the engine's
 * runs under emulation, which works but is much slower: a warning.
 */
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
        $engine = 'linux/'.$info->platformArchitecture();
        $requested = $context->facts->requestedPlatform();
        $platform = $requested ?? $engine;
        $supported = $context->platforms === [] ? self::DEFAULT_PLATFORMS : $context->platforms;
        $details = [
            'platform' => $platform, 'engine_platform' => $engine, 'emulated' => $platform !== $engine,
            'docker_default_platform' => $context->facts->platform, 'release_platforms' => $supported, 'os' => $info->operatingSystem,
        ];

        if (! in_array($platform, $supported, true)) {
            return $requested === null
                ? CheckResult::fail($this->key(), "The engine runs {$platform}; this release ships ".implode(' and ', $supported).'.', 'Use an amd64 or arm64 Linux host (or Docker Desktop on one).', $details)
                : CheckResult::fail($this->key(), "DOCKER_DEFAULT_PLATFORM selects {$platform}; this release ships ".implode(' and ', $supported).'.', 'Set DOCKER_DEFAULT_PLATFORM to one of these platforms, or unset it.', $details);
        }

        if ($platform !== $engine) {
            $hint = in_array($engine, $supported, true)
                ? "Unset DOCKER_DEFAULT_PLATFORM to run the {$engine} images this release also ships."
                : "Fine for a trial; run a production instance on a {$platform} host.";

            return CheckResult::warn($this->key(), "The engine runs {$engine}; DOCKER_DEFAULT_PLATFORM runs the {$platform} images under emulation, which is much slower.", $hint, $details);
        }

        return CheckResult::ok($this->key(), "The engine runs {$platform}.", $details);
    }
}
