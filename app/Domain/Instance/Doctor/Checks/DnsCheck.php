<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor\Checks;

use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\CheckStatus;
use App\Domain\Instance\Doctor\DoctorCheck;
use App\Domain\Instance\Doctor\DoctorContext;

/**
 * Domain mode (R10, AE5): does the domain resolve to this host? A and AAAA records are checked separately, against
 * the host's addresses from the launcher (FIELDSET_LAN_ADDRESSES, global IPv4 on a VPS). The ops container sees no
 * public address of its own and fieldset makes no outbound call to learn one (§10), so:
 *
 * - no record at all, or a record pointing at a non-public address that is not the host's: fail;
 * - an A record pointing elsewhere while the host has a public IPv4: fail;
 * - the host has only private addresses (NAT, port forwarding): warn, it cannot tell;
 * - AAAA records: the host's IPv6 addresses are unknown, so a public AAAA warns (ACME may use it) and a
 *   non-public one fails.
 *
 * Local mode skips the check.
 */
final readonly class DnsCheck implements DoctorCheck
{
    public function key(): string
    {
        return 'dns';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $domain = $context->domain;
        if ($domain === null) {
            return CheckResult::skipped($this->key(), 'Local mode: no domain to resolve.');
        }
        $a = $context->probes->resolve($domain, DNS_A);
        $aaaa = $context->probes->resolve($domain, DNS_AAAA);
        $host = $context->facts->lanAddresses;
        $details = ['domain' => $domain, 'a' => $a, 'aaaa' => $aaaa, 'host_addresses' => $host];
        if ($a === [] && $aaaa === []) {
            return CheckResult::fail($this->key(), "{$domain} does not resolve.", "Create an A record for {$domain} pointing at this server's public address, wait for it to propagate, then run the command again.", $details);
        }
        $parts = [$this->ipv4($domain, $a, $host), $this->ipv6($domain, $aaaa)];
        usort($parts, static fn (array $x, array $y): int => self::rank($y[0]) <=> self::rank($x[0]));
        [$status, $summary, $hint] = $parts[0];

        return new CheckResult($this->key(), $status, $summary, $hint, $details);
    }

    /**
     * @param  list<string>  $records
     * @param  list<string>  $host
     * @return array{0: CheckStatus, 1: string, 2: string|null}
     */
    private function ipv4(string $domain, array $records, array $host): array
    {
        $hint = "Point the A record of {$domain} at this server's public address.";
        if ($records === []) {
            return [CheckStatus::Warn, "{$domain} has no A record; IPv4 clients cannot reach it.", $hint];
        }
        if (array_intersect($records, $host) !== []) {
            return [CheckStatus::Ok, "{$domain} resolves to this host (".implode(', ', $records).').', null];
        }
        $list = implode(', ', $records);
        if (array_any($records, static fn (string $ip): bool => ! self::isPublic($ip))) {
            return [CheckStatus::Fail, "{$domain} resolves to {$list}, which is not an address of this host.", $hint];
        }
        if (array_any($host, self::isPublic(...))) {
            return [CheckStatus::Fail, "{$domain} resolves to {$list}, but this host's address is ".implode(', ', $host).'.', $hint];
        }

        return [CheckStatus::Warn, "{$domain} resolves to {$list}; this host has only private addresses, so fieldset cannot tell whether that is its public address (NAT).", 'Make sure ports 80 and 443 of that address reach this host.'];
    }

    /**
     * @param  list<string>  $records
     * @return array{0: CheckStatus, 1: string, 2: string|null}
     */
    private function ipv6(string $domain, array $records): array
    {
        if ($records === []) {
            return [CheckStatus::Ok, "{$domain} has no AAAA record.", null];
        }
        $list = implode(', ', $records);
        if (array_any($records, static fn (string $ip): bool => ! self::isPublic($ip))) {
            return [CheckStatus::Fail, "{$domain} has AAAA records ({$list}) that are not public addresses.", "Remove the AAAA record of {$domain} or point it at this server."];
        }

        return [CheckStatus::Warn, "{$domain} has AAAA records ({$list}); fieldset cannot see this host's IPv6 addresses.", 'Make sure the AAAA record points at this server, or remove it: certificate requests may use IPv6.'];
    }

    private static function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    private static function rank(CheckStatus $status): int
    {
        return match ($status) {
            CheckStatus::Fail => 3,
            CheckStatus::Warn => 2,
            CheckStatus::Ok => 1,
            CheckStatus::Skipped => 0,
        };
    }
}
