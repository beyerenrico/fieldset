<?php

declare(strict_types=1);

namespace App\Http\Middleware\Security;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Starts the session named by the request's cookie and only reads it (ADR 0075): unlike StartSession it never
 * saves the session, never sets a cookie, never records the previous URL and never collects garbage. For endpoints
 * that check a session on behalf of another service, such as the catcher auth the edge's `forward_auth` calls for
 * every one of Mailpit's parallel requests: writing the session back would race with the operator's /platform tab
 * (lost updates) and age its flash data. The store's cookie name was already set by the global SecureCookies
 * (`__Host-…` in HTTPS modes); EncryptCookies must run first.
 *
 * Not a StartSession subclass on purpose: a route that excludes StartSession also excludes its subclasses.
 */
final readonly class ReadOnlySession
{
    public function __construct(private SessionManager $manager) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (($this->manager->getSessionConfig()['driver'] ?? null) === null) {
            return $next($request);
        }
        $session = $this->manager->driver();
        $cookie = $request->cookies->get($session->getName());
        $session->setId(is_string($cookie) ? $cookie : null);
        $session->setRequestOnHandler($request);
        $session->start();
        $request->setLaravelSession($session);

        return $next($request);
    }
}
