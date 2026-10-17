<?php

declare(strict_types=1);

namespace App\Http\Middleware\Security;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Behind HTTPS the session and XSRF cookies are always Secure, whatever SESSION_SECURE_COOKIE says
 * (ADR 0040). Runs globally, before StartSession and the CSRF middleware write their cookies.
 * SameSite stays at config('session.same_site') (lax), HttpOnly at config('session.http_only').
 */
final class SecureCookies
{
    public function handle(Request $request, Closure $next): Response
    {
        if (SecureTransport::expected($request)) {
            config(['session.secure' => true]);
        }

        return $next($request);
    }
}
