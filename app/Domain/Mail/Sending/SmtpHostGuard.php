<?php

declare(strict_types=1);

namespace App\Domain\Mail\Sending;

use RuntimeException;

/**
 * SSRF guard for per-organization SMTP servers (§11): the host must resolve only to globally
 * routable addresses, unless messaging.smtp.allow_private_hosts is enabled (development, Mailpit).
 * Checked when settings are saved and again before every send.
 */
final class SmtpHostGuard
{
    public function isAllowed(string $host): bool
    {
        if ((bool) config('messaging.smtp.allow_private_hosts')) {
            return true;
        }

        $addresses = $this->resolve($host);

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
                return false;
            }
        }

        return $addresses !== [];
    }

    public function assertAllowed(string $host): void
    {
        if (! $this->isAllowed($host)) {
            throw new RuntimeException("SMTP host [{$host}] is not allowed: it must resolve to a public address.");
        }
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
