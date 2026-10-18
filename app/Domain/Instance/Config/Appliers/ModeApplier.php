<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config\Appliers;

use App\Domain\Instance\Config\ConfigChange;
use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Config\SettingApplier;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * `fsa config set mode local` (R32, ADR 0077): from domain or proxy mode back to `http://localhost:<port>`. The edge
 * and the proxy labels go (Compose removes the edge container; its certificate volume stays until uninstall), the
 * domain and proxy settings are cleared, and the app and the catcher UI publish loopback ports again: the ones the
 * instance had, or the next free ones when those are taken now. The edge subnet and the ACME inputs stay, so a later
 * `config set domain` reuses them. The other directions need more than one value: `config set domain <name>` enters
 * domain mode; proxy mode is chosen at install (exit 2 with that hint).
 */
final readonly class ModeApplier implements SettingApplier
{
    public function __construct(private LoopbackPorts $ports) {}

    public function plan(InstanceState $state, ConfigRequest $request): ConfigChange
    {
        $mode = strtolower(trim($request->value));
        match ($mode) {
            'local' => null,
            'domain' => throw new InvalidInput('Domain mode needs the domain.', 'use_another_key', 'Run `fsa config set domain <name>` (with --acme-email or --acme-staging as needed).'),
            'proxy' => throw new InvalidInput('Proxy mode needs the proxy, its network and the public URL, which only `fsa install` takes in this version.', 'mode_not_settable', 'Keep the current mode, or reinstall with --behind-proxy traefik|caddy|port --url <origin> (back up first).'),
            default => throw new InvalidInput('Invalid value for mode.', 'invalid_value', 'Use local; enter domain mode with `fsa config set domain <name>`.'),
        };
        if ($state->mode() === 'local') {
            return new ConfigChange('mode', $state, $state, 'local', 'local');
        }
        $settings = InstanceSettings::of($state);
        $after = $state->withMode('local')->withUrl(null)->withSettings([
            'domain' => null, 'proxy' => null, 'proxy_network' => null, 'proxy_container' => null, 'trusted_proxies' => null,
            'port' => $settings->port(), 'mail_ui_port' => $settings->mailUiPort(),
        ]);

        return new ConfigChange('mode', $state, $after, $state->mode(), 'local');
    }

    /** The loopback ports: the remembered ones when free (or already this instance's), else the next free ones. */
    public function preflight(ConfigChange $change, HostFacts $facts): ConfigChange
    {
        $wanted = InstanceSettings::of($change->after);
        $app = $this->ports->choose($change->before, $wanted->port(), InstanceSettings::DEFAULT_PORT, []);
        $mail = $wanted->capturesMail()
            ? $this->ports->choose($change->before, $wanted->mailUiPort(), InstanceSettings::DEFAULT_MAIL_UI_PORT, [$app])
            : $wanted->mailUiPort();
        if ($app === $wanted->port() && $mail === $wanted->mailUiPort()) {
            return $change;
        }
        $refined = $change->withAfter($change->after->withSettings(['port' => $app, 'mail_ui_port' => $mail]));

        return $app === $wanted->port() ? $refined : $refined->withWarning('port_changed', "Port {$wanted->port()} is in use, so the app now listens on 127.0.0.1:{$app}.");
    }
}
