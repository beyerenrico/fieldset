<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config\Appliers;

use App\Domain\Instance\Config\ConfigChange;
use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Config\SettingApplier;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * `fsa config set acme-email <address|none>` (R16, R32, ADR 0075, ADR 0077): the ACME account contact of domain mode
 * (shared with the CA; `none` removes it). The edge is recreated for its new Caddyfile and must still serve a
 * certificate. Local and proxy mode have no ACME account (exit 2).
 */
final readonly class AcmeEmailApplier implements SettingApplier
{
    public function plan(InstanceState $state, ConfigRequest $request): ConfigChange
    {
        ConfigValues::assertMode($state, ['domain'], 'acme-email', 'Only domain mode orders certificates: `fsa config set domain <name> --acme-email <address>` sets both.');
        $email = strtolower(trim($request->value)) === 'none' ? null : ConfigValues::email($request->value, 'acme-email');

        return new ConfigChange('acme-email', $state, $state->withSetting('acme_email', $email), InstanceSettings::of($state)->acmeEmail(), $email);
    }

    public function preflight(ConfigChange $change, HostFacts $facts): ConfigChange
    {
        return $change;
    }
}
