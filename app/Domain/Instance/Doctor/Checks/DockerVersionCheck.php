<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor\Checks;

use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\DoctorCheck;
use App\Domain\Instance\Doctor\DoctorContext;

/**
 * Docker Engine and Compose versions (R10). Too old is exit 10 (§7). The floors are what fieldset relies on: Engine 24
 * (API 1.43, healthcheck start intervals, `docker run` with several networks later) and Compose 2.24 (`up --wait`
 * with `--wait-timeout`, top-level `name`). The Compose plugin ships in the image, so in practice only the engine
 * can be too old.
 */
final readonly class DockerVersionCheck implements DoctorCheck
{
    public const string MIN_ENGINE = '24.0.0';

    public const string MIN_COMPOSE = '2.24.0';

    public function key(): string
    {
        return 'docker';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $versions = $context->docker->versions();
        $details = ['engine' => $versions->server, 'client' => $versions->client, 'compose' => $versions->compose];
        if (version_compare($this->plain($versions->server), self::MIN_ENGINE, '<')) {
            return CheckResult::fail($this->key(), "Docker Engine {$versions->server} is older than ".self::MIN_ENGINE.'.', 'Upgrade Docker Engine (https://docs.docker.com/engine/install/), then run the command again.', [...$details, 'too_old' => true]);
        }
        if (version_compare($this->plain($versions->compose), self::MIN_COMPOSE, '<')) {
            return CheckResult::fail($this->key(), "Docker Compose {$versions->compose} is older than ".self::MIN_COMPOSE.'.', 'Use the fieldset command from the instance directory; it brings its own Compose.', [...$details, 'too_old' => true]);
        }

        return CheckResult::ok($this->key(), "Docker Engine {$versions->server}, Compose {$versions->compose}.", $details);
    }

    /** "29.2.1-rd" → "29.2.1"; anything unparsable compares as 0. */
    private function plain(string $version): string
    {
        return preg_match('/^v?(\d+(?:\.\d+){0,2})/', $version, $match) === 1 ? $match[1] : '0';
    }
}
