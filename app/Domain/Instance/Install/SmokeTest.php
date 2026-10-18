<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\Compose\EdgeNetwork;
use App\Domain\Instance\Compose\Services\EdgeService;
use App\Domain\Instance\Compose\Services\GotenbergService;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use Throwable;

/**
 * The install's smoke test (R11, ADR 0072). The ops container is not on the instance's networks and, on Docker
 * Desktop, cannot reach a port published on the host, so a throwaway `curl` container of the app image joins the
 * compose network and asks the app the way a browser on the published port would (Host: localhost:<port>,
 * which the app's trusted-host list accepts): `/up` and the admin login page (which needs the database and the
 * session table). A second one asks Gotenberg on the internal renderer network for its health. Both carry the
 * instance label. PDF render and test mail join with the capture-mode unit (U12).
 *
 * Domain mode (U8, ADR 0075) adds the edge, asked on its own network at its fixed address with the domain as SNI and
 * Host: first HTTPS until the edge holds a certificate (exit 4 `certificate_failed` with the edge's log when it gets
 * none in time; the certificate volume stays, so the retry continues the ACME account and order), then the HTTP→HTTPS
 * redirect. The HTTPS check skips chain verification: the edge has exactly one issuer, the configured ACME CA, and
 * never serves a self-signed fallback for the domain, so a completed handshake means a certificate from that CA
 * (the journeys verify the chain against Pebble's root).
 */
final readonly class SmokeTest
{
    public const string ROLE = 'smoke';

    public const array APP_PATHS = ['/up', '/admin/login'];

    private const float TIMEOUT = 240.0;

    /** HTTPS attempts against the edge, 5 s apart: ACME orders take seconds, a failed one is retried after a minute. */
    public const int CERTIFICATE_ATTEMPTS = 40;

    private const float EDGE_TIMEOUT = 420.0;

    public function __construct(private DockerGateway $docker) {}

    /** @return array<string, mixed> */
    public function run(InstanceState $state, ComposeProject $project): array
    {
        $port = InstanceSettings::of($state)->port();
        $this->curl($state, $project->name.'_default', "localhost:{$port}", array_map(static fn (string $path): string => "http://app:8080{$path}", self::APP_PATHS));
        $this->curl($state, $project->name.'_'.GotenbergService::NETWORK, null, ['http://'.GotenbergService::NAME.':3000/health']);
        $edge = $state->mode() === 'domain' ? $this->edge($state, $project) : [];

        return ['checked' => [...self::APP_PATHS, 'gotenberg /health', ...$edge]];
    }

    /** @return list<string> what was checked through the edge */
    private function edge(InstanceState $state, ComposeProject $project): array
    {
        $settings = InstanceSettings::of($state);
        $domain = (string) $settings->domain();
        $address = EdgeNetwork::edgeAddress((string) $settings->edgeSubnet());
        $network = $project->name.'_'.EdgeNetwork::NAME;
        $https = $this->edgeCurl($state, $network, [
            '--fail', '--insecure', '--max-time', '20', '--retry', (string) self::CERTIFICATE_ATTEMPTS, '--retry-delay', '5', '--retry-all-errors',
            '--resolve', "{$domain}:443:{$address}", '--output', '/dev/null', '--write-out', '%{http_code}', "https://{$domain}/up",
        ]);
        if (! $https->successful()) {
            throw new InstanceException(
                "The edge got no certificate for {$domain} from {$settings->acmeCa()}.",
                ExitCode::HealthCheckFailed,
                'certificate_failed',
                "Check that {$domain} points at this host and that ports 80 and 443 reach it from the internet, then run `fsa install` again with the same flags: it resumes at the smoke test and keeps the certificate storage. Repeated attempts count against the CA's rate limits; --acme-staging is for trials.",
                true,
                ['output' => trim($https->stdout."\n".$https->stderrTail(3)), 'logs' => $this->edgeLogs($project)],
            );
        }
        $http = $this->edgeCurl($state, $network, [
            '--max-time', '20', '--resolve', "{$domain}:80:{$address}", '--output', '/dev/null', '--write-out', '%{http_code} %{redirect_url}', "http://{$domain}/up",
        ]);
        if (trim($http->stdout) !== "308 https://{$domain}/up") {
            throw new InstanceException("The edge does not redirect http://{$domain} to HTTPS.", ExitCode::HealthCheckFailed, 'smoke_failed', 'Read `fsa logs edge`, fix the cause, then run `fsa install` again with the same flags: it resumes at the smoke test.', true, ['output' => trim($http->stdout."\n".$http->stderrTail(3))]);
        }

        return ["https://{$domain}/up", "http://{$domain} → https"];
    }

    /** @param  list<string>  $arguments */
    private function edgeCurl(InstanceState $state, string $network, array $arguments): ProcessOutcome
    {
        return $this->docker->runContainer(new ContainerSpec(
            image: $state->digests()['app'],
            command: ['--silent', '--show-error', ...$arguments],
            labels: Labels::forInstance($state->uuid(), self::ROLE),
            network: $network,
            entrypoint: 'curl',
        ), null, self::EDGE_TIMEOUT);
    }

    private function edgeLogs(ComposeProject $project): string
    {
        try {
            return $this->docker->composeLogs($project, [EdgeService::NAME], null, 20);
        } catch (Throwable) {
            return '';
        }
    }

    /** @param  list<string>  $urls */
    private function curl(InstanceState $state, string $network, ?string $host, array $urls): void
    {
        $outputs = array_merge(...array_map(static fn (): array => ['--output', '/dev/null'], $urls));
        $outcome = $this->docker->runContainer(new ContainerSpec(
            image: $state->digests()['app'],
            command: [
                '--fail', '--fail-early', '--silent', '--show-error', '--max-time', '30', '--retry', '10', '--retry-delay', '3',
                '--retry-connrefused', ...($host === null ? [] : ['--header', "Host: {$host}"]), ...$outputs,
                '--write-out', '%{http_code} %{url_effective}\n', ...$urls,
            ],
            labels: Labels::forInstance($state->uuid(), self::ROLE),
            network: $network,
            entrypoint: 'curl',
        ), null, self::TIMEOUT);
        if (! $outcome->successful()) {
            throw new InstanceException(
                'The smoke test failed for '.implode(', ', $urls).'.',
                ExitCode::HealthCheckFailed,
                'smoke_failed',
                'Read `fsa logs app`, fix the cause, then run `fsa install` again with the same flags: it resumes at the smoke test. `fsa uninstall` removes the half-finished instance.',
                true,
                ['output' => trim($outcome->stdout."\n".$outcome->stderrTail(5))],
            );
        }
    }
}
