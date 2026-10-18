<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config\Appliers;

use App\Domain\Instance\Config\ConfigChange;
use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Config\SettingApplier;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * `fsa config set acme-ca <production|staging|URL>` (R16, R32, ADR 0075, ADR 0077): the ACME directory of domain
 * mode, e.g. from Let's Encrypt staging (a trial) to production. `--acme-ca-root-file` adds the root of a private
 * directory's HTTPS; a Let's Encrypt directory drops it. The edge is recreated and must get a certificate from the new
 * directory, else the change reverts. Local and proxy mode order no certificates (exit 2).
 */
final readonly class AcmeCaApplier implements SettingApplier
{
    public function __construct(private InstanceDirectory $directory) {}

    public function plan(InstanceState $state, ConfigRequest $request): ConfigChange
    {
        ConfigValues::assertMode($state, ['domain'], 'acme-ca', 'Only domain mode orders certificates: `fsa config set domain <name> --acme-ca <URL>` sets both.');
        $acme = AcmeSettings::from(new ConfigRequest('acme-ca', $request->value, acmeCa: $request->value, acmeCaRoot: $request->acmeCaRoot), $this->directory);
        $after = $acme->applyTo($state);

        return new ConfigChange('acme-ca', $state, $after, InstanceSettings::of($state)->acmeCa(), InstanceSettings::of($after)->acmeCa(), $acme->caRoot);
    }

    public function preflight(ConfigChange $change, HostFacts $facts): ConfigChange
    {
        return $change;
    }
}
