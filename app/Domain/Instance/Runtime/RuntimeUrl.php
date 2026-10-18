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
 * - A request on an allowed host (local URL, instance URL, extra hosts, active tunnel) keeps its own origin, so a
 *   localhost request during a tunnel still links to localhost. Any other host, and every job, uses the public URL:
 *   the active tunnel, else the instance URL.
 * - HTTPS is expected when the request is secure, or when the URL configured for its host is https. Loopback over
 *   plain http never counts as HTTPS, so it never gets HSTS or Secure cookies.
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

        return new ResolvedRequest(rtrim($this->origin($request, $https).$path, '/'), $https, $viaTunnel);
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
        $url = $state->isLocalHost($host) ? (string) $state->localUrl : $state->instanceUrl;

        return str_starts_with($url, 'https://');
    }

    /** The request's scheme and host with the port it addressed, unless that port is the scheme's default. */
    private function origin(Request $request, bool $https): string
    {
        $port = parse_url('http://'.$request->getHttpHost(), PHP_URL_PORT);
        $default = $https ? 443 : 80;

        return ($https ? 'https' : 'http').'://'.$request->getHost().(is_int($port) && $port !== $default ? ':'.$port : '');
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
