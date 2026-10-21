<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config\Appliers;

use App\Domain\Instance\Compose\EdgeNetwork;
use App\Domain\Instance\Config\ConfigChange;
use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Config\SettingApplier;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\NetworkInfo;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\State\InputGrammar;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * `fieldset config set domain <name>` (R16, R21, R32, ADR 0075, ADR 0077): switches local or proxy mode to domain mode,
 * or changes the domain of a domain-mode instance. The URL becomes `https://<name>`; the edge joins the stack with
 * its fixed address (the subnet chosen once in the preflight and kept, also across domain → local → domain), and
 * proxy mode's settings go. `--acme-email`, `--acme-ca`/`--acme-staging` and `--acme-ca-root-file` set the ACME
 * inputs like the install flags; without them the recorded ones stay. Preflight: DNS for the new domain and ports 80/443 (exit 3).
 * Verify: the edge holds a certificate from the configured CA, so a failed ACME order reverts the change.
 */
final readonly class DomainApplier implements SettingApplier
{
    private const array PROXY_SETTINGS = ['proxy' => null, 'proxy_network' => null, 'proxy_container' => null, 'trusted_proxies' => null];

    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private EdgePreflight $preflight,
    ) {}

    public function plan(InstanceState $state, ConfigRequest $request): ConfigChange
    {
        $domain = ConfigValues::domain($request->value);
        ConfigValues::assertNoPhoneHttps($state, 'domain mode');
        $acme = AcmeSettings::from($request, $this->directory);
        $from = InstanceSettings::of($state)->domain();
        $target = $acme->applyTo($state->withSetting('domain', $domain));
        if ($state->mode() === 'domain' && $from === $domain) {
            return new ConfigChange('domain', $state, $target, $from, $domain, $acme->caRoot);
        }
        $after = $target->withMode('domain')->withUrl('https://'.$domain)->withSettings(self::PROXY_SETTINGS);

        return new ConfigChange('domain', $state, $after, $from, $domain, $acme->caRoot);
    }

    /**
     * DNS and ports 80/443 for the new domain. The edge subnet is chosen here once (under the lock) and then kept; an
     * edge network an earlier, reverted attempt left behind keeps its subnet (Compose removes containers, not
     * networks, on `up`), so the next attempt reuses it instead of conflicting with it.
     */
    public function preflight(ConfigChange $change, HostFacts $facts): ConfigChange
    {
        $settings = InstanceSettings::of($change->after);
        if ($settings->edgeSubnet() === null) {
            $change = $change->withAfter($change->after->withSetting('edge_subnet', $this->subnet($change->after)));
        }
        $domain = (string) $settings->domain();
        foreach ($this->preflight->check($change->before, $domain, $facts) as $warning) {
            $change = $change->withWarning($warning['code'], $warning['message'], $warning['hint']);
        }

        return $change;
    }

    private function subnet(InstanceState $state): string
    {
        $left = $this->docker->inspectNetwork($state->project().'_'.EdgeNetwork::NAME);
        $reusable = $left instanceof NetworkInfo ? array_values(array_filter($left->subnets, InputGrammar::isPrivateSubnet(...))) : [];

        return $reusable[0] ?? EdgeNetwork::choose($state->uuid(), $this->docker->networkSubnets());
    }
}
