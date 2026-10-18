<?php

declare(strict_types=1);

namespace App\Domain\Instance\Runtime;

use Illuminate\Http\Request;

/**
 * What the quick tunnel may reach (R65, KTD12, ADR 0074). Local mode has no edge, so the policy lives here.
 *
 * A request is a tunnel request when its TCP peer (REMOTE_ADDR, before any proxy resolution) is cloudflared's
 * fixed address from runtime.json, whatever Host or X-Forwarded-Host it carries, or when its host is the tunnel
 * host. For the peer, the host comes only from the runtime tunnel hostname: pinHost() overwrites Host and drops
 * the forwarded host and port, so a forged `X-Forwarded-Host: localhost` cannot reach /platform.
 *
 * Allow-list by path prefix: the Field App, the API, sign-in and assets. Everything else is 404 there, /platform,
 * Horizon and the API docs above all; /admin (with Livewire, Filament downloads and the builder preview) only with
 * `--tunnel-admin`.
 */
final readonly class TunnelPathPolicy
{
    /** An expired tunnel host: the Field App's sync treats 503 as retryable and backs off. */
    public const int RETRY_AFTER = 300;

    /** @var list<string> Request::is() patterns reachable through every tunnel */
    public const array ALLOWED = [
        'app', 'app/*',                 // Field App shell, service worker, manifest and its static files
        'api', 'api/*',                 // API v1, including signed document downloads
        'auth/*',                       // Fortify sign-in, two-factor, password reset, SSO start and return
        'sanctum/csrf-cookie',          // the Field App's CSRF cookie
        'login-link',                   // one-time sign-in links (ADR 0056)
        'invitations/*',                // signed invitations: set a password, then sign in
        'build/*', 'css/*', 'fonts/*', 'js/*', 'favicon.ico', 'robots.txt', // assets (Caddy serves existing files)
    ];

    /** @var list<string> only with `--tunnel-admin` */
    public const array ADMIN = ['/', 'admin', 'admin/*', 'livewire/*', 'livewire-*', 'filament/*', 'form-preview', 'form-preview/*'];

    public function isTunnelPeer(RuntimeState $state, Request $request): bool
    {
        $peer = $state->tunnel->peer === null ? null : HostName::canonicalIp($state->tunnel->peer);
        $remote = HostName::canonicalIp((string) $request->server->get('REMOTE_ADDR', ''));

        return $peer !== null && $remote === $peer;
    }

    /** The tunnel peer's host comes only from runtime.json; cloudflared terminates TLS, so the request is https. */
    public function pinHost(Request $request, string $hostname): void
    {
        foreach (['X-Forwarded-Host', 'X-Forwarded-Port', 'X-Forwarded-Prefix', 'Forwarded'] as $header) {
            $request->headers->remove($header);
        }
        $request->headers->set('Host', $hostname);
        $request->headers->set('X-Forwarded-Proto', 'https');
        $request->server->add(['HTTP_HOST' => $hostname, 'HTTPS' => 'on', 'SERVER_PORT' => 443]);
    }

    public function allows(Request $request, bool $admin): bool
    {
        return $request->is(...self::ALLOWED) || ($admin && $request->is(...self::ADMIN));
    }
}
