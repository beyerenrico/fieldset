<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config;

use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Install\SmokeTest;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\ProgressReporter;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * Whether a changed (or reverted) instance works (ADR 0077). `compose up --wait` already waited for every
 * healthcheck; this adds the install's smoke test (app `/up` and `/admin/login` on the internal network, Gotenberg,
 * and in domain mode the edge: a certificate from the configured ACME CA for the domain, then the HTTP → HTTPS
 * redirect, ADR 0075) and a request with the new URL's host, which only passes once the app trusts it (runtime
 * hosts, ADR 0074). Like the smoke test it runs `curl` from the app image in a throwaway container on the
 * instance's own network: the ops container cannot reach published ports on every engine.
 */
final readonly class ConfigVerifier
{
    public const string ROLE = 'config-verify';

    /** Process timeout of the proxy-mode host check (curl with its retries). */
    public const int HOST_CHECK_SECONDS = 180;

    public function __construct(private DockerGateway $docker, private SmokeTest $smoke) {}

    /** @return array{checked: list<string>} */
    public function verify(InstanceState $state, ComposeProject $project, ?ProgressReporter $progress = null, string $phase = 'verify'): array
    {
        $checked = $this->smoke->run($state, $project, $progress, $phase)['checked'] ?? [];
        $host = $this->urlHost($state);
        if ($host !== null) {
            $this->checkHost($state, $project, $host);
            $checked[] = "Host: {$host}";
        }

        return ['checked' => array_values(array_map(strval(...), is_array($checked) ? $checked : []))];
    }

    /** The host (with a non-default port) of the instance URL when the smoke test does not already use it. */
    private function urlHost(InstanceState $state): ?string
    {
        if ($state->mode() !== 'proxy') {
            return null; // local: the smoke test sends localhost:<port>; domain: the edge check uses the domain
        }
        $parts = parse_url(InstanceSettings::of($state)->instanceUrl());
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;

        return is_string($host) ? $host.(isset($parts['port']) ? ':'.$parts['port'] : '') : null;
    }

    private function checkHost(InstanceState $state, ComposeProject $project, string $host): void
    {
        $outcome = $this->docker->runContainer(new ContainerSpec(
            image: $state->digests()['app'],
            command: ['--fail', '--silent', '--show-error', '--max-time', '20', '--retry', '5', '--retry-delay', '2', '--retry-all-errors',
                '--header', "Host: {$host}", '--output', '/dev/null', '--write-out', '%{http_code}', 'http://app:8080/up'],
            labels: Labels::forInstance($state->uuid(), self::ROLE),
            network: $project->name.'_default',
            entrypoint: 'curl',
        ), null, (float) self::HOST_CHECK_SECONDS);
        if (! $outcome->successful()) {
            throw new InstanceException(
                "The app does not answer for {$host}.",
                ExitCode::HealthCheckFailed,
                'url_check_failed',
                'Read `fieldset logs app` for the cause.',
                true,
                ['output' => trim($outcome->stdout."\n".$outcome->stderrTail(3))],
            );
        }
    }
}
