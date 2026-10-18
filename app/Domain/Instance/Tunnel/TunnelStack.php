<?php

declare(strict_types=1);

namespace App\Domain\Instance\Tunnel;

use App\Domain\Instance\Compose\TunnelNetwork;
use App\Domain\Instance\Config\ConfigChange;
use App\Domain\Instance\Config\ConfigRollout;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\NetworkInfo;
use App\Domain\Instance\Output\ProgressReporter;
use App\Domain\Instance\State\InputGrammar;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\RuntimeWriter;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The quick tunnel in Docker and in the runtime directory (R20, KTD12, ADR 0082), on top of the `config set` rollout
 * (ConfigRollout, ADR 0077): the state is the single authority, so starting is "render the state with the tunnel and
 * bring the stack to it" (cloudflared on its network, the app joining it, TRUSTED_PROXIES naming cloudflared), and
 * stopping is the reverse; Compose recreates only what changed and removes the tunnel container as an orphan. Queue and
 * scheduler stop while the stack changes and start once it is verified (KTD19), so no job mails a tunnel link before
 * the tunnel is recorded.
 *
 * runtime.json changes around it: the tunnel block is written once the hostname is known (the app then trusts the host
 * and pins requests from cloudflared's address to it) and cleared before the container goes (the app answers the old
 * host with 503 meanwhile, never with another instance's content).
 */
final readonly class TunnelStack
{
    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private ConfigRollout $rollout,
        private QuickTunnelHostname $hostnames,
    ) {}

    /** The state with the tunnel: `phone_https` tunnel and the tunnel network's subnet (chosen once, then kept). */
    public function target(InstanceState $state): InstanceState
    {
        $subnet = InstanceSettings::of($state)->tunnelSubnet() ?? $this->subnet($state);

        return $state->withSettings(['phone_https' => 'tunnel', 'tunnel_subnet' => $subnet]);
    }

    public static function change(InstanceState $before, InstanceState $after): ConfigChange
    {
        return new ConfigChange('phone-https', $before, $after, InstanceSettings::of($before)->phoneHttps(), InstanceSettings::of($after)->phoneHttps());
    }

    /** @return array{pulled: list<string>} cloudflared's image when the engine lacks it (exit 3 when it cannot be pulled) */
    public function pull(InstanceState $after): array
    {
        return $this->rollout->pullMissing($after);
    }

    /**
     * Brings the stack to the tunnel state, reads the hostname, records the tunnel, verifies the app and starts the
     * workers. A failure throws; the caller reverts with `revertStart()`.
     *
     * @return array{hostname: string, expires_at: string, peer: string, admin: bool}
     */
    public function start(ConfigChange $change, bool $admin, ?ProgressReporter $progress = null): array
    {
        $this->rollout->apply($change);
        $tunnel = $this->record($change->after, $admin, $progress);
        $this->rollout->verify($change->after, $progress, 'start');
        $this->rollout->startWorkers($change->after);

        return $tunnel;
    }

    /** Back to the state before `start()`: no tunnel in runtime.json, the previous stack, the network gone. */
    public function revertStart(ConfigChange $change): void
    {
        new RuntimeWriter($this->directory)->writeTunnel($change->before, null);
        $this->rollout->revert($change, null);
        $this->removeNetwork($change->before);
    }

    /**
     * A new expiry for a living tunnel: the hostname is read again (cloudflared must still answer with the recorded
     * one; a restarted cloudflared would have a new one, which is recorded instead).
     *
     * @return array{hostname: string, expires_at: string, peer: string, admin: bool}
     */
    public function renew(InstanceState $state, ?ProgressReporter $progress = null): array
    {
        return $this->record($state, new RuntimeWriter($this->directory)->tunnel()['admin'], $progress);
    }

    /**
     * Removes the tunnel: the runtime block first, then the state without it, the stack without cloudflared (the app
     * leaves the tunnel network), the workers again, and the network. The subnet stays in the state for the next start.
     *
     * @return array{recreated: list<string>, added: list<string>, removed: list<string>}
     */
    public function remove(InstanceState $state): array
    {
        new RuntimeWriter($this->directory)->writeTunnel($state, null);
        $after = $state->withSetting('phone_https', null);
        $change = self::change($state, $after);
        $services = $this->rollout->apply($change);
        $this->rollout->startWorkers($after);
        $this->removeNetwork($after);

        return $services;
    }

    /** @return array{hostname: string, expires_at: string, peer: string, admin: bool} */
    private function record(InstanceState $state, bool $admin, ?ProgressReporter $progress): array
    {
        $hostname = $this->hostnames->read($state, ComposeProject::forInstance($state, $this->directory), $progress);
        $tunnel = [
            'hostname' => $hostname,
            'expires_at' => TunnelClock::expiry(CarbonImmutable::now('UTC'))->toIso8601ZuluString(),
            'peer' => TunnelNetwork::peerAddress((string) InstanceSettings::of($state)->tunnelSubnet()),
            'admin' => $admin,
        ];
        new RuntimeWriter($this->directory)->writeTunnel($state, $tunnel);

        return $tunnel;
    }

    /** A subnet a removed tunnel network left behind is reused; else a new /28 from the tunnel pool. */
    private function subnet(InstanceState $state): string
    {
        $left = $this->docker->inspectNetwork($state->project().'_'.TunnelNetwork::NAME);
        $reusable = $left instanceof NetworkInfo ? array_values(array_filter($left->subnets, InputGrammar::isPrivateSubnet(...))) : [];

        return $reusable[0] ?? TunnelNetwork::choose($state->uuid(), $this->docker->networkSubnets());
    }

    /** Best effort: Compose never removes networks on `up` (LEARNINGS 109); uninstall sweeps a leftover by its label. */
    private function removeNetwork(InstanceState $state): void
    {
        try {
            if ($this->docker->inspectNetwork($state->project().'_'.TunnelNetwork::NAME) instanceof NetworkInfo) {
                $this->docker->removeNetwork($state->project().'_'.TunnelNetwork::NAME);
            }
        } catch (Throwable) {
            // a container still attached keeps it; it is labelled, so uninstall removes it
        }
    }
}
