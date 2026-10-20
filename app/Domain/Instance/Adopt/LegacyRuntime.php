<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;

/**
 * What the running 1.0 install really is, beyond its checkout's files (plan U19, ADR 0087), checked before adopt changes
 * anything (exit 7):
 *
 * - review S2, V5: the APP keys, database names, port and Valkey database adopt takes from `.env` must be the ones 1.0's
 *   app container runs with (`Config.Env`, read for these keys only): a `.env` edited since 1.0 started (a planted
 *   APP_PREVIOUS_KEYS, another APP_KEY, another REDIS_DB the drain would count in) is refused, `env_mismatch`;
 * - review A4: 1.0's Postgres must be major 16 on Alpine (musl), like the instance's `postgres:16-alpine`: a binary copy
 *   into another libc would leave text indexes, unique or not, ordered differently from what the new server expects.
 *   PG_MAJOR comes from the container's environment, the distribution from `/etc/os-release` of its image (a one-off
 *   without a network). Another major or libc is `postgres_image_mismatch`; REINDEXing a copy was rejected (ADR 0087).
 */
final readonly class LegacyRuntime
{
    /** The `.env` values that must match the app container's environment. */
    public const array COMPARED = ['APP_KEY', 'APP_PREVIOUS_KEYS', 'DB_DATABASE', 'DB_USERNAME', 'APP_PORT', 'REDIS_DB'];

    public const string PG_MAJOR = '16';

    public function __construct(private DockerGateway $docker) {}

    /**
     * @return array{env_checked: string, postgres: array{major: string, os: string}}
     *
     * @throws InstanceException exit 7
     */
    public function assert(LegacyInstall $legacy, LegacyCheckout $checkout, string $uuid): array
    {
        return ['env_checked' => $this->assertEnv($legacy, $checkout), 'postgres' => $this->assertPostgres($legacy, $uuid)];
    }

    /** @return string the service whose environment was compared */
    private function assertEnv(LegacyInstall $legacy, LegacyCheckout $checkout): string
    {
        $service = array_find(['app', 'queue', 'scheduler'], static fn (string $service): bool => isset($legacy->containers[$service]));
        $env = $service === null ? null : $this->docker->containerEnv($legacy->containers[$service], self::COMPARED);
        if ($env === null) {
            throw $this->refused('No app container of the 1.0 install can be read, so fsa cannot tell what it runs with.', 'legacy_not_found', 'Start 1.0 in its checkout (`docker compose up -d`) and run adopt again.');
        }
        $differ = array_values(array_filter(self::COMPARED, static fn (string $key): bool => self::value($checkout->value($key)) !== self::value($env[$key] ?? null)));
        if ($differ !== []) {
            throw $this->refused('The checkout\'s .env differs from what 1.0 runs with: '.implode(', ', $differ).'.', 'env_mismatch', 'Restore the .env 1.0 was started with, or restart 1.0 with this one (`docker compose up -d` in the checkout), then run adopt again.');
        }

        return (string) $service;
    }

    /** @return array{major: string, os: string} */
    private function assertPostgres(LegacyInstall $legacy, string $uuid): array
    {
        $container = $this->docker->inspectContainer($legacy->postgres());
        $major = $this->docker->containerEnv($legacy->postgres(), ['PG_MAJOR'])['PG_MAJOR'] ?? '';
        $outcome = $container instanceof ContainerInfo && $container->image !== null ? $this->docker->runContainer(new ContainerSpec(
            image: $container->image, command: ['/etc/os-release'], labels: Labels::forInstance($uuid, DatabaseProbe::ROLE), network: 'none', entrypoint: 'cat',
        )->hardened(), null, 60.0) : null;
        $os = $outcome?->successful() === true && preg_match('/^ID="?([a-z0-9._-]+)"?$/m', $outcome->stdout, $match) === 1 ? $match[1] : 'unknown';
        if ($major !== self::PG_MAJOR || $os !== 'alpine') {
            throw $this->refused("1.0's Postgres is major ".($major === '' ? 'unknown' : $major)." on {$os}, not ".self::PG_MAJOR.' on Alpine: a file copy into the instance\'s postgres:16-alpine would leave its text indexes ordered for another C library.', 'postgres_image_mismatch', 'Move the data with a 1.0 backup instead (`scripts/backup.sh --include-env`, then `install.sh --dir <new directory> --from-backup <backup> --accept-unauthenticated`), which restores it with pg_restore.');
        }

        return ['major' => $major, 'os' => $os];
    }

    private static function value(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }

    private function refused(string $message, string $code, string $hint): InstanceException
    {
        return new InstanceException($message, ExitCode::Incompatible, $code, $hint);
    }
}
