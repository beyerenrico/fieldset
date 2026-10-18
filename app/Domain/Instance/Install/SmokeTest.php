<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\Compose\Services\GotenbergService;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * The install's smoke test (R11, ADR 0072). The ops container is not on the instance's networks and, on Docker
 * Desktop, cannot reach a port published on the host, so a throwaway `curl` container of the app image joins the
 * compose network and asks the app the way a browser on the published port would (Host: localhost:<port>,
 * which the app's trusted-host list accepts): `/up` and the admin login page (which needs the database and the
 * session table). A second one asks Gotenberg on the internal renderer network for its health. Both carry the
 * instance label. PDF render and test mail join with the capture-mode unit (U12).
 */
final readonly class SmokeTest
{
    public const string ROLE = 'smoke';

    public const array APP_PATHS = ['/up', '/admin/login'];

    private const float TIMEOUT = 240.0;

    public function __construct(private DockerGateway $docker) {}

    /** @return array<string, mixed> */
    public function run(InstanceState $state, ComposeProject $project): array
    {
        $port = InstanceSettings::of($state)->port();
        $this->curl($state, $project->name.'_default', "localhost:{$port}", array_map(static fn (string $path): string => "http://app:8080{$path}", self::APP_PATHS));
        $this->curl($state, $project->name.'_'.GotenbergService::NETWORK, null, ['http://'.GotenbergService::NAME.':3000/health']);

        return ['checked' => [...self::APP_PATHS, 'gotenberg /health']];
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
