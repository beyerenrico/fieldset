<?php

declare(strict_types=1);

namespace App\Domain\Mail\Sending;

use RuntimeException;

/**
 * SSRF guard for per-organization SMTP servers (§11): the host must resolve only to globally routable
 * addresses and the port must be an SMTP submission port (messaging.smtp.allowed_ports), unless
 * messaging.smtp.allow_private_hosts is enabled (development, Mailpit). Checked when settings are saved;
 * at send time pinnedAddress() resolves once and returns the validated address the transport connects to,
 * so a DNS answer that changes between check and connect (rebinding) cannot redirect the connection.
 */
final class SmtpHostGuard
{
    public function isAllowed(string $host): bool
    {
        if ($this->allowsPrivate()) {
            return true;
        }

        return $this->publicAddresses($this->resolve($host)) !== null;
    }

    public function isAllowedPort(int $port): bool
    {
        return $this->allowsPrivate() || in_array($port, array_map(intval(...), (array) config('messaging.smtp.allowed_ports', [25, 465, 587, 2525])), true);
    }

    public function assertAllowed(string $host): void
    {
        if (! $this->isAllowed($host)) {
            throw new RuntimeException("SMTP host [{$host}] is not allowed: it must resolve to a public address.");
        }
    }

    /**
     * Resolves the host once, validates every address and the port, and returns the address to connect
     * to (IPv4 preferred; IPv6 in brackets, as stream_socket_client expects).
     */
    public function pinnedAddress(string $host, int $port): string
    {
        if (! $this->isAllowedPort($port)) {
            throw new RuntimeException("SMTP port [{$port}] is not allowed: use 25, 465, 587 or 2525.");
        }
        $addresses = $this->resolve($host);
        $allowed = $this->allowsPrivate() ? $addresses : $this->publicAddresses($addresses);
        if ($allowed === null || $allowed === []) {
            throw new RuntimeException("SMTP host [{$host}] is not allowed: it must resolve to a public address.");
        }
        $ipv4 = array_values(array_filter($allowed, static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false));
        $address = $ipv4[0] ?? $allowed[0];

        return str_contains($address, ':') ? '['.$address.']' : $address;
    }

    private function allowsPrivate(): bool
    {
        return (bool) config('messaging.smtp.allow_private_hosts');
    }

    /**
     * @param  list<string>  $addresses
     * @return list<string>|null null when there is none or any is not globally routable
     */
    private function publicAddresses(array $addresses): ?array
    {
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
                return null;
            }
        }

        return $addresses === [] ? null : $addresses;
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = gethostbynamel($host) ?: [];
        $records = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($records as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
