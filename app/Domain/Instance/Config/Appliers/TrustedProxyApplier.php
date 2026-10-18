<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config\Appliers;

use App\Domain\Instance\Config\ConfigChange;
use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Config\SettingApplier;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\NetworkEndpoint;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Install\ProxySetup;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InputGrammar;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * `fsa config set trusted-proxy <container|address|auto>` (R17, R32, KTD10, ADR 0076, ADR 0077): the one address a
 * proxy-mode app accepts X-Forwarded-* from, resolved through the Docker engine as at install, never by DNS name.
 *
 * - traefik, caddy: the named container (name, ID or its address on the shared network), or `auto`: the recorded
 *   proxy container if it is still attached, else the only container running the setup's proxy image. This follows
 *   a recreated proxy that got a new address. Not found or ambiguous is exit 3.
 * - port: only `auto`, which reads the gateway of the instance's default network again.
 *
 * An address equal to the trusted one is no change without asking the engine; names and `auto` are resolved
 * (read-only) before deciding. Only the app roles are recreated, for TRUSTED_PROXIES.
 */
final readonly class TrustedProxyApplier implements SettingApplier
{
    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private ProxySetup $proxy,
    ) {}

    public function plan(InstanceState $state, ConfigRequest $request): ConfigChange
    {
        ConfigValues::assertMode($state, ['proxy'], 'trusted-proxy', 'Domain mode trusts its edge and local mode no proxy; nothing to set.');
        $settings = InstanceSettings::of($state);
        $value = trim($request->value);
        $from = implode(',', $settings->trustedProxies());
        if ($value !== 'auto' && ! InputGrammar::isIp($value) && ! InputGrammar::isDockerName($value)) {
            throw new InvalidInput('Invalid value for trusted-proxy.', 'invalid_value', 'Use the proxy container\'s name or ID, its address on the shared network, or auto.');
        }
        if ($settings->proxy() === 'port') {
            return $this->gateway($state, $value, $from);
        }
        if (InputGrammar::isIp($value) && [$value] === $settings->trustedProxies()) {
            return new ConfigChange('trusted-proxy', $state, $state, $from, $value);
        }
        $network = (string) $settings->proxyNetwork();
        $info = $this->docker->inspectNetwork($network)
            ?? throw new InstanceException("The Docker network {$network} does not exist.", ExitCode::PreflightFailed, 'proxy_network_missing', "Create it and attach the proxy (docker network create {$network}), then run the command again.");
        $recorded = $settings->proxyContainer();
        $given = $value !== 'auto' ? $value : ($recorded !== null && $info->endpoint($recorded) instanceof NetworkEndpoint ? $recorded : null);
        $endpoint = $this->proxy->endpointOn($info, (string) $settings->proxy(), $given);
        $address = $endpoint->address()
            ?? throw new InstanceException("The container {$endpoint->name} has no address on {$network}.", ExitCode::PreflightFailed, 'proxy_not_found', 'Check that the proxy runs and is attached to the network.');

        return new ConfigChange('trusted-proxy', $state, $state->withSettings(['trusted_proxies' => $address, 'proxy_container' => $endpoint->name]), $from, $address);
    }

    public function preflight(ConfigChange $change, HostFacts $facts): ConfigChange
    {
        return $change;
    }

    /** The port setup trusts the gateway its loopback connections arrive from (ADR 0076). */
    private function gateway(InstanceState $state, string $value, string $from): ConfigChange
    {
        if ($value !== 'auto') {
            throw new InvalidInput('In the port setup the trusted proxy is the gateway of the instance\'s network.', 'invalid_value', 'Pass auto to read the gateway again.');
        }

        try {
            $gateway = $this->proxy->gateway(ComposeProject::forInstance($state, $this->directory));
        } catch (InstanceException $e) {
            throw new InstanceException($e->getMessage(), ExitCode::PreflightFailed, $e->errorCode, 'Start the instance first (its network must exist), then run the command again.', true, previous: $e);
        }

        return new ConfigChange('trusted-proxy', $state, $state->withSetting('trusted_proxies', $gateway), $from, $gateway);
    }
}
