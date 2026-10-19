<?php

declare(strict_types=1);

namespace App\Http\Middleware\Security;

use App\Domain\Instance\Runtime\ResolvedRequest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For the phone trust page and its root certificate only (R19, ADR 0082): the edge serves them on plain HTTP to a
 * phone that does not trust the local CA yet. The runtime resolves HTTPS as expected for the instance host even over
 * plain HTTP (ADR 0074), so SecurityHeaders would send HSTS and `upgrade-insecure-requests` and the phone could not
 * load the page's own links. Over plain HTTP this route middleware records the request as not HTTPS (same URL root
 * and tunnel flag); a secure request is left alone. The routes run without session and cookies, so nothing else
 * follows the changed value.
 */
final class AllowPlainHttpPage
{
    public function handle(Request $request, Closure $next): Response
    {
        $resolved = ResolvedRequest::of($request);
        if ($resolved instanceof ResolvedRequest && $resolved->https && ! $request->isSecure()) {
            $request->attributes->set(ResolvedRequest::ATTRIBUTE, new ResolvedRequest($resolved->root, false, $resolved->viaTunnel));
        }

        return $next($request);
    }
}
