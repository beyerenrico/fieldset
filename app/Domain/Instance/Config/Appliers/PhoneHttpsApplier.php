<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config\Appliers;

use App\Domain\Instance\Config\ConfigChange;
use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Config\SettingApplier;
use App\Domain\Instance\Config\SettingHooks;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\LocalCa\LanAddress;
use App\Domain\Instance\LocalCa\LocalCaDeletion;
use App\Domain\Instance\LocalCa\LocalCaInfo;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * `fsa config set phone-https local-ca|off` (§7, R19, R21, R61, ADR 0082) on a local install.
 *
 * - `local-ca`: the edge joins the stack with Caddy's internal CA for the LAN address (the recorded one while it is
 *   still the host's, else the launcher's best candidate; `config set lan-address` picks another) and publishes 80 and
 *   443 there; the URL becomes https://<address> (exit 6 without --yes). Preflight: free ports there, no account on the
 *   demo password, the edge subnet. Verify (smoke test): the root in the runtime directory for the trust page, HTTPS
 *   verified against it without SNI, port 80 serving only the trust page. The result names the trust page and the
 *   root's fingerprint.
 * - `off`: the edge goes, the URL is the loopback one again, and once that runs the CA (keys included) is deleted from
 *   the certificate volume; a later `local-ca` creates a new one, which phones must trust anew. A deletion that failed
 *   stays pending in the state (LocalCaDeletion): `off` again retries it, `local-ca` deletes it before its edge starts.
 *
 * The quick tunnel has its own command (`fsa tunnel`); domain and proxy mode already serve HTTPS (R61: exit 2).
 */
final readonly class PhoneHttpsApplier implements SettingApplier, SettingHooks
{
    public const string KEY = 'phone-https';

    public function __construct(
        private InstanceDirectory $directory,
        private HostFacts $facts,
        private LocalCaPreflight $preflight,
        private LocalCaDeletion $deletion,
    ) {}

    public function plan(InstanceState $state, ConfigRequest $request): ConfigChange
    {
        $value = strtolower(trim($request->value));
        $settings = InstanceSettings::of($state);
        match (true) {
            $value === 'tunnel' => throw new InvalidInput('The quick tunnel has its own command.', 'use_tunnel_command', 'Run `fsa tunnel start` (and `fsa tunnel stop`).'),
            ! in_array($value, ['local-ca', 'off'], true) => throw new InvalidInput('Invalid value for phone-https.', 'invalid_value', 'Use local-ca or off; the quick tunnel is `fsa tunnel start`.'),
            $state->mode() !== 'local' => throw new InvalidInput("Phone HTTPS belongs to local mode; this instance runs in {$state->mode()} mode, which serves HTTPS already (R61).", 'invalid_combination', 'Phones open '.$settings->instanceUrl().'.'),
            $settings->tunnel() => throw new InvalidInput('The local CA and the quick tunnel exclude each other (R61).', 'invalid_combination', 'Stop the tunnel first: `fsa tunnel stop`.'),
            default => null,
        };
        $from = $settings->phoneHttps() ?? 'off';
        if ($value === $from) { // `off` again retries a CA deletion that failed (a change of the pending setting)
            return new ConfigChange(self::KEY, $state, $value === 'off' ? $state->withSetting(LocalCaDeletion::SETTING, null) : $state, $from, $value);
        }
        if ($value === 'off') {
            return new ConfigChange(self::KEY, $state, $state->withSetting('phone_https', null)->withUrl(null), $from, $value);
        }
        $address = $this->address($settings);

        return new ConfigChange(self::KEY, $state, $state->withSettings(['phone_https' => 'local-ca', 'lan_address' => $address, LocalCaDeletion::SETTING => null])->withUrl('https://'.$address), $from, $value);
    }

    public function preflight(ConfigChange $change, HostFacts $facts): ConfigChange
    {
        if (! InstanceSettings::of($change->after)->localCa()) {
            return $change;
        }
        $checked = $change->withAfter($this->preflight->check($change->before, $change->after, $facts));
        $this->deletion->beforeNewCa($change->before);

        return $checked;
    }

    public function verifySeconds(): int
    {
        return 0;
    }

    /** The smoke test already checked the local-CA edge (LocalCaEdge). */
    public function verify(ConfigChange $change, ConfigRequest $request, ComposeProject $project): array
    {
        return [];
    }

    public function completed(ConfigChange $change, ConfigRequest $request, ComposeProject $project): array
    {
        $after = InstanceSettings::of($change->after);
        if ($after->localCa()) {
            $warnings = array_map(static fn (array $w): array => ['code' => $w[0], 'message' => $w[1], 'hint' => $w[2]], LocalCaInfo::warnings((string) $after->lanAddress()));

            return ['data' => ['phone_https' => LocalCaInfo::data($change->after, $this->directory, $this->facts)], 'warnings' => $warnings];
        }
        if (InstanceSettings::of($change->before)->localCa() || LocalCaDeletion::pending($change->before)) {
            return $this->deletion->delete($change->before);
        }

        return ['data' => [], 'warnings' => []];
    }

    /** The recorded address while it is still the host's (or nothing is known), else the best candidate. */
    private function address(InstanceSettings $settings): string
    {
        $recorded = $settings->lanAddress();
        if ($recorded !== null && LanAddress::usable($recorded) && LanAddress::isCurrent($recorded, $this->facts) !== false) {
            return $recorded;
        }

        return LanAddress::best($this->facts)
            ?? throw new InvalidInput('No LAN address of this host is known for the local CA.', 'lan_address_required', 'Set it first: `fsa config set lan-address <IPv4 address of this machine>`, then `fsa config set phone-https local-ca --yes`.');
    }
}
