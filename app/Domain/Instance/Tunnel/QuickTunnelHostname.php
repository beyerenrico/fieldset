<?php

declare(strict_types=1);

namespace App\Domain\Instance\Tunnel;

use App\Domain\Instance\Compose\Services\TunnelService;
use App\Domain\Instance\Compose\TunnelNetwork;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\ProgressReporter;
use App\Domain\Instance\Runtime\RuntimeDocument;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Reads the quick tunnel's hostname from cloudflared's metrics server (KTD12, ADR 0057, ADR 0082): `GET /quicktunnel`
 * answers `{"hostname":"<name>.trycloudflare.com"}` once Cloudflare assigned one, an empty name before
 * (cloudflared metrics/metrics.go). The ops container is on no instance network, so a throwaway `curl` from the app
 * image asks on the tunnel network, at cloudflared's fixed address; attempts repeat until a name arrives, cloudflared
 * stops answering, or the time is up. The name must match the app's strict grammar (RuntimeDocument::TUNNEL_HOST), so
 * nothing else can become a trusted host.
 */
final readonly class QuickTunnelHostname
{
    public const string ROLE = 'tunnel-probe';

    public const string PATH = '/quicktunnel';

    public const int ATTEMPTS = 30;

    public const int DELAY = 2;

    private const float TIMEOUT = 60.0;

    public function __construct(private DockerGateway $docker) {}

    /** The longest read() takes. */
    public static function maxSeconds(): int
    {
        return self::ATTEMPTS * (self::DELAY + 15);
    }

    /** @throws InstanceException exit 4 `tunnel_unavailable` when no valid hostname arrives */
    public function read(InstanceState $state, ComposeProject $project, ?ProgressReporter $progress = null, string $phase = 'hostname'): string
    {
        $peer = TunnelNetwork::peerAddress((string) InstanceSettings::of($state)->tunnelSubnet());
        $answer = '';
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $progress?->event($phase, ProgressReporter::INFO, "Waiting for the quick tunnel's hostname (attempt {$attempt}/".self::ATTEMPTS.')', ['attempt' => $attempt, 'attempts' => self::ATTEMPTS]);
            $outcome = $this->docker->runContainer(new ContainerSpec(
                image: $state->digests()['app'],
                command: ['--fail', '--silent', '--show-error', '--max-time', '5', 'http://'.$peer.':'.TunnelService::METRICS_PORT.self::PATH],
                labels: Labels::forInstance($state->uuid(), self::ROLE),
                network: $project->name.'_'.TunnelNetwork::NAME,
                entrypoint: 'curl',
            ), null, self::TIMEOUT);
            $answer = trim($outcome->stdout."\n".$outcome->stderrTail(1));
            $hostname = $outcome->successful() ? self::hostname($outcome->stdout) : null;
            if ($hostname !== null) {
                return $hostname;
            }
            if ($attempt < self::ATTEMPTS) {
                Sleep::for(self::DELAY)->seconds();
            }
        }

        throw new InstanceException('The quick tunnel got no hostname from Cloudflare.', ExitCode::HealthCheckFailed, 'tunnel_unavailable', 'Quick tunnels need outbound internet access and have no uptime guarantee: check `fsa logs tunnel`, then run `fsa tunnel start` again.', true, [
            'answer' => mb_substr($answer, 0, 500), 'logs' => $this->logs($project),
        ]);
    }

    /** The hostname of a `/quicktunnel` answer when it passes the app's grammar, else null (empty while connecting). */
    public static function hostname(string $json): ?string
    {
        try {
            $decoded = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        $hostname = is_array($decoded) && is_string($decoded['hostname'] ?? null) ? strtolower(trim($decoded['hostname'])) : '';

        return preg_match(RuntimeDocument::TUNNEL_HOST, $hostname) === 1 ? $hostname : null;
    }

    private function logs(ComposeProject $project): string
    {
        try {
            return $this->docker->composeLogs($project, [TunnelService::NAME], null, 20);
        } catch (Throwable) {
            return '';
        }
    }
}
