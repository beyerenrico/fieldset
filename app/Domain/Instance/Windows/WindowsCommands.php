<?php

declare(strict_types=1);

namespace App\Domain\Instance\Windows;

use App\Domain\Instance\Doctor\Checks\EdgePorts;
use InvalidArgumentException;

/**
 * The PowerShell and netsh lines the doctor prints for phone access on WSL 2, and uninstall for undoing them (2.1.1 KTD9,
 * R12, R32). Each line is built only from validated values (IPv4 addresses by pattern, ports as integers) and fixed rule
 * names that carry the instance's short ID (`fieldset-<first 8 hex of the UUID>-https`, `…-https-wsl` for the Hyper-V
 * rule); a value that does not validate throws instead of reaching a line. No free-form Windows string ever does.
 * fieldset never runs them: the user does, in PowerShell as administrator.
 */
final class WindowsCommands
{
    public const string HEADER = 'Run in PowerShell as administrator:';

    /** WSL's VM creator ID for the Hyper-V firewall; distribution/shim.sh's fact-finding script has the same ID as `$vm`. */
    public const string WSL_VM_CREATOR = '{40E0AC32-46A5-438A-A0B2-2B479E8F2E90}';

    /** The Windows firewall rule for the edge ports: inbound TCP 80 and 443, Private profile, local subnet only. */
    public static function hostRule(string $uuid): string
    {
        $name = self::ruleName($uuid);

        return "New-NetFirewallRule -Name {$name} -DisplayName {$name} -Direction Inbound -Action Allow -Protocol TCP -LocalPort ".self::ports().' -Profile Private -RemoteAddress LocalSubnet';
    }

    /** The Hyper-V firewall rule that lets inbound TCP 80 and 443 reach WSL's VM (mirrored networking). */
    public static function hyperVRule(string $uuid): string
    {
        $name = self::hyperVRuleName($uuid);

        return "New-NetFirewallHyperVRule -Name {$name} -DisplayName {$name} -Direction Inbound -VMCreatorId '".self::WSL_VM_CREATOR."' -Protocol TCP -LocalPorts ".self::ports();
    }

    /** Switches the network the address is on to the Private profile. */
    public static function privateProfile(string $address): string
    {
        return 'Set-NetConnectionProfile -InterfaceIndex (Get-NetIPAddress -IPAddress '.self::ipv4($address).').InterfaceIndex -NetworkCategory Private';
    }

    public static function portProxyAdd(string $listen, int $port, string $connect): string
    {
        $port = self::port($port);

        return 'netsh interface portproxy add v4tov4 listenaddress='.self::ipv4($listen)." listenport={$port} connectaddress=".self::ipv4($connect)." connectport={$port}";
    }

    /** @param  string|null  $listen  null: the entry listens on every address (`*`) */
    public static function portProxyDelete(?string $listen, int $port): string
    {
        return 'netsh interface portproxy delete v4tov4 '.($listen === null ? '' : 'listenaddress='.self::ipv4($listen).' ').'listenport='.self::port($port);
    }

    /**
     * What removes the rules the doctor suggests for this setup (R32): every kind when the setup is unknown.
     *
     * @return list<string>
     */
    public static function removal(string $uuid, WslSetup $setup, string $address): array
    {
        $host = 'Remove-NetFirewallRule -Name '.self::ruleName($uuid);
        $hyperV = 'Remove-NetFirewallHyperVRule -Name '.self::hyperVRuleName($uuid);
        $proxies = array_map(static fn (int $port): string => self::portProxyDelete($address, $port), array_values(EdgePorts::PORTS));

        return match ($setup) {
            WslSetup::DockerDesktop => [$host],
            WslSetup::EngineNat => [...$proxies, $host],
            WslSetup::EngineMirrored => [$hyperV],
            WslSetup::Unknown => [...$proxies, $host, $hyperV],
        };
    }

    /**
     * The copy-ready hint: the header, one indented line per command, then what to do next.
     *
     * @param  list<string>  $commands
     */
    public static function hint(array $commands, ?string $then = 'Then run `fieldset doctor` again.'): string
    {
        $lines = [self::HEADER, ...array_map(static fn (string $command): string => '    '.$command, $commands)];

        return implode("\n", $then === null ? $lines : [...$lines, $then]);
    }

    public static function ruleName(string $uuid): string
    {
        return 'fieldset-'.self::shortId($uuid).'-https';
    }

    public static function hyperVRuleName(string $uuid): string
    {
        return self::ruleName($uuid).'-wsl';
    }

    private static function shortId(string $uuid): string
    {
        if (preg_match('/^([0-9a-f]{8})-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', strtolower($uuid), $match) !== 1) {
            throw new InvalidArgumentException('An instance UUID is needed for a Windows rule name.');
        }

        return $match[1];
    }

    private static function ports(): string
    {
        return implode(',', array_map(self::port(...), array_values(EdgePorts::PORTS)));
    }

    private static function ipv4(string $address): string
    {
        if (! WindowsFactsParser::isIpv4($address)) {
            throw new InvalidArgumentException('Only an IPv4 address goes into a Windows command.');
        }

        return $address;
    }

    private static function port(int $port): int
    {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Only a TCP port goes into a Windows command.');
        }

        return $port;
    }
}
