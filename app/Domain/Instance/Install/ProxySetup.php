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
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * Which single address the app trusts in proxy mode (R17, KTD10, ADR 0076). Never a range: a neighbouring
 * container on a shared proxy network could otherwise forge X-Forwarded-For, -Proto and -Host.
 *
 * - traefik, caddy: the proxy container's address on the shared network, read from the Docker engine at install
 *   (the doctor phase, nothing changes). The container is the one named by `--trusted-proxy` (name, ID or its
 *   address there), else the only container on that network running a Traefik or caddy-docker-proxy image. Not
 *   by DNS name: any neighbour may claim the same network alias. The name is kept: `fsa doctor` and `fsa status`
 *   re-resolve it and warn when its address moved (`drift()`, ADR 0078), and `config set trusted-proxy auto`
 *   follows it.
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
        $proxy = $this->endpointOn($info, $kind, $request->trustedProxy());
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

    /**
     * The proxy's endpoint on the shared network: the container named by `$given` (name, ID or its address there),
     * else the only container running the setup's proxy image (exit 3 when none or several, with `data.candidates`).
     * `fsa config set trusted-proxy` re-resolves through the same rule; `$forConfig` makes the hint name that command
     * instead of the install flag.
     */
    public function endpointOn(NetworkInfo $network, string $kind, ?string $given, bool $forConfig = false): NetworkEndpoint
    {
        $retry = $forConfig ? 'Run `fsa config set trusted-proxy <name>`' : 'Pass --trusted-proxy';
        if ($given !== null) {
            $endpoint = InputGrammar::isIp($given) ? $network->endpointAt($given) : $network->endpoint($given);

            return $endpoint ?? throw new InstanceException("No container {$given} is attached to {$network->name}.", ExitCode::PreflightFailed, 'trusted_proxy_not_found', "{$retry} with the proxy's container name or its address on {$network->name}: one of data.candidates (`docker network inspect {$network->name}`).", false, ['candidates' => $this->candidates($network->endpoints)]);
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
            "{$retry} with the proxy's container name or its address on that network: one of data.candidates.",
            false,
            ['candidates' => $this->candidates($candidates === [] ? $network->endpoints : $candidates)],
        );
    }

    /**
     * Whether `$given` names the proxy this instance trusts: its recorded container name, its trusted address, or an
     * ID prefix (12+ hex characters) of the container with that name on the shared network (one read-only inspect,
     * only for an ID). A repeated install compares --trusted-proxy through this (ADR 0078).
     */
    public function isTrusted(InstanceState $state, string $given): bool
    {
        $settings = InstanceSettings::of($state);
        if ($given === $settings->proxyContainer() || in_array($given, $settings->trustedProxies(), true)) {
            return true;
        }
        $network = $settings->proxyNetwork();
        if ($network === null || $settings->proxyContainer() === null || strlen($given) < 12 || ! ctype_xdigit($given)) {
            return false;
        }

        return $this->docker->inspectNetwork($network)?->endpoint($given)?->name === $settings->proxyContainer();
    }

    /**
     * Where the trusted proxy is now, read-only (`fsa doctor`, `fsa status`; ADR 0078): traefik and caddy re-resolve the
     * recorded container on the shared network, the port setup re-reads its network's gateway. Null outside proxy
     * mode or without a recorded proxy.
     *
     * @return array{setup: string, network: string|null, container: string|null, trusted: list<string>, current: string|null, moved: bool}|null
     */
    public function drift(InstanceState $state, ComposeProject $project): ?array
    {
        $settings = InstanceSettings::of($state);
        $setup = $settings->proxy();
        if ($setup === null || $settings->trustedProxies() === []) {
            return null;
        }
        $container = $settings->proxyContainer();
        $current = match (true) {
            $settings->joinsProxyNetwork() => $container === null ? null : $this->docker->inspectNetwork((string) $settings->proxyNetwork())?->endpoint($container)?->address(),
            $setup === 'port' => $this->docker->inspectNetwork($project->name.'_default')?->gateway(),
            default => null,
        };

        return [
            'setup' => $setup, 'network' => $settings->proxyNetwork(), 'container' => $container,
            'trusted' => $settings->trustedProxies(), 'current' => $current, 'moved' => ! in_array($current, $settings->trustedProxies(), true),
        ];
    }

    /**
     * @param  list<NetworkEndpoint>  $endpoints
     * @return list<array{name: string, address: string|null}>
     */
    private function candidates(array $endpoints): array
    {
        return array_map(static fn (NetworkEndpoint $endpoint): array => ['name' => $endpoint->name, 'address' => $endpoint->address()], $endpoints);
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
