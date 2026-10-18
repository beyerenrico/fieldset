<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\NetworkEndpoint;
use App\Domain\Instance\Docker\NetworkInfo;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InputGrammar;
use App\Domain\Instance\State\InstanceState;

/**
 * Which single address the app trusts in proxy mode (R17, KTD10, ADR 0076). Never a range: a neighbouring
 * container on a shared proxy network could otherwise forge X-Forwarded-For, -Proto and -Host.
 *
 * - traefik, caddy: the proxy container's address on the shared network, read from the Docker engine at install
 *   (the doctor phase, nothing changes). The container is the one named by `--trusted-proxy` (name, ID or its
 *   address there), else the only container on that network running a Traefik or caddy-docker-proxy image. Not
 *   by DNS name: any neighbour may claim the same network alias. The name is kept, so `fsa doctor` and
 *   `config set` can notice and follow a recreated proxy.
 * - port: the gateway of the instance's own default network, where connections to a loopback-published port
 *   arrive from (verified on Docker Desktop and the Linux bridge with docker-proxy). It exists once Compose
 *   created the network, so the `up` phase reads it.
 */
final readonly class ProxySetup
{
    /** Image names (last path segment, without tag or digest) each setup recognises on its own. */
    public const array IMAGES = ['traefik' => ['traefik'], 'caddy' => ['caddy-docker-proxy']];

    public function __construct(private DockerGateway $docker) {}

    /**
     * @return array{kind: string, network: string|null, container: string|null, address: string|null}|null null in local mode
     *
     * @throws InstanceException exit 3 when the network or the proxy cannot be found
     */
    public function resolve(InstallRequest $request): ?array
    {
        $kind = $request->proxy();
        if ($kind === null) {
            return null;
        }
        $network = $request->proxyNetwork();
        if (! in_array($kind, InstallRequest::NETWORK_PROXIES, true) || $network === null) {
            return ['kind' => $kind, 'network' => null, 'container' => null, 'address' => null];
        }
        $info = $this->docker->inspectNetwork($network)
            ?? throw new InstanceException("The Docker network {$network} does not exist.", ExitCode::PreflightFailed, 'proxy_network_missing', "Pass the network your proxy is attached to (`docker network ls`), or create it and attach the proxy: docker network create {$network}.");
        $proxy = $this->proxyOn($info, $kind, $request->trustedProxy());
        $address = $proxy->address()
            ?? throw new InstanceException("The container {$proxy->name} has no address on {$network}.", ExitCode::PreflightFailed, 'proxy_not_found', 'Check that the proxy runs and is attached to the network.');

        return ['kind' => $kind, 'network' => $network, 'container' => $proxy->name, 'address' => $address];
    }

    /**
     * The state's proxy-mode settings from the doctor phase's data.
     *
     * @param  array<string, mixed>  $preflight
     */
    public static function apply(InstanceState $state, array $preflight): InstanceState
    {
        $proxy = $preflight['proxy'] ?? null;
        if (! is_array($proxy) || ! is_string($preflight['url'] ?? null)) {
            return $state;
        }

        return $state->withMode('proxy')->withUrl($preflight['url'])->withSettings([
            'proxy' => $proxy['kind'] ?? null,
            'proxy_network' => $proxy['network'] ?? null,
            'proxy_container' => $proxy['container'] ?? null,
            'trusted_proxies' => $proxy['address'] ?? null,
        ]);
    }

    /** The `port` setup's trusted address: the gateway of the project's default network (exit 4 when unknown). */
    public function gateway(ComposeProject $project): string
    {
        $network = $project->name.'_default';

        return $this->docker->inspectNetwork($network)?->gateway()
            ?? throw new InstanceException("The network {$network} reports no gateway.", ExitCode::HealthCheckFailed, 'gateway_unknown', 'Run `fsa install` again with the same flags: it resumes here.', true);
    }

    private function proxyOn(NetworkInfo $network, string $kind, ?string $given): NetworkEndpoint
    {
        if ($given !== null) {
            $endpoint = InputGrammar::isIp($given) ? $network->endpointAt($given) : $network->endpoint($given);

            return $endpoint ?? throw new InstanceException("No container {$given} is attached to {$network->name}.", ExitCode::PreflightFailed, 'trusted_proxy_not_found', "Pass the proxy's container name or its address on {$network->name} (`docker network inspect {$network->name}`).");
        }
        $candidates = array_values(array_filter($network->endpoints, fn (NetworkEndpoint $endpoint): bool => $this->runsProxyImage($endpoint, $kind)));
        if (count($candidates) === 1) {
            return $candidates[0];
        }
        $names = implode(', ', array_map(static fn (NetworkEndpoint $endpoint): string => $endpoint->name, $candidates));

        throw new InstanceException(
            $candidates === [] ? "No {$kind} container is attached to {$network->name}." : "Several {$kind} containers are attached to {$network->name}: {$names}.",
            ExitCode::PreflightFailed,
            $candidates === [] ? 'proxy_not_found' : 'proxy_ambiguous',
            'Pass --trusted-proxy with the proxy\'s container name or its address on that network.',
        );
    }

    private function runsProxyImage(NetworkEndpoint $endpoint, string $kind): bool
    {
        $image = (string) $this->docker->inspectContainer($endpoint->containerId)?->image;
        $name = explode('@', $image, 2)[0];
        $name = substr($name, (int) strrpos('/'.$name, '/'));
        $name = explode(':', $name, 2)[0];

        return in_array($name, self::IMAGES[$kind] ?? [], true);
    }
}
