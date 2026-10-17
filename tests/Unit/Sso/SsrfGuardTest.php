<?php

declare(strict_types=1);

use App\Domain\Identity\Sso\Providers\SsrfBlockedException;
use App\Domain\Identity\Sso\Providers\SsrfGuardedHttpClient;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\RequestInterface;
use Tests\Feature\Workflows\FakeHostResolver;

function ssoGuard(array $hosts = []): SsrfGuardedHttpClient
{
    return new SsrfGuardedHttpClient(new FakeHostResolver($hosts));
}

it('pins a public host to its checked address and keeps the port', function (): void {
    $options = ssoGuard(['idp.example.test' => ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c']])
        ->guard(new Uri('https://idp.example.test:8443/realms/x'), ['curl' => [CURLOPT_TIMEOUT => 3]]);

    expect($options['curl'])->toBe([CURLOPT_TIMEOUT => 3, CURLOPT_RESOLVE => ['idp.example.test:8443:93.184.215.14']]);
});

it('pins IPv6-only hosts in brackets and leaves IP literals unpinned', function (): void {
    expect(ssoGuard(['v6.example.test' => ['2606:2800:21f:cb07:6820:80da:af6b:8b2c']])->guard(new Uri('https://v6.example.test/'), [])['curl'][CURLOPT_RESOLVE])
        ->toBe(['v6.example.test:443:[2606:2800:21f:cb07:6820:80da:af6b:8b2c]'])
        ->and(ssoGuard()->guard(new Uri('https://93.184.215.14/'), []))->toBe([]);
});

it('refuses non-https, credentials and private, loopback, link-local or mixed answers', function (string $url, array $hosts): void {
    expect(fn () => ssoGuard($hosts)->guard(new Uri($url), []))->toThrow(SsrfBlockedException::class);
})->with([
    'http' => ['http://idp.example.test/', []],
    'ftp' => ['ftp://idp.example.test/', []],
    'credentials' => ['https://user:pw@idp.example.test/', []],
    'private' => ['https://intranet.example.test/', ['intranet.example.test' => ['10.1.2.3']]],
    'loopback literal' => ['https://127.0.0.1/', []],
    'metadata service' => ['https://169.254.169.254/latest', []],
    'ipv6 loopback' => ['https://[::1]/', []],
    'mixed' => ['https://mixed.example.test/', ['mixed.example.test' => ['93.184.215.14', '192.168.0.2']]],
    'unresolvable' => ['https://nowhere.example.test/', ['nowhere.example.test' => []]],
]);

it('allows http and private hosts only with sso.allow_insecure_idp', function (): void {
    config(['sso.allow_insecure_idp' => true]);

    // `.invalid` never resolves (RFC 2606): no pinning, and the private host is still allowed.
    expect(ssoGuard(['keycloak.invalid' => ['172.18.0.4']])->guard(new Uri('http://keycloak.invalid:8080/realms/x'), []))->toBe([])
        ->and(fn () => ssoGuard()->guard(new Uri('ftp://keycloak/'), []))->toThrow(SsrfBlockedException::class);
});

it('pins the system resolver\'s answer in insecure mode (libcurl would map *.localhost to loopback itself)', function (): void {
    config(['sso.allow_insecure_idp' => true]);

    // `localhost` resolves everywhere; inside Docker, keycloak.localhost resolves to the host gateway instead.
    $options = ssoGuard()->guard(new Uri('http://localhost:8180/realms/x'), []);

    expect($options['curl'][CURLOPT_RESOLVE] ?? null)->toBe(['localhost:8180:127.0.0.1']);
});

it('guards every request of the client, follows no redirects and uses no proxy', function (): void {
    $seen = [];
    $client = ssoGuard(['ok.example.test' => ['93.184.215.14'], 'bad.example.test' => ['10.0.0.1']])
        ->withHandler(function (RequestInterface $request, array $options) use (&$seen): PromiseInterface {
            $seen[] = [(string) $request->getUri(), $options['allow_redirects'], $options['proxy']];

            return Create::promiseFor(new Response(302, ['Location' => 'https://bad.example.test/']));
        })->client();

    expect($client->get('https://ok.example.test/x')->getStatusCode())->toBe(302)
        ->and($seen)->toBe([['https://ok.example.test/x', false, '']])
        ->and(fn () => $client->get('https://bad.example.test/'))->toThrow(SsrfBlockedException::class)
        ->and($seen)->toHaveCount(1);
});
