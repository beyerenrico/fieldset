<?php

declare(strict_types=1);

namespace App\Support\Network;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * SSRF building block (§11): is an IP address globally routable? PHP's FILTER_FLAG_GLOBAL_RANGE rejects
 * private, loopback, link-local, CGNAT and reserved ranges (IPv4 and IPv6, including IPv4-mapped IPv6);
 * the extra list closes what it lets through: multicast, broadcast, NAT64 and 6to4 prefixes (which embed
 * IPv4 addresses) and the deprecated 6to4 relay range. Used by the SMTP and webhook guards.
 */
final class PublicAddress
{
    private const array BLOCKED = [
        '0.0.0.0/8', '100.64.0.0/10', '192.88.99.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/23', '2001:db8::/32',
        '2002::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    public static function isPublic(string $ip): bool
    {
        $ip = trim($ip, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }

        return ! IpUtils::checkIp($ip, self::BLOCKED);
    }

    /** @param  list<string>  $addresses  true when there is at least one and every one is public */
    public static function allPublic(array $addresses): bool
    {
        return $addresses !== [] && array_all($addresses, static fn (string $ip): bool => self::isPublic($ip));
    }
}
