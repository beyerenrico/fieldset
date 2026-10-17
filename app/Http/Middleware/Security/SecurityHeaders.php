<?php

declare(strict_types=1);

namespace App\Http\Middleware\Security;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Laravel\Horizon\Horizon;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Global security headers (BUILD_PROMPT §11, ADR 0040): CSP per surface, HSTS behind HTTPS, nosniff,
 * Referrer-Policy, Permissions-Policy and framing rules. A CSP set by a controller (e.g. the sandbox
 * policy of attachment downloads) is kept. Registered outermost; it reads the proxy-aware scheme
 * only after the inner TrustProxies has run.
 */
final class SecurityHeaders
{
    /** Powerful features denied everywhere; the Field App may use the camera and (opt-in) GPS. */
    private const array FEATURES = [
        'accelerometer', 'camera', 'display-capture', 'geolocation', 'gyroscope', 'magnetometer',
        'microphone', 'midi', 'payment', 'serial', 'usb',
    ];

    private const array FIELD_APP_FEATURES = ['camera', 'geolocation'];

    public function handle(Request $request, Closure $next): Response
    {
        $surface = Surface::of($request);
        $nonce = $surface->usesNonce() ? Vite::useCspNonce() : null;
        if ($surface === Surface::Horizon && $nonce !== null) {
            Horizon::cspNonce($nonce);
        }

        $response = $next($request);

        $secure = SecureTransport::expected($request);
        $headers = $response->headers;
        if (! $headers->has('Content-Security-Policy')) {
            $policy = new ContentSecurityPolicy($this->origin($request), $nonce, $secure, $this->hotOrigin());
            $headers->set('Content-Security-Policy', $policy->for($surface));
        }
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', $surface === Surface::Preview ? 'SAMEORIGIN' : 'DENY');
        $headers->set('Permissions-Policy', self::permissionsPolicy($surface));
        $this->setDefault($headers, 'Referrer-Policy', 'same-origin');
        $this->crossOrigin($headers, $surface);

        $hsts = SecureTransport::hsts($request);
        if ($hsts !== null) {
            $headers->set('Strict-Transport-Security', $hsts);
        }

        return $response;
    }

    public static function permissionsPolicy(Surface $surface): string
    {
        $allowed = $surface === Surface::FieldApp ? self::FIELD_APP_FEATURES : [];

        return implode(', ', array_map(
            static fn (string $feature): string => $feature.'='.(in_array($feature, $allowed, true) ? '(self)' : '()'),
            self::FEATURES,
        ));
    }

    /** Top-level documents get their own browsing context group; API responses are not embeddable cross-origin. */
    private function crossOrigin(ResponseHeaderBag $headers, Surface $surface): void
    {
        if (in_array($surface, [Surface::Html, Surface::FieldApp, Surface::Horizon], true)) {
            $this->setDefault($headers, 'Cross-Origin-Opener-Policy', 'same-origin');
        }
        if ($surface === Surface::Api) {
            $this->setDefault($headers, 'Cross-Origin-Resource-Policy', 'same-origin');
        }
    }

    private function setDefault(ResponseHeaderBag $headers, string $name, string $value): void
    {
        if (! $headers->has($name)) {
            $headers->set($name, $value);
        }
    }

    /** The request's origin; APP_URL's when the Host is not a trusted host (the 404 for it still gets headers). */
    private function origin(Request $request): string
    {
        try {
            return $request->getSchemeAndHttpHost();
        } catch (SuspiciousOperationException) {
            return rtrim((string) config('app.url'), '/');
        }
    }

    /** The Vite dev server (`npm run dev` writes public/hot) serves scripts and HMR from its own origin. */
    private function hotOrigin(): ?string
    {
        if (! Vite::isRunningHot()) {
            return null;
        }
        $url = trim((string) @file_get_contents(Vite::hotFile()));

        return preg_match('#^https?://[^/\s]+#', $url, $match) === 1 ? $match[0] : null;
    }
}
