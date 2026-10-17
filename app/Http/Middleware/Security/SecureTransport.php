<?php

declare(strict_types=1);

namespace App\Http\Middleware\Security;

use Illuminate\Http\Request;

/**
 * Whether the instance runs behind HTTPS, and the HSTS header it then sends (ADR 0040).
 *
 * HTTPS is expected when the request is secure — behind a TLS-terminating proxy listed in
 * TRUSTED_PROXIES (config trustedproxy.proxies) that includes its X-Forwarded-Proto, since TrustProxies
 * runs before this middleware — or when APP_URL is https, a safety net for a proxy nobody configured.
 *
 * Settings (defaults in code): security.hsts.enabled (null = automatic, true, false),
 * security.hsts.max_age (one year), security.hsts.include_subdomains, security.hsts.preload.
 */
final class SecureTransport
{
    public const int HSTS_MAX_AGE = 31_536_000;

    public static function expected(Request $request): bool
    {
        return $request->isSecure() || str_starts_with(strtolower((string) config('app.url')), 'https://');
    }

    public static function hsts(Request $request): ?string
    {
        $enabled = config('security.hsts.enabled');
        if ($enabled === false || ($enabled !== true && ! self::expected($request))) {
            return null;
        }

        $value = 'max-age='.max(0, (int) config('security.hsts.max_age', self::HSTS_MAX_AGE));
        if ((bool) config('security.hsts.include_subdomains', false)) {
            $value .= '; includeSubDomains';
        }
        if ((bool) config('security.hsts.preload', false)) {
            $value .= '; preload';
        }

        return $value;
    }
}
