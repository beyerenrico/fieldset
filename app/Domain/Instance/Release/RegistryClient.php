<?php

declare(strict_types=1);

namespace App\Domain\Instance\Release;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads manifests by digest over the OCI distribution API, anonymously or with the token flow
 * (`WWW-Authenticate: Bearer realm=…`). `localhost` and `127.0.0.1` registries (the local harness) use plain
 * HTTP; everything else HTTPS. Every answer is checked against the requested digest.
 */
final class RegistryClient
{
    /** @var array<string, string> "registry|repository" => Authorization header value */
    private array $authorizations = [];

    /**
     * @param  array<string, array{0: string, 1: string}>  $credentials  registry => [username, password]
     */
    public function __construct(private readonly array $credentials = []) {}

    public static function baseUrl(string $registry): string
    {
        if ($registry === 'docker.io') {
            return 'https://registry-1.docker.io';
        }

        $host = (string) preg_replace('/:\d+$/D', '', $registry);

        return (in_array($host, ['localhost', '127.0.0.1'], true) ? 'http://' : 'https://').$registry;
    }

    public function manifest(string $registry, string $repository, string $digest): RegistryManifest
    {
        ImagePin::assertRegistry($registry);
        ImagePin::assertRepository($repository);
        ImagePin::assertDigest($digest, "{$registry}/{$repository}");

        $response = $this->get($registry, $repository, self::baseUrl($registry)."/v2/{$repository}/manifests/{$digest}");
        if ($response->status() === 404) {
            throw new ReleaseException(sprintf('%s/%s@%s is not in the registry.', $registry, $repository, $digest));
        }

        if (! $response->successful()) {
            throw new ReleaseException(sprintf('The registry answered HTTP %d for %s/%s@%s.', $response->status(), $registry, $repository, $digest));
        }

        $body = $response->body();
        if ('sha256:'.hash('sha256', $body) !== $digest) {
            throw new ReleaseException(sprintf('The registry returned content for %s/%s that does not match %s.', $registry, $repository, $digest));
        }

        return new RegistryManifest($this->mediaType($response->header('Content-Type'), $body), $body, $digest);
    }

    private function get(string $registry, string $repository, string $url): Response
    {
        $key = "{$registry}|{$repository}";
        $response = $this->request($key)->get($url);
        if ($response->status() !== 401) {
            return $response;
        }

        $this->authorizations[$key] = $this->authorize($registry, $repository, $response->header('WWW-Authenticate'));

        return $this->request($key)->get($url);
    }

    private function request(string $key): PendingRequest
    {
        $request = Http::accept(implode(', ', [...RegistryManifest::INDEX_TYPES, ...RegistryManifest::IMAGE_TYPES]))
            ->timeout(60)
            ->connectTimeout(10)
            ->retry(3, 1000, static fn (Throwable $exception): bool => $exception instanceof ConnectionException, throw: false);

        $authorization = $this->authorizations[$key] ?? null;

        return $authorization === null ? $request : $request->withHeaders(['Authorization' => $authorization]);
    }

    /** Answers a 401 challenge: a bearer token from the realm, or basic credentials when the registry asks for them. */
    private function authorize(string $registry, string $repository, string $challenge): string
    {
        $credentials = $this->credentials[$registry] ?? null;
        if (str_starts_with(strtolower($challenge), 'basic') && $credentials !== null) {
            return 'Basic '.base64_encode($credentials[0].':'.$credentials[1]);
        }

        preg_match_all('/(\w+)="([^"]*)"/', $challenge, $pairs, PREG_SET_ORDER);
        $parameters = array_column($pairs, 2, 1);
        $realm = $parameters['realm'] ?? '';
        if (! str_starts_with(strtolower($challenge), 'bearer') || ! str_starts_with($realm, 'https://')) {
            throw new ReleaseException(sprintf('%s refused access to %s and offered no usable token service.', $registry, $repository));
        }

        $request = Http::timeout(30)->connectTimeout(10);
        if ($credentials !== null) {
            $request = $request->withBasicAuth($credentials[0], $credentials[1]);
        }

        $response = $request->get($realm, array_filter([
            'service' => $parameters['service'] ?? null,
            'scope' => "repository:{$repository}:pull",
        ]));
        $token = $response->json('token') ?? $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new ReleaseException(sprintf('%s refused a pull token for %s (HTTP %d).', $registry, $repository, $response->status()));
        }

        return 'Bearer '.$token;
    }

    private function mediaType(string $header, string $body): string
    {
        $type = trim(explode(';', $header, 2)[0]);
        if ($type !== '' && $type !== 'application/json') {
            return $type;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) && is_string($decoded['mediaType'] ?? null) ? $decoded['mediaType'] : $type;
    }
}
