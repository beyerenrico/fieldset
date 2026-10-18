<?php

declare(strict_types=1);

namespace App\Domain\Instance\Runtime;

use Symfony\Component\HttpFoundation\IpUtils;

/** Host names as the runtime compares them: lowercase, IPv6 without brackets (Symfony's getHost() keeps them). */
final class HostName
{
    private const array LOOPBACK_NETWORKS = ['127.0.0.0/8', '::1/128'];

    public static function normalize(string $host): string
    {
        return trim(strtolower(trim($host)), '[]');
    }

    public static function fromUrl(string $url): string
    {
        return self::normalize((string) parse_url($url, PHP_URL_HOST));
    }

    /** localhost, *.localhost, 127.0.0.0/8 and ::1: plain http there is a secure context and never gets HSTS. */
    public static function isLoopback(string $host): bool
    {
        $host = self::normalize($host);
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        $ip = self::canonicalIp($host);

        return $ip !== null && IpUtils::checkIp($ip, self::LOOPBACK_NETWORKS);
    }

    /** An IP address in canonical form, IPv4-mapped IPv6 (::ffff:a.b.c.d) as plain IPv4; null for anything else. */
    public static function canonicalIp(string $address): ?string
    {
        $packed = filter_var($address, FILTER_VALIDATE_IP) !== false ? inet_pton($address) : false;
        if ($packed === false) {
            return null;
        }
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
        }

        return inet_ntop($packed) ?: null;
    }

    /**
     * The spellings a Host header can carry for a host: an IPv6 address also in brackets.
     *
     * @return list<string>
     */
    public static function spellings(string $host): array
    {
        $host = self::normalize($host);

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? [$host, '['.$host.']'] : [$host];
    }
}
