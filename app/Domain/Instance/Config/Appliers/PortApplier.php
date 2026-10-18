<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config\Appliers;

use App\Domain\Instance\Config\ConfigChange;
use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Config\SettingApplier;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * `fsa config set port <n>` (R15, R32, ADR 0077): the app's loopback port in local mode (the URL becomes
 * `http://localhost:<n>`, so it is a URL change) and in proxy mode's `port` setup (the proxy's target; the URL stays).
 * Domain mode and shared proxy networks publish no app port (exit 2). The port must be free (exit 3); only the app
 * is recreated with the new binding (and, in local mode, queue and scheduler for the new APP_URL).
 */
final readonly class PortApplier implements SettingApplier
{
    public function __construct(private LoopbackPorts $ports) {}

    public function plan(InstanceState $state, ConfigRequest $request): ConfigChange
    {
        $port = ConfigValues::port($request->value);
        $settings = InstanceSettings::of($state);
        if (! $settings->publishesAppPort()) {
            throw new InvalidInput("`port` does not apply in {$state->mode()} mode: the app publishes no loopback port there.", 'not_in_this_mode', $state->mode() === 'domain'
                ? 'Domain mode publishes 80 and 443 through the edge; `fsa config set mode local` returns to a loopback port.'
                : 'On a shared proxy network the proxy reaches the app directly.');
        }
        if ($settings->runsCatcher() && $port === $settings->mailUiPort()) {
            throw new InvalidInput("Port {$port} is the mail catcher UI's port.", 'port_conflict', 'Choose another port.');
        }

        return new ConfigChange('port', $state, $state->withSetting('port', $port), (string) $settings->port(), (string) $port);
    }

    public function preflight(ConfigChange $change, HostFacts $facts): ConfigChange
    {
        $port = InstanceSettings::of($change->after)->port();
        if (! $this->ports->isFree($change->before, $port)) {
            throw new InstanceException("Port {$port} is in use on 127.0.0.1.", ExitCode::PreflightFailed, 'port_in_use', 'Free the port or choose another one, then run the command again; nothing was changed.', false, ['taken' => [$port]]);
        }

        return $change;
    }
}
