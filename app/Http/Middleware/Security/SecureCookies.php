<?php

declare(strict_types=1);

namespace App\Http\Middleware\Security;

use App\Domain\Instance\Runtime\ResolvedRequest;
use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Behind HTTPS the session and XSRF cookies are always Secure, whatever SESSION_SECURE_COOKIE says
 * (ADR 0040). Runs globally, before StartSession and the CSRF middleware write their cookies.
 * SameSite stays at config('session.same_site') (lax), HttpOnly at config('session.http_only').
 *
 * On a `fieldset` instance (ADR 0074) both follow the scheme the runtime resolved for the request host: Secure exactly
 * when it is HTTPS, and then the session cookie is `__Host-<name>` (ASVS 3.4.4: Secure, Path=/, no Domain), so a
 * plain-http localhost request keeps a cookie the browser accepts. A configured SESSION_DOMAIN or path rules the
 * prefix out.
 */
final class SecureCookies
{
    public const string HOST_PREFIX = '__Host-';

    public function handle(Request $request, Closure $next): Response
    {
        $resolved = ResolvedRequest::of($request);
        if ($resolved instanceof ResolvedRequest) {
            $name = $this->sessionCookie($resolved->https);
            config(['session.secure' => $resolved->https, 'session.cookie' => $name]);
            $this->renameOpenStores($name);
        } elseif (SecureTransport::expected($request)) {
            config(['session.secure' => true]);
        }

        return $next($request);
    }

    /** A session store built earlier in this process (tests, long-running workers) still carries the old name. */
    private function renameOpenStores(string $name): void
    {
        if (! app()->resolved('session')) {
            return;
        }
        $manager = app('session');
        foreach ($manager instanceof SessionManager ? $manager->getDrivers() : [] as $store) {
            if ($store instanceof Session) {
                $store->setName($name);
            }
        }
    }

    private function sessionCookie(bool $https): string
    {
        $name = (string) config('session.cookie');
        $base = str_starts_with($name, self::HOST_PREFIX) ? substr($name, strlen(self::HOST_PREFIX)) : $name;
        $prefixable = in_array(config('session.domain'), [null, ''], true) && config('session.path', '/') === '/';

        return $https && $prefixable ? self::HOST_PREFIX.$base : $base;
    }
}
