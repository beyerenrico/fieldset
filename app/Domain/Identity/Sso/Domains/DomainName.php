<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Domains;

/**
 * Normalizes a claimed SSO email domain (ADR 0036): trimmed, lower-case, IDN → punycode, no trailing dot.
 * Only exact host names are accepted (no wildcards, URLs, ports, addresses); IP addresses and public
 * suffixes are refused. Lookups (discovery, the password gate) match the stored value exactly.
 */
final class DomainName
{
    private const int MAX_LENGTH = 253;

    private const string LABEL = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    /** @throws InvalidDomainName */
    public static function normalize(string $input): string
    {
        $domain = rtrim(trim($input), '.');
        if (self::isIpAddress($domain)) {
            throw new InvalidDomainName('ip_address');
        }
        if ($domain === '' || preg_match('/[\s@\/\\\\:*?#\[\]]/u', $domain) === 1) {
            throw new InvalidDomainName('invalid');
        }

        $ascii = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ, INTL_IDNA_VARIANT_UTS46);
        if (! is_string($ascii) || ! self::isHostName($ascii = strtolower($ascii))) {
            throw new InvalidDomainName('invalid');
        }
        if (PublicSuffixes::isPublicSuffix($ascii)) {
            throw new InvalidDomainName('public_suffix');
        }

        return $ascii;
    }

    private static function isIpAddress(string $value): bool
    {
        $value = str_starts_with($value, '[') && str_ends_with($value, ']') ? substr($value, 1, -1) : $value;

        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    private static function isHostName(string $ascii): bool
    {
        if (strlen($ascii) > self::MAX_LENGTH) {
            return false;
        }
        $labels = explode('.', $ascii);
        foreach ($labels as $label) {
            if (preg_match(self::LABEL, $label) !== 1) {
                return false;
            }
        }

        // A top-level domain is never all-numeric (that would be an IP-like name).
        return preg_match('/^[0-9]+$/', end($labels)) !== 1;
    }
}
