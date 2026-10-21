<?php

declare(strict_types=1);

namespace App\Domain\Instance\Tunnel;

use App\Domain\Instance\Compose\Services\TunnelService;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\RuntimeWriter;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The quick tunnel as it is right now (R20, AE6, ADR 0082), read-only: what `fieldset status` reports and what decides
 * whether the next mutating command removes it (TunnelSweeper).
 *
 * - `none`: no tunnel in the state and none in runtime.json.
 * - `active`: hostname recorded, not expired, cloudflared running.
 * - `expired`: past `expires_at` (the app already answers 503 on the tunnel host); the container may still run.
 * - `stopped`: configured, but cloudflared does not run (it exited; `restart: "no"`) or no hostname was recorded.
 */
final readonly class TunnelStatus
{
    public function __construct(private InstanceDirectory $directory, private DockerGateway $docker) {}

    /**
     * @return array{state: string, hostname: string|null, url: string|null, expires_at: string|null, admin: bool, peer: string|null, container: string|null}
     */
    public function of(InstanceState $state, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $tunnel = new RuntimeWriter($this->directory)->tunnel();
        $configured = InstanceSettings::of($state)->tunnel();
        $container = $configured ? $this->container($state) : null;
        $expires = $this->time($tunnel['expires_at']);
        $status = match (true) {
            ! $configured && $tunnel['hostname'] === null => 'none',
            $tunnel['hostname'] !== null && (! $expires instanceof CarbonImmutable || ! $expires->isAfter($now)) => 'expired',
            // Docker that cannot say (null) is no evidence of a stopped tunnel.
            ! $configured || $tunnel['hostname'] === null || ($container !== null && $container !== 'running') => 'stopped',
            default => 'active',
        };

        return [
            'state' => $status, 'hostname' => $tunnel['hostname'], 'url' => $status === 'active' ? 'https://'.$tunnel['hostname'] : null,
            'expires_at' => $tunnel['expires_at'], 'admin' => $tunnel['admin'], 'peer' => $tunnel['peer'], 'container' => $container,
        ];
    }

    /** The tunnel container's state (`running`, `exited` …), `missing` when there is none, null when Docker cannot say. */
    private function container(InstanceState $state): ?string
    {
        try {
            $containers = ComposeFile::serviceContainers($this->docker->composePs(ComposeProject::forInstance($state, $this->directory)));
        } catch (Throwable) {
            return null;
        }
        $tunnel = array_find($containers, static fn (ContainerInfo $c): bool => $c->service() === TunnelService::NAME);

        return $tunnel instanceof ContainerInfo ? $tunnel->state : 'missing';
    }

    private function time(?string $value): ?CarbonImmutable
    {
        try {
            return $value === null ? null : CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
