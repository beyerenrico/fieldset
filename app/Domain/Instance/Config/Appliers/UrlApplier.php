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
 * `fsa config set url <origin>` (R17, R21, R32, ADR 0076, ADR 0077): the public URL a proxy-mode instance's proxy
 * serves. Local mode's URL follows its port and domain mode's its domain (exit 2 there, with the key to use). The new
 * origin goes into APP_URL, the runtime file and the Traefik or caddy-docker-proxy labels; the verify step asks the
 * app with the new host.
 */
final readonly class UrlApplier implements SettingApplier
{
    public function plan(InstanceState $state, ConfigRequest $request): ConfigChange
    {
        $url = ConfigValues::origin($request->value);
        ConfigValues::assertMode($state, ['proxy'], 'url', $state->mode() === 'domain'
            ? 'Domain mode serves https://<domain>: use `fsa config set domain <name>`.'
            : 'Local mode serves http://localhost:<port>: use `fsa config set port <n>`.');

        return new ConfigChange('url', $state, $state->withUrl($url), InstanceSettings::of($state)->instanceUrl(), $url);
    }

    public function preflight(ConfigChange $change, HostFacts $facts): ConfigChange
    {
        return $change;
    }
}
