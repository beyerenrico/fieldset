<?php

declare(strict_types=1);

namespace App\Domain\Instance\LocalCa;

use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Output\InvalidInput;

/**
 * The LAN address of local-CA mode (R19, ADR 0082): the edge binds 80/443 there and Caddy's internal CA issues the
 * certificate for it. The ops container sees only its bridge, so the candidates come from the launcher
 * (FIELDSET_LAN_ADDRESSES, HostFacts). Only IPv4 unicast addresses qualify: phones reach an IPv4 LAN address, and an IPv6
 * link-local one would need a zone.
 *
 * The best candidate is the first private address (10/8, 172.16/12, 192.168/16), else the first other usable IPv4
 * address; `--lan-address` picks another one.
 */
final class LanAddress
{
    /** @throws InvalidInput exit 2 for anything but a usable IPv4 unicast address */
    public static function validate(string $value, string $flag = '--lan-address', string $code = 'invalid_flag'): string
    {
        $address = trim($value);
        if (! self::usable($address)) {
            throw new InvalidInput("Invalid value for {$flag}.", $code, 'Use the IPv4 address of this machine in your LAN, such as 192.168.1.20 (not 127.0.0.1 and not an IPv6 address).');
        }

        return $address;
    }

    /** The best candidate the launcher reported, or null when it reported none. */
    public static function best(HostFacts $facts): ?string
    {
        $usable = array_values(array_filter($facts->lanAddresses, self::usable(...)));
        $private = array_values(array_filter($usable, static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false));

        return $private[0] ?? $usable[0] ?? null;
    }

    /**
     * Whether the address is still one of the host's (the doctor's and status's "LAN address changed" check): null when
     * the launcher reported no addresses at all, so nothing can be said.
     */
    public static function isCurrent(string $address, HostFacts $facts): ?bool
    {
        return $facts->lanAddresses === [] ? null : in_array($address, $facts->lanAddresses, true);
    }

    /** An IPv4 unicast address that is neither loopback, "this network", link-local nor multicast/reserved. */
    public static function usable(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && ! str_starts_with($address, '127.')
            && ! str_starts_with($address, '0.')
            && ! str_starts_with($address, '169.254.')
            && (int) explode('.', $address)[0] < 224;
    }
}
