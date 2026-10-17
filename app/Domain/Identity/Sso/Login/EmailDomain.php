<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Login;

use Illuminate\Support\Str;

/**
 * The domain of an email address in the form sso_domains stores (lower-case, punycode; ADR 0036), or null for
 * anything that is not a plausible address. Exact match only: subdomains are separate domains.
 */
final class EmailDomain
{
    public static function of(?string $email): ?string
    {
        $email = Str::lower(trim((string) $email));
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return null;
        }

        $domain = rtrim(substr($email, $at + 1), '.');
        if ($domain === '' || str_contains($domain, '@')) {
            return null;
        }
        if (preg_match('/[^\x20-\x7e]/', $domain) === 1) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $domain = $ascii === false ? '' : Str::lower($ascii);
        }

        return preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $domain) === 1 ? $domain : null;
    }

    /** Lower-cased, trimmed address (the users.email form). */
    public static function normalizeEmail(?string $email): string
    {
        return Str::lower(trim((string) $email));
    }
}
