<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Instance\Runtime\OperationGate;
use App\Domain\Instance\Runtime\RuntimeState;
use App\Domain\Instance\Runtime\RuntimeStateReader;
use App\Domain\Instance\Runtime\RuntimeUrl;
use App\Domain\Instance\Runtime\TunnelPathPolicy;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Applies the instance runtime state to each request (R56, R62, R65; KTD11, KTD12; ADR 0074). Global middleware,
 * after TrustHosts (whose patterns already include the runtime hosts) and TrustProxies, before SecureCookies and
 * every route middleware. A no-op without FIELDSET_RUNTIME_PATH.
 *
 * 1. A request from cloudflared's address gets its host pinned to the tunnel hostname (TunnelPathPolicy).
 * 2. Reading the host: an unknown host gets its 400 here (TrustedHosts), as before. The URL root and the HTTPS
 *    expectation are resolved and kept on the request (RuntimeUrl), so every answer below follows them.
 * 3. Tunnel requests: an expired or missing tunnel answers 503 with Retry-After, a path outside R65 404.
 * 4. The operation gate: 503 with Retry-After unless OperationGate admits the request.
 * 5. URL root and Sanctum stateful domains are applied for the rest of the request.
 */
final readonly class ResolveInstanceRuntime
{
    public function __construct(
        private RuntimeStateReader $reader,
        private RuntimeUrl $urls,
        private OperationGate $gate,
        private TunnelPathPolicy $tunnel,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $state = $this->reader->current();
        if (! $state instanceof RuntimeState) {
            return $next($request);
        }
        $now = CarbonImmutable::now();
        $fromPeer = $this->tunnel->isTunnelPeer($state, $request);
        if ($fromPeer) {
            $this->tunnel->pinHost($request, $state->tunnel->hostname ?? throw $this->tunnelUnavailable());
        }
        $viaTunnel = $fromPeer || $state->isTunnelHost($request->getHost());
        // Resolved before the 404/503 answers below, so those follow the host's scheme too (no HSTS on loopback http).
        $resolved = $this->urls->resolve($state, $request, $viaTunnel, $now);
        $this->urls->remember($resolved, $request);
        if ($viaTunnel) {
            $this->guardTunnel($state, $request, $now);
        }
        if (! $this->gate->admits($state, $request, $viaTunnel)) {
            throw new HttpException(503, '', null, ['Retry-After' => (string) OperationGate::RETRY_AFTER]);
        }
        $this->urls->applyToRequest($resolved, $state, $request, $now);

        return $next($request);
    }

    private function guardTunnel(RuntimeState $state, Request $request, CarbonImmutable $now): void
    {
        if (! $state->tunnel->isActive($now)) {
            throw $this->tunnelUnavailable();
        }
        if (! $this->tunnel->allows($request, $state->tunnel->admin)) {
            throw new NotFoundHttpException;
        }
    }

    private function tunnelUnavailable(): HttpException
    {
        return new HttpException(503, '', null, ['Retry-After' => (string) TunnelPathPolicy::RETRY_AFTER]);
    }
}
