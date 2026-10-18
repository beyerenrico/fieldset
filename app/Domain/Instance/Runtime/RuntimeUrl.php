<?php

declare(strict_types=1);

namespace App\Domain\Instance\Runtime;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;

/**
 * URL root and scheme from the runtime state (R56, KTD11).
 *
 * - A request on an allowed host (local URL, instance URL, extra hosts, active tunnel) links to its own scheme and
 *   host, so a localhost request during a tunnel still links to localhost. The port comes from the URL configured for
 *   that host (local URL, tunnel: 443, instance URL, which extra hosts follow), never from Host or X-Forwarded-Port,
 *   so a client cannot put a port of its choice into a password-reset link (ADR 0079). Any other host, and every job,
 *   uses the public URL: the active tunnel, else the instance URL.
 * - The link scheme is the request's own even when the host's URL is https: Laravel verifies signed URLs against
 *   the request's scheme and host, so a URL signed in a plain-http request (a proxy that is no longer trusted, a host
 *   proxy without X-Forwarded-Proto) must be http to verify. The browser still lands on https: the request counts as
 *   HTTPS below, so HSTS and upgrade-insecure-requests lift the same-origin http links (ADR 0079).
 * - HTTPS is expected when the request is secure, or when the URL configured for its host is https. Loopback over
 *   plain http never counts as HTTPS, so it never gets HSTS or Secure cookies. Nothing marks a plain-http request
 *   secure.
 * - Sanctum's stateful domains gain the allowed hosts, so the Field App keeps its cookie session on each of them.
 */
final readonly class RuntimeUrl
{
    public function __construct(private UrlGenerator $url, private Repository $config) {}

    /** For a request whose host already passed TrustedHosts (an unknown host has had its 400 by then). */
    public function resolve(RuntimeState $state, Request $request, bool $viaTunnel, CarbonImmutable $now): ResolvedRequest
    {
        $host = HostName::normalize($request->getHost());
        $https = $this->https($state, $request, $host, $now);
        if (! $state->isAllowedHost($host, $now)) {
            return new ResolvedRequest($state->publicUrl($now), $https, $viaTunnel);
        }
        $path = $state->isTunnelHost($host) || $state->isLocalHost($host) ? '' : (string) parse_url($state->instanceUrl, PHP_URL_PATH);

        return new ResolvedRequest(rtrim($this->origin($request, $this->configuredUrl($state, $host)).$path, '/'), $https, $viaTunnel);
    }

    /** Records the resolution on the request: SecureTransport and SecureCookies read it, early answers included. */
    public function remember(ResolvedRequest $resolved, Request $request): void
    {
        $request->attributes->set(ResolvedRequest::ATTRIBUTE, $resolved);
    }

    public function applyToRequest(ResolvedRequest $resolved, RuntimeState $state, Request $request, CarbonImmutable $now): void
    {
        $this->forceRoot($resolved->root);
        $this->config->set('sanctum.stateful', $this->statefulDomains($state, $request, $now));
    }

    /**
     * Jobs, queued notifications and mails link to the public URL of the moment the job starts. config('app.url')
     * stays the rendered APP_URL: Message-IDs derive their host from it and must not change between attempts.
     */
    public function applyToJob(RuntimeState $state, CarbonImmutable $now): void
    {
        $this->forceRoot($state->publicUrl($now));
    }

    private function https(RuntimeState $state, Request $request, string $host, CarbonImmutable $now): bool
    {
        if ($request->isSecure()) {
            return true;
        }
        if (HostName::isLoopback($host)) {
            return false;
        }
        if ($state->isTunnelHost($host)) {
            return $state->tunnel->isActive($now);
        }

        return str_starts_with($this->configuredUrl($state, $host), 'https://');
    }

    /** The URL configured for a host: the tunnel's, the local URL (any loopback spelling), else the instance URL. */
    private function configuredUrl(RuntimeState $state, string $host): string
    {
        if ($state->isTunnelHost($host)) {
            return 'https://'.$state->tunnel->hostname;
        }

        return $state->isLocalHost($host) ? (string) $state->localUrl : $state->instanceUrl;
    }

    /**
     * The request's own scheme and host, and the port of the URL configured for that host: always when the schemes
     * agree (left out when it is the default), else only when the request addressed that very port. A client-chosen
     * port (Host, X-Forwarded-Port) never gets in, and a plain-http request on an https URL keeps the port Laravel
     * verifies its signed URLs against.
     */
    private function origin(Request $request, string $configured): string
    {
        $scheme = $request->getScheme();
        $port = parse_url($configured, PHP_URL_PORT);
        $port = is_int($port) ? $port : (str_starts_with($configured, 'https://') ? 443 : 80);
        if (! str_starts_with($configured, $scheme.'://') && (int) $request->getPort() !== $port) {
            $port = null;
        }

        return $scheme.'://'.$request->getHost().($port !== null && $port !== ($scheme === 'https' ? 443 : 80) ? ':'.$port : '');
    }

    private function forceRoot(string $root): void
    {
        $this->url->forceRootUrl($root);
        $this->url->forceScheme(str_starts_with($root, 'https://') ? 'https' : 'http');
    }

    /** @return list<string> */
    private function statefulDomains(RuntimeState $state, Request $request, CarbonImmutable $now): array
    {
        $configured = $this->config->get('sanctum.stateful', []);
        $domains = is_array($configured) ? array_values(array_filter($configured, is_string(...))) : [];
        foreach ($state->allowedHosts($now) as $host) {
            array_push($domains, ...HostName::spellings($host));
        }
        foreach (array_filter([$state->instanceUrl, $state->localUrl]) as $url) {
            $domains[] = (string) preg_replace('#^https?://([^/]+).*$#D', '$1', $url);
        }
        if ($state->isAllowedHost($request->getHost(), $now)) {
            $domains[] = $request->getHttpHost();
        }

        return array_values(array_unique($domains));
    }
}
