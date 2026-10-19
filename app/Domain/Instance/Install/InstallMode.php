<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\Compose\EdgeNetwork;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\LocalCa\LanAddress;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceState;
use Closure;

/**
 * Domain mode in an install (E7, ADR 0053, ADR 0075), decided in the doctor phase and journaled with it under `edge`,
 * so a resumed install keeps it: the domain, the ACME inputs and the edge network's subnet (chosen once, never
 * re-chosen while the state has one). The doctor phase's `mode` and `url` say domain and `https://<domain>`; proxy
 * mode has its own `proxy` entry (ProxySetup). The state phase applies both.
 *
 * Local-CA mode (`--phone-https local-ca`, R19, ADR 0082) is local mode with the edge: journaled under `phone` with the
 * LAN address (the flag, else the launcher's best candidate) and the edge subnet; the URL becomes https://<address>.
 * `--phone-https tunnel` is journaled there as `{phone_https: tunnel}` only, so a resume with the same flags matches;
 * the state does not take it (the tunnel starts after the install, InstallCommand).
 */
final class InstallMode
{
    /**
     * The doctor phase's mode: proxy (ProxySetup resolved one), domain (--domain) or local.
     *
     * @param  array<string, string|null>|null  $proxy  ProxySetup::resolve()
     */
    public static function mode(InstallRequest $request, ?array $proxy): string
    {
        return match (true) {
            $proxy !== null => 'proxy',
            $request->domain() !== null => 'domain',
            default => 'local',
        };
    }

    /**
     * @param  Closure(): list<string>  $takenSubnets  subnets of the engine's networks, asked only when one is needed
     * @return array<string, string>|null the domain-mode settings, null outside domain mode
     */
    public static function edge(InstallRequest $request, ?InstanceState $state, string $uuid, Closure $takenSubnets): ?array
    {
        $domain = $request->domain();
        if ($domain === null) {
            return null;
        }
        $subnet = $state?->setting('edge_subnet');

        return [
            'domain' => $domain,
            'edge_subnet' => is_string($subnet) ? $subnet : EdgeNetwork::choose($uuid, $takenSubnets()),
            ...$request->acme(),
        ];
    }

    /**
     * Local-CA mode's settings, null without `--phone-https local-ca`; exit 2 when no LAN address is known.
     *
     * @param  Closure(): list<string>  $takenSubnets
     * @return array{phone_https: string, lan_address: string, edge_subnet: string}|null
     */
    public static function localCa(InstallRequest $request, ?InstanceState $state, string $uuid, HostFacts $facts, Closure $takenSubnets): ?array
    {
        if ($request->phoneHttps() !== 'local-ca') {
            return null;
        }
        $address = $request->lanAddress() ?? LanAddress::best($facts)
            ?? throw new InvalidInput('No LAN address of this host is known for the local CA.', 'lan_address_required', 'Pass --lan-address with the IPv4 address of this machine in your LAN, such as 192.168.1.20.');
        $subnet = $state?->setting('edge_subnet');

        return ['phone_https' => 'local-ca', 'lan_address' => $address, 'edge_subnet' => is_string($subnet) ? $subnet : EdgeNetwork::choose($uuid, $takenSubnets())];
    }

    /** @param  array<string, mixed>  $preflight  the doctor phase's data */
    public static function apply(InstanceState $state, array $preflight): InstanceState
    {
        $phone = $preflight['phone'] ?? null;
        if (is_array($phone) && is_string($phone['lan_address'] ?? null)) {
            /** @var array<string, string> $phone */
            $state = $state->withUrl('https://'.$phone['lan_address'])->withSettings($phone);
        }
        $edge = $preflight['edge'] ?? null;
        if (! is_array($edge) || ! is_string($edge['domain'] ?? null)) {
            return $state;
        }
        /** @var array<string, string> $edge */

        return $state->withMode('domain')->withUrl('https://'.$edge['domain'])->withSettings($edge);
    }

    /**
     * What the doctor phase applied, keyed like InstallRequest, for a resume's flag check: an absent key was not
     * applied yet, a null one was applied as "not set".
     *
     * @param  array<string, mixed>  $doctor  the doctor phase's data
     * @return array<string, string|null>
     */
    public static function applied(array $doctor): array
    {
        $edge = is_array($doctor['edge'] ?? null) ? $doctor['edge'] : [];
        $phone = is_array($doctor['phone'] ?? null) ? $doctor['phone'] : [];
        $keys = ['domain', ...InstallRequest::ACME_FLAGS];
        $applied = array_map(static fn (string $key): ?string => is_string($edge[$key] ?? null) ? $edge[$key] : null, array_combine($keys, $keys));

        return [...$applied, 'phone_https' => is_string($phone['phone_https'] ?? null) ? $phone['phone_https'] : null, 'lan_address' => is_string($phone['lan_address'] ?? null) ? $phone['lan_address'] : null];
    }
}
