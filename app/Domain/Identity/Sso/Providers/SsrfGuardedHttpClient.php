<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Providers;

use App\Support\Network\HostResolver;
use App\Support\Network\PublicAddress;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * The only HTTP client SSO code uses (discovery, JWKS, token endpoint, userinfo, SAML metadata; ADR 0036,
 * §11): every request — including ones to endpoints a discovery document names — must be https without
 * credentials, and its host must resolve to public addresses only. The connection is pinned to the checked
 * address (curl CURLOPT_RESOLVE), so a DNS answer that changes between check and connect cannot redirect it.
 * Redirects and proxies are off. `sso.allow_insecure_idp` (dev/CI: Keycloak in Docker) allows http and
 * private hosts. Tests swap the transport with withHandler(); the guard still runs in front of it.
 */
final class SsrfGuardedHttpClient
{
    /** @var (Closure(RequestInterface, array<string, mixed>): PromiseInterface)|null */
    private ?Closure $handler = null;

    public function __construct(private readonly HostResolver $resolver) {}

    /** @param  Closure(RequestInterface, array<string, mixed>): PromiseInterface  $handler */
    public function withHandler(Closure $handler): self
    {
        $clone = clone $this;
        $clone->handler = $handler;

        return $clone;
    }

    public function client(): Client
    {
        $stack = HandlerStack::create($this->handler);
        // Pushed last = innermost: it sees every request actually sent, after all other middleware.
        $stack->push(fn (callable $next): Closure => fn (RequestInterface $request, array $options): PromiseInterface => $next($request, $this->guard($request->getUri(), $options)), 'sso_ssrf_guard');

        return new Client([
            'handler' => $stack,
            'allow_redirects' => false,
            'proxy' => '',
            'timeout' => max(1, config()->integer('sso.http.timeout', 10)),
            'connect_timeout' => max(1, config()->integer('sso.http.connect_timeout', 5)),
            'headers' => ['User-Agent' => config()->string('app.name').' SSO'],
        ]);
    }

    /**
     * Validates the target and pins the connection to the checked address.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws SsrfBlockedException
     */
    public function guard(UriInterface $uri, array $options): array
    {
        $insecure = (bool) config('sso.allow_insecure_idp');
        $scheme = strtolower($uri->getScheme());
        $host = strtolower(trim($uri->getHost(), '[]'));

        if ($host === '' || ! in_array($scheme, $insecure ? ['http', 'https'] : ['https'], true)) {
            throw SsrfBlockedException::for($host, 'https required');
        }
        if ($uri->getUserInfo() !== '') {
            throw SsrfBlockedException::for($host, 'credentials in URL');
        }
        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $port = $uri->getPort() ?? ($scheme === 'http' ? 80 : 443);
        if ($insecure) {
            // Dev/CI: any host, but still pinned to the system resolver's (getaddrinfo) answer. libcurl
            // resolves `*.localhost` to loopback by itself and would ignore a container's extra_hosts entry.
            $addresses = $literal ? [] : (gethostbynamel($host) ?: []);

            return $addresses === [] ? $options : $this->pin($options, $host, $port, $addresses);
        }

        $addresses = $literal ? [$host] : $this->resolver->resolve($host);
        if (! PublicAddress::allPublic($addresses)) {
            throw SsrfBlockedException::for($host, 'not a public address');
        }

        return $literal ? $options : $this->pin($options, $host, $port, $addresses);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  list<string>  $addresses
     * @return array<string, mixed>
     */
    private function pin(array $options, string $host, int $port, array $addresses): array
    {
        $ipv4 = array_values(array_filter($addresses, static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false));
        $address = $ipv4[0] ?? $addresses[0];
        $address = str_contains($address, ':') ? '['.$address.']' : $address;
        $curl = is_array($options['curl'] ?? null) ? $options['curl'] : [];
        $curl[CURLOPT_RESOLVE] = ["{$host}:{$port}:{$address}"];
        $options['curl'] = $curl;

        return $options;
    }
}
