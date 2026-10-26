<?php

declare(strict_types=1);

namespace App\Domain\Instance\Windows;

/**
 * Reads FIELDSET_WINDOWS_FACTS, the document the shim builds from one powershell.exe call (2.1.1 U6, KTD8, KTD9), into
 * WindowsFacts. One `key=value` per line; CR line ends and a UTF-8 byte order mark are tolerated, lines of other shapes
 * (PowerShell's error text) and unknown keys are ignored:
 *
 * - `scope=full|addresses|unavailable` (written by the shim) and `end=1` (the script's last line): without both, or
 *   with `unavailable`, every fact is unknown
 * - `ipv4=<address> <network profile> <adapter alias / description>`, then `checked=addresses`
 * - `networking=nat|mirrored`, `binding=<Docker Desktop port binding>`, `hyperv_enabled=True|False|NotConfigured`,
 *   `hyperv_inbound=Allow|Block|NotConfigured`: once each, else unknown
 * - `rule=<port> <profile flags>` then `checked=rules`; `hyperv_rule=<port>` then `checked=hyperv_rules`;
 *   `portproxy=<listen address|*> <port> <connect address> <port>` then `checked=portproxy`;
 *   `listen=<port> <address> <pid> <process name>` then `checked=listen`
 *
 * A list is known only when its `checked` line came (the script's section ran to its end), and only when every line of
 * it is well-formed: one malformed line makes the whole list unknown, never a partial one. LAN candidates are private
 * IPv4 addresses (10/8, 172.16/12, 192.168/16) of adapters that are no virtual switch, VPN or tunnel (VIRTUAL_ADAPTER);
 * the adapter's name is used for that match only, then dropped. Process names only pick a ListenerOwner.
 */
final class WindowsFactsParser
{
    /** Adapters that are no LAN, matched on "alias / description": Hyper-V and WSL switches, VPNs, tunnels, other VMs. */
    public const string VIRTUAL_ADAPTER = '/vEthernet|Hyper-V|\bWSL\b|\bVPN\b|\bTAP\b|WireGuard|Wintun|Tailscale|ZeroTier|AnyConnect|OpenVPN|VirtualBox|VMware|Loopback|Docker/i';

    /** A dotted-quad IPv4 address, octets 0–255 without leading zeros. */
    public const string IPV4 = '/^(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)$/D';

    /** Larger documents are not what the shim's script prints: unavailable. */
    private const int MAX_BYTES = 65536;

    private const string HOST_NAME = '/^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,252})$/D';

    public static function parse(?string $document): WindowsFacts
    {
        if ($document === null || trim($document) === '') {
            return WindowsFacts::skipped();
        }
        $lines = self::lines($document);
        $scope = FactsScope::tryFrom(self::single($lines, 'scope') ?? '');
        if (strlen($document) > self::MAX_BYTES || ! in_array($scope, [FactsScope::Addresses, FactsScope::Full], true) || self::single($lines, 'end') !== '1') {
            return WindowsFacts::unavailable();
        }
        $addresses = self::section($lines, 'addresses', 'ipv4', self::address(...));
        if ($scope === FactsScope::Addresses) {
            return new WindowsFacts($scope, self::candidates($addresses));
        }

        return new WindowsFacts(
            scope: $scope,
            addresses: self::candidates($addresses),
            networking: WslNetworking::tryFrom(self::single($lines, 'networking') ?? '') ?? WslNetworking::Unknown,
            portBinding: PortBinding::tryFrom(self::single($lines, 'binding') ?? '') ?? PortBinding::Unknown,
            hostRules: self::section($lines, 'rules', 'rule', self::rule(...)),
            hyperVFirewall: HyperVFirewall::tryFrom(self::single($lines, 'hyperv_enabled') ?? '') ?? HyperVFirewall::Unknown,
            hyperVInbound: InboundAction::tryFrom(self::single($lines, 'hyperv_inbound') ?? '') ?? InboundAction::Unknown,
            hyperVRules: self::section($lines, 'hyperv_rules', 'hyperv_rule', self::port(...)),
            portProxies: self::section($lines, 'portproxy', 'portproxy', self::portProxy(...)),
            listeners: self::section($lines, 'listen', 'listen', self::listener(...)),
        );
    }

    /** @return array<string, list<string>> values by key, in document order */
    private static function lines(string $document): array
    {
        $lines = [];
        $text = str_starts_with($document, "\u{FEFF}") ? substr($document, 3) : $document;
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            if (preg_match('/^([a-z][a-z0-9_]*)=(.*)$/D', $line, $match) === 1) {
                $lines[$match[1]][] = trim($match[2], " \t");
            }
        }

        return $lines;
    }

    /** @param  array<string, list<string>>  $lines */
    private static function single(array $lines, string $key): ?string
    {
        $values = array_values(array_unique($lines[$key] ?? []));

        return count($values) === 1 ? $values[0] : null;
    }

    /**
     * The section's entries when it ran to its end and every line parsed, else null.
     *
     * @template T
     *
     * @param  array<string, list<string>>  $lines
     * @param  callable(string): (T|null)  $parse
     * @return list<T>|null
     */
    private static function section(array $lines, string $name, string $key, callable $parse): ?array
    {
        if (! in_array($name, $lines['checked'] ?? [], true)) {
            return null;
        }
        $entries = [];
        foreach ($lines[$key] ?? [] as $value) {
            $entry = $parse($value);
            if ($entry === null) {
                return null;
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * Private addresses of physical adapters, without duplicates; null stays unknown.
     *
     * @param  list<array{WindowsAddress, string}>|null  $addresses
     * @return list<WindowsAddress>|null
     */
    private static function candidates(?array $addresses): ?array
    {
        if ($addresses === null) {
            return null;
        }
        $candidates = [];
        foreach ($addresses as [$address, $adapter]) {
            if (self::isPrivate($address->address) && preg_match(self::VIRTUAL_ADAPTER, $adapter) !== 1) {
                $candidates[$address->address] ??= $address;
            }
        }

        return array_values($candidates);
    }

    /** @return array{WindowsAddress, string}|null the address with the adapter's name, which goes no further than candidates() */
    private static function address(string $value): ?array
    {
        [$address, $profile, $adapter] = array_pad(explode(' ', $value, 3), 3, '');
        if (preg_match(self::IPV4, $address) !== 1) {
            return null;
        }

        return [new WindowsAddress($address, NetworkProfile::tryFrom($profile) ?? NetworkProfile::Unknown), $adapter];
    }

    /** @return array{port: int, profiles: int}|null */
    private static function rule(string $value): ?array
    {
        if (preg_match('/^(\d{1,5}) (\d{1,10})$/D', $value, $match) !== 1 || self::port($match[1]) === null || (int) $match[2] > 0x7FFFFFFF) {
            return null;
        }

        return ['port' => (int) $match[1], 'profiles' => (int) $match[2]];
    }

    private static function port(string $value): ?int
    {
        return preg_match('/^[1-9]\d{0,4}$/D', $value) === 1 && (int) $value <= 65535 ? (int) $value : null;
    }

    private static function portProxy(string $value): ?PortProxyEntry
    {
        if (preg_match('/^(\S+) (\d+) (\S+) (\d+)$/D', $value, $match) !== 1) {
            return null;
        }
        [, $listen, $listenPort, $connect, $connectPort] = $match;
        $listenOk = $listen === '*' || preg_match(self::IPV4, $listen) === 1;
        $connectOk = preg_match(self::IPV4, $connect) === 1 || preg_match(self::HOST_NAME, $connect) === 1;
        if (! $listenOk || ! $connectOk || self::port($listenPort) === null || self::port($connectPort) === null) {
            return null;
        }

        return new PortProxyEntry($listen === '*' ? null : $listen, (int) $listenPort, preg_match(self::IPV4, $connect) === 1 ? $connect : null, (int) $connectPort);
    }

    private static function listener(string $value): ?WindowsListener
    {
        if (preg_match('/^(\d+) (\S+) (\d{1,10})(?: (.*))?$/D', $value, $match) !== 1) {
            return null;
        }
        $port = self::port($match[1]);
        if ($port === null || filter_var($match[2], FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return new WindowsListener($port, $match[2], (int) $match[3], ListenerOwner::fromProcess((int) $match[3], $match[4] ?? ''));
    }

    private static function isPrivate(string $address): bool
    {
        [$a, $b] = array_map(intval(...), explode('.', $address));

        return $a === 10 || ($a === 172 && $b >= 16 && $b <= 31) || ($a === 192 && $b === 168);
    }
}
