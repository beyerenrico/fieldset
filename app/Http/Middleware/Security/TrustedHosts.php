<?php

declare(strict_types=1);

namespace App\Http\Middleware\Security;

/**
 * Host header allow-list for Laravel's TrustHosts middleware (ADR 0040): password-reset and invitation
 * links are built from the request's host, so an arbitrary Host (or a trusted proxy's X-Forwarded-Host)
 * must not reach the application. Laravel applies it outside the local and testing environments only.
 *
 * Allowed: the APP_URL host, loopback names (container health checks such as `curl localhost:8080/up`;
 * a link to the recipient's own machine leaks nothing) and config('security.trusted_hosts')
 * (TRUSTED_HOSTS, comma-separated extra host names). Evaluated per request, after config is loaded.
 */
final class TrustedHosts
{
    private const array LOOPBACK = ['localhost', '127.0.0.1', '[::1]', '::1'];

    /** @return list<string> regular expressions as Symfony's Request::setTrustedHosts() expects them */
    public static function patterns(): array
    {
        $extra = config('security.trusted_hosts', []);
        $extra = is_string($extra) ? explode(',', $extra) : (is_array($extra) ? $extra : []);
        $hosts = [parse_url((string) config('app.url'), PHP_URL_HOST), ...self::LOOPBACK, ...$extra];

        $names = array_unique(array_filter(array_map(
            static fn (mixed $host): string => is_string($host) ? strtolower(trim($host)) : '',
            $hosts,
        )));

        return array_values(array_map(static fn (string $host): string => '^'.preg_quote($host, '/').'$', $names));
    }
}
