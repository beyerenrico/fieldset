<?php

declare(strict_types=1);

namespace App\Domain\Instance\Windows;

use App\Domain\Instance\Doctor\Checks\EdgePorts;
use App\Domain\Instance\LocalCa\LanAddress;

/**
 * The Windows phone-access hint table (2.1.1 KTD10, plan "Windows phone-access hints"): the first matching row wins, so
 * exactly one command set is printed.
 *
 * 1. The network of the advertised address is Public: switch it to Private.
 * 2. Docker Desktop binds published ports to Windows' loopback only: change that Desktop setting.
 * 3. A fact the setup needs is unknown (interop off, a section that failed): "unknown", the manual steps and the
 *    `lan-address` setting; never a pass (R30).
 * 4. Docker Desktop without an inbound firewall rule for 80 and 443 on the Private profile: the host firewall rule.
 * 5. Docker Engine behind WSL's NAT with a missing or stale portproxy (its target is none of the distro's current
 *    addresses, FIELDSET_WSL_ADDRESSES) or a missing host rule: portproxy delete/add lines and the host firewall rule.
 * 6. Docker Engine with mirrored networking and no Hyper-V firewall rule (while that firewall filters): the Hyper-V rule.
 *
 * Docker Desktop's port binding is only printed by the fact-finding script when the setting exists, so its absence
 * counts as the default binding rather than as unknown.
 */
final class PhoneAccessRows
{
    /** @param  list<string>  $wslAddresses  the distro's own addresses (the portproxy target under NAT) */
    public static function evaluate(WindowsFacts $facts, WslSetup $setup, string $address, array $wslAddresses, string $uuid): PhoneAccessHint
    {
        $profile = $facts->profile($address);
        if ($profile === NetworkProfile::Public) {
            return new PhoneAccessHint(PhoneAccessRow::PublicProfile, "Windows treats the network of {$address} as Public, so its firewall blocks phones.", [WindowsCommands::privateProfile($address)],
                'Set the network\'s profile type to Private (Windows Settings > Network & internet, the network\'s properties), or:');
        }
        if ($setup === WslSetup::DockerDesktop && $facts->portBinding === PortBinding::LocalOnly) {
            return new PhoneAccessHint(PhoneAccessRow::DesktopLocalOnly, "Docker Desktop publishes ports on Windows' loopback only (its port binding setting is local-only), so phones cannot reach {$address}.", [],
                'Change Docker Desktop\'s port binding setting from local-only to the default port binding, then run `fieldset doctor` again.');
        }
        $target = self::target($wslAddresses);
        $unknown = self::unknownFacts($facts, $setup, $profile, $target);
        if ($unknown !== []) {
            return self::unknown($facts, $setup, $address, $target, $uuid, $unknown);
        }

        return match ($setup) {
            WslSetup::DockerDesktop => self::desktop($facts, $address, $uuid),
            WslSetup::EngineNat => self::nat($facts, $address, $wslAddresses, (string) $target, $uuid),
            default => self::mirrored($facts, $address, $uuid),
        };
    }

    /**
     * @param  list<string>  $unknown
     */
    private static function unknown(WindowsFacts $facts, WslSetup $setup, string $address, ?string $target, string $uuid, array $unknown): PhoneAccessHint
    {
        $why = $facts->scope === FactsScope::Unavailable ? 'WSL interop is off or powershell.exe did not answer' : 'Windows did not report '.implode(', ', $unknown);
        $forward = match ($setup) {
            WslSetup::DockerDesktop => ', Docker Desktop\'s port binding is not local-only',
            WslSetup::EngineNat => ", a portproxy forwards them from {$address} to WSL",
            WslSetup::EngineMirrored => ', a Hyper-V firewall rule lets them reach WSL',
            WslSetup::Unknown => ', a portproxy (WSL with NAT networking) or a Hyper-V firewall rule (mirrored networking) forwards them to WSL',
        };
        $commands = match ($setup) {
            WslSetup::DockerDesktop => [WindowsCommands::hostRule($uuid)],
            WslSetup::EngineNat => [...($target === null ? [] : self::adds($address, $target, array_values(EdgePorts::PORTS))), WindowsCommands::hostRule($uuid)],
            WslSetup::EngineMirrored => [WindowsCommands::hyperVRule($uuid)],
            WslSetup::Unknown => [],
        };
        $advice = "Check on Windows that the network of {$address} is Private, that the firewall allows inbound TCP 80 and 443 from the local subnet{$forward}, and that no other program uses those ports. "
            .'If Windows\' LAN address is not '.$address.', set it with `fieldset config set lan-address <address> --yes`.'.($commands === [] ? '' : ' What is missing is set up by:');

        return new PhoneAccessHint(PhoneAccessRow::FactsUnknown, "Whether Windows lets phones reach {$address} is unknown: {$why}.", $commands, $advice, $unknown);
    }

    /** @return list<string> the facts the setup's row needs that Windows did not report */
    private static function unknownFacts(WindowsFacts $facts, WslSetup $setup, NetworkProfile $profile, ?string $target): array
    {
        $rules = in_array(Presence::Unknown, array_map($facts->hostRule(...), EdgePorts::PORTS), true);
        $hyperV = in_array(Presence::Unknown, array_map($facts->hyperVRule(...), EdgePorts::PORTS), true);
        $missing = [
            'network profile' => $profile === NetworkProfile::Unknown,
            'networking mode' => $setup === WslSetup::Unknown,
            'firewall rules' => $rules && in_array($setup, [WslSetup::DockerDesktop, WslSetup::EngineNat], true),
            'portproxy entries' => $setup === WslSetup::EngineNat && $facts->portProxies === null,
            'WSL address' => $setup === WslSetup::EngineNat && $target === null,
            'Hyper-V firewall rules' => $hyperV && $setup === WslSetup::EngineMirrored,
            'listeners' => $facts->listeners === null,
        ];

        return array_keys(array_filter($missing));
    }

    private static function desktop(WindowsFacts $facts, string $address, string $uuid): PhoneAccessHint
    {
        $missing = self::missingHostRules($facts);
        if ($missing !== []) {
            return new PhoneAccessHint(PhoneAccessRow::HostRule, ucfirst(self::noHostRule($missing)).", so it blocks phones that open https://{$address}.", [WindowsCommands::hostRule($uuid)]);
        }

        return new PhoneAccessHint(null, "Windows lets phones reach {$address}: the network is Private and a firewall rule allows inbound TCP 80 and 443 to Docker Desktop.");
    }

    /** @param  list<string>  $wslAddresses */
    private static function nat(WindowsFacts $facts, string $address, array $wslAddresses, string $target, string $uuid): PhoneAccessHint
    {
        $deletes = $adds = $problems = [];
        foreach (EdgePorts::PORTS as $port) {
            $entries = array_values(array_filter($facts->portProxies ?? [], static fn (PortProxyEntry $e): bool => $e->listenPort === $port && in_array($e->listenAddress, [null, $address], true)));
            $current = array_any($entries, static fn (PortProxyEntry $e): bool => in_array($e->connectAddress, $wslAddresses, true) && $e->connectPort === $port);
            foreach (array_filter($entries, static fn (PortProxyEntry $e): bool => ! in_array($e->connectAddress, $wslAddresses, true) || $e->connectPort !== $port) as $stale) {
                $deletes[] = WindowsCommands::portProxyDelete($stale->listenAddress, $port);
                $problems[] = "the portproxy for port {$port} points at ".($stale->connectAddress ?? 'a host name').":{$stale->connectPort}, not at WSL's {$target}:{$port}";
            }
            $adds = $current ? $adds : [...$adds, ...self::adds($address, $target, [$port])];
            if ($entries === []) {
                $problems[] = "no portproxy forwards port {$port} from {$address} to WSL";
            }
        }
        $missing = self::missingHostRules($facts);
        if ($missing !== []) {
            $problems[] = self::noHostRule($missing);
        }
        if ($problems === []) {
            return new PhoneAccessHint(null, "Windows forwards TCP 80 and 443 from {$address} to WSL ({$target}), and its firewall allows them on the Private network.");
        }
        $rule = $missing === [] ? [] : [WindowsCommands::hostRule($uuid)];

        return new PhoneAccessHint(PhoneAccessRow::PortProxy, 'Phones cannot reach the edge behind WSL\'s NAT: '.implode('; ', $problems).'.', [...array_values(array_unique($deletes)), ...$adds, ...$rule]);
    }

    private static function mirrored(WindowsFacts $facts, string $address, string $uuid): PhoneAccessHint
    {
        $missing = array_values(array_filter(EdgePorts::PORTS, static fn (int $port): bool => $facts->hyperVRule($port) === Presence::Missing));
        $open = $facts->hyperVFirewall === HyperVFirewall::Disabled || $facts->hyperVInbound === InboundAction::Allow;
        if ($missing === [] || $open) {
            return new PhoneAccessHint(null, "Windows lets phones reach {$address}: ".($missing === [] ? 'a Hyper-V firewall rule allows inbound TCP 80 and 443 to WSL.' : 'the Hyper-V firewall lets inbound traffic reach WSL.'));
        }
        $ports = implode(' and ', $missing);

        return new PhoneAccessHint(PhoneAccessRow::HyperVRule, "The Hyper-V firewall has no inbound rule for TCP {$ports} to WSL (mirrored networking), so it blocks phones that open https://{$address}.", [WindowsCommands::hyperVRule($uuid)]);
    }

    /** @return list<int> edge ports without an inbound allow rule that covers the Private profile */
    private static function missingHostRules(WindowsFacts $facts): array
    {
        return array_values(array_filter(EdgePorts::PORTS, static fn (int $port): bool => $facts->hostRule($port) === Presence::Missing));
    }

    /** @param  list<int>  $ports */
    private static function noHostRule(array $ports): string
    {
        return 'the Windows firewall has no inbound rule for TCP '.implode(' and ', $ports).' on the Private profile';
    }

    /**
     * @param  list<int>  $ports
     * @return list<string>
     */
    private static function adds(string $address, string $target, array $ports): array
    {
        return array_map(static fn (int $port): string => WindowsCommands::portProxyAdd($address, $port, $target), $ports);
    }

    /** @param  list<string>  $wslAddresses */
    private static function target(array $wslAddresses): ?string
    {
        return array_values(array_filter($wslAddresses, static fn (string $ip): bool => LanAddress::usable($ip) && preg_match(WindowsFactsParser::IPV4, $ip) === 1))[0] ?? null;
    }
}
