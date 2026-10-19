<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config\Appliers;

use App\Domain\Instance\Config\ConfigChange;
use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Config\SettingApplier;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\LocalCa\LanAddress;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * `fsa config set lan-address <IPv4>` (R19, ADR 0082): the LAN address of the local CA. With the local CA on it
 * re-issues the certificate for the new address (the Caddyfile changes, so the edge is recreated, and Caddy's CA signs a
 * certificate for it; the CA itself stays, so trusting phones keep trusting it), moves 80/443 there and makes
 * https://<address> the URL (exit 6 without --yes); `fsa doctor` and `fsa status` offer it when the host's address
 * changed. With the local CA off it only records the address `phone-https local-ca` will use.
 */
final readonly class LanAddressApplier implements SettingApplier
{
    public const string KEY = 'lan-address';

    public function __construct(private LocalCaPreflight $preflight) {}

    public function plan(InstanceState $state, ConfigRequest $request): ConfigChange
    {
        $address = LanAddress::validate($request->value, 'lan-address', 'invalid_value');
        if ($state->mode() !== 'local') {
            throw new InvalidInput("`lan-address` belongs to the local CA of local mode; this instance runs in {$state->mode()} mode.", 'not_in_this_mode', 'Phones open '.InstanceSettings::of($state)->instanceUrl().' over HTTPS already.');
        }
        $settings = InstanceSettings::of($state);
        $from = $settings->lanAddress();
        if ($from === $address) {
            return new ConfigChange(self::KEY, $state, $state, $from, $address);
        }
        $after = $state->withSetting('lan_address', $address);

        return new ConfigChange(self::KEY, $state, $settings->localCa() ? $after->withUrl('https://'.$address) : $after, $from, $address);
    }

    public function preflight(ConfigChange $change, HostFacts $facts): ConfigChange
    {
        return InstanceSettings::of($change->after)->localCa()
            ? $change->withAfter($this->preflight->check($change->before, $change->after, $facts))
            : $change;
    }
}
