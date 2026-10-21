<?php

declare(strict_types=1);

namespace App\Domain\Instance\LocalCa;

use App\Domain\Instance\Compose\EdgeNetwork;
use App\Domain\Instance\Compose\Services\EdgeService;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Install\SmokeTest;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Runtime\PhoneTrustRoute;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\RuntimeWriter;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * The local-CA edge's part of the smoke test (install) and of `config set` verification (R19, ADR 0082), once the edge
 * runs:
 *
 * 1. the CA's public root is copied from the certificate volume into the runtime directory (LocalCaStore), where the
 *    app's trust page serves it and computes the fingerprint fieldset also prints;
 * 2. HTTPS to the LAN address, verified against that root, without SNI (curl sends none for an IP): the certificate is
 *    issued for the IP and `default_sni` picks it;
 * 3. port 80: the trust page answers 200 and anything else 308 to HTTPS.
 *
 * Like the domain-mode check it runs `curl` from the app image in a throwaway container on the edge network, aimed at
 * the edge's fixed address with `--connect-to`, so it needs no route to the host's LAN address.
 */
final readonly class LocalCaEdge
{
    /** Attempts to read the root while Caddy creates its CA at start. */
    private const int ROOT_ATTEMPTS = 15;

    private const int ROOT_DELAY = 2;

    private const string CA_MOUNT = '/run/fieldset-local-ca';

    private const float TIMEOUT = 120.0;

    public function __construct(private DockerGateway $docker, private LocalCaStore $store, private InstanceDirectory $directory) {}

    /** The longest check() takes: the root wait and two curl containers. */
    public static function maxSeconds(): int
    {
        return self::ROOT_ATTEMPTS * (self::ROOT_DELAY + 60) + 2 * (int) self::TIMEOUT;
    }

    /** @return array{checked: list<string>, root_sha256: string} */
    public function check(InstanceState $state, ComposeProject $project): array
    {
        $root = $this->publishRoot($state, $project);
        $settings = InstanceSettings::of($state);
        $address = (string) $settings->lanAddress();
        $edge = EdgeNetwork::edgeAddress((string) $settings->edgeSubnet());
        $network = $project->name.'_'.EdgeNetwork::NAME;
        $https = $this->curl($state, $network, [
            '--fail', '--cacert', self::CA_MOUNT.'/'.basename(RuntimeWriter::LOCAL_CA_ROOT), '--connect-to', "{$address}:443:{$edge}:443",
            '--retry', '10', '--retry-delay', '2', '--retry-all-errors', '--max-time', '15', '--output', '/dev/null', '--write-out', '%{http_code}', "https://{$address}/up",
        ]);
        if (! $https->successful() || trim($https->stdout) !== '200') {
            throw $this->failure("The edge does not serve https://{$address} with a certificate of the local CA.", $https, $project);
        }
        $http = $this->curl($state, $network, [
            '--connect-to', "{$address}:80:{$edge}:80", '--max-time', '20', '--output', '/dev/null', '--output', '/dev/null',
            '--write-out', '%{http_code} %{redirect_url}\n', "http://{$address}/up", "http://{$address}".PhoneTrustRoute::PATH,
        ]);
        $lines = array_map(trim(...), explode("\n", trim($http->stdout)));
        if ($lines !== ["308 https://{$address}/up", '200']) {
            throw $this->failure("Port 80 on {$address} does not serve only the trust page (308 to HTTPS otherwise).", $http, $project);
        }

        return [
            'checked' => ["https://{$address}/up (local CA, no SNI)", "http://{$address} → https", "http://{$address}".PhoneTrustRoute::PATH],
            'root_sha256' => LocalCaStore::fingerprint($root),
        ];
    }

    /** Copies the CA's root into the runtime directory once Caddy created it; exit 4 when it never appears. */
    public function publishRoot(InstanceState $state, ComposeProject $project): string
    {
        for ($attempt = 1; $attempt <= self::ROOT_ATTEMPTS; $attempt++) {
            $root = $this->store->root($state);
            if ($root !== null) {
                new RuntimeWriter($this->directory)->writeLocalCaRoot($root);

                return $root;
            }
            if ($attempt < self::ROOT_ATTEMPTS) {
                Sleep::for(self::ROOT_DELAY)->seconds();
            }
        }

        throw new InstanceException('The edge created no local CA.', ExitCode::HealthCheckFailed, 'local_ca_missing', 'Read `fieldset logs edge`, fix the cause, then run the command again.', true, ['logs' => $this->logs($project)]);
    }

    /** @param  list<string>  $arguments */
    private function curl(InstanceState $state, string $network, array $arguments): ProcessOutcome
    {
        return $this->docker->runContainer(new ContainerSpec(
            image: $state->digests()['app'],
            command: ['--silent', '--show-error', ...$arguments],
            labels: Labels::forInstance($state->uuid(), SmokeTest::ROLE),
            volumes: [$this->directory->path(dirname(RuntimeWriter::LOCAL_CA_ROOT)).':'.self::CA_MOUNT.':ro'],
            network: $network,
            entrypoint: 'curl',
        ), null, self::TIMEOUT);
    }

    private function failure(string $message, ProcessOutcome $outcome, ComposeProject $project): InstanceException
    {
        return new InstanceException($message, ExitCode::HealthCheckFailed, 'smoke_failed', 'Read `fieldset logs edge` and `fieldset logs app`, fix the cause, then run the command again.', true, [
            'output' => trim($outcome->stdout."\n".$outcome->stderrTail(3)), 'logs' => $this->logs($project),
        ]);
    }

    private function logs(ComposeProject $project): string
    {
        try {
            return $this->docker->composeLogs($project, [EdgeService::NAME], null, 20);
        } catch (Throwable) {
            return '';
        }
    }
}
