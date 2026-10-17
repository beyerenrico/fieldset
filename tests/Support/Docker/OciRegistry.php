<?php

declare(strict_types=1);

namespace Tests\Support\Docker;

use App\Domain\Instance\Release\ImageInspector;
use App\Domain\Instance\Release\ImagePin;
use App\Domain\Instance\Release\RegistryClient;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A minimal OCI distribution client for install journeys, without Laravel: manifests and blobs by digest from
 * upstream registries (anonymous token flow), content-addressed caching on disk, and pushes into the local
 * harness registry. Every byte is checked against its digest.
 */
final class OciRegistry
{
    private const string ACCEPT = 'application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, '
        .'application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json';

    private readonly Client $http;

    /** @var array<string, string> "registry|repository" => Authorization header */
    private array $authorizations = [];

    public function __construct(private readonly string $cache)
    {
        $this->http = new Client(['http_errors' => false, 'connect_timeout' => 10, 'timeout' => 900]);
        if (! is_dir($cache) && ! mkdir($cache, 0755, true) && ! is_dir($cache)) {
            throw new RuntimeException("Cannot create the OCI cache {$cache}.");
        }
    }

    /**
     * Copies an image into the target registry by digest: the exact index bytes (tagged with the pin's tag) and,
     * for the given platforms only, the image manifests with their config and layers. The harness registry
     * accepts such partial indexes (REGISTRY_VALIDATION_MANIFESTS_INDEXES_PLATFORMS=none), so the digest an
     * install pulls is the upstream one while only the needed platforms are downloaded, once, into the cache.
     *
     * @param  list<string>  $platforms
     */
    public function mirror(ImagePin $pin, string $target, array $platforms): void
    {
        $index = $this->manifest($pin->registry, $pin->repository, $pin->digest);
        $entries = json_decode($index['body'], true)['manifests'] ?? null;
        if (! is_array($entries)) {
            throw new RuntimeException("{$pin->reference()} is not a multi-platform index.");
        }

        foreach ($platforms as $platform) {
            $digest = ImageInspector::platformDigest($entries, $platform) ?? throw new RuntimeException("{$pin->reference()} has no {$platform} image.");
            $manifest = $this->manifest($pin->registry, $pin->repository, $digest);
            $document = json_decode($manifest['body'], true);
            foreach ([$document['config'] ?? [], ...($document['layers'] ?? [])] as $blob) {
                $blobDigest = (string) ($blob['digest'] ?? '');
                if (! $this->hasBlob($target, $pin->repository, $blobDigest)) {
                    $this->pushBlob($target, $pin->repository, $blobDigest, $this->blob($pin->registry, $pin->repository, $blobDigest));
                }
            }

            $this->pushManifest($target, $pin->repository, $digest, $manifest['type'], $manifest['body']);
        }

        $this->pushManifest($target, $pin->repository, $pin->tag, $index['type'], $index['body']);
    }

    /**
     * A manifest by digest, from the cache or the registry.
     *
     * @return array{type: string, body: string}
     */
    public function manifest(string $registry, string $repository, string $digest): array
    {
        $path = $this->cachePath($digest);
        if (is_file($path) && is_file("{$path}.type")) {
            return ['type' => (string) file_get_contents("{$path}.type"), 'body' => (string) file_get_contents($path)];
        }

        $response = $this->get($registry, $repository, "/manifests/{$digest}", ['Accept' => self::ACCEPT]);
        $body = (string) $response->getBody();
        if ($response->getStatusCode() !== 200 || 'sha256:'.hash('sha256', $body) !== $digest) {
            throw new RuntimeException("Cannot fetch {$registry}/{$repository}@{$digest} (HTTP {$response->getStatusCode()}).");
        }

        $type = trim(explode(';', $response->getHeaderLine('Content-Type'))[0]);
        file_put_contents("{$path}.type", $type);
        file_put_contents($path, $body);

        return ['type' => $type, 'body' => $body];
    }

    /** A blob by digest as a cached file path, downloading it once. */
    public function blob(string $registry, string $repository, string $digest): string
    {
        $path = $this->cachePath($digest);
        if (is_file($path)) {
            return $path;
        }

        $partial = $path.'.'.bin2hex(random_bytes(4)).'.partial';
        $response = $this->get($registry, $repository, "/blobs/{$digest}", [], ['sink' => $partial]);
        if ($response->getStatusCode() !== 200 || 'sha256:'.hash_file('sha256', $partial) !== $digest) {
            @unlink($partial);
            throw new RuntimeException("Cannot fetch blob {$registry}/{$repository}@{$digest} (HTTP {$response->getStatusCode()}).");
        }

        rename($partial, $path);

        return $path;
    }

    public function hasBlob(string $registry, string $repository, string $digest): bool
    {
        return $this->http->head(RegistryClient::baseUrl($registry)."/v2/{$repository}/blobs/{$digest}")->getStatusCode() === 200;
    }

    /** Uploads a blob in one PUT (monolithic upload) unless the target already has it. */
    public function pushBlob(string $registry, string $repository, string $digest, string $file): void
    {
        if ($this->hasBlob($registry, $repository, $digest)) {
            return;
        }

        $base = RegistryClient::baseUrl($registry);
        $start = $this->http->post("{$base}/v2/{$repository}/blobs/uploads/");
        $location = $start->getHeaderLine('Location');
        if ($start->getStatusCode() !== 202 || $location === '') {
            throw new RuntimeException("Cannot start a blob upload to {$registry}/{$repository} (HTTP {$start->getStatusCode()}).");
        }

        $url = (str_starts_with($location, '/') ? $base.$location : $location).(str_contains($location, '?') ? '&' : '?').'digest='.urlencode($digest);
        $put = $this->http->put($url, [
            'body' => Utils::tryFopen($file, 'r'),
            'headers' => ['Content-Type' => 'application/octet-stream', 'Content-Length' => (string) filesize($file)],
        ]);
        if ($put->getStatusCode() !== 201) {
            throw new RuntimeException("Uploading {$digest} to {$registry}/{$repository} failed (HTTP {$put->getStatusCode()}): {$put->getBody()}");
        }
    }

    public function pushManifest(string $registry, string $repository, string $reference, string $type, string $body): void
    {
        $response = $this->http->put(RegistryClient::baseUrl($registry)."/v2/{$repository}/manifests/{$reference}", [
            'body' => $body,
            'headers' => ['Content-Type' => $type],
        ]);
        if ($response->getStatusCode() !== 201) {
            throw new RuntimeException("Pushing {$repository}:{$reference} failed (HTTP {$response->getStatusCode()}): {$response->getBody()}");
        }
    }

    /**
     * @return list<string>
     */
    public function tags(string $registry, string $repository): array
    {
        $response = $this->http->get(RegistryClient::baseUrl($registry)."/v2/{$repository}/tags/list");
        $data = json_decode((string) $response->getBody(), true);
        $tags = is_array($data) && is_array($data['tags'] ?? null) ? $data['tags'] : [];
        sort($tags);

        return array_values(array_filter($tags, is_string(...)));
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $options
     */
    private function get(string $registry, string $repository, string $path, array $headers, array $options = []): ResponseInterface
    {
        $key = "{$registry}|{$repository}";
        $url = RegistryClient::baseUrl($registry)."/v2/{$repository}{$path}";
        $response = $this->http->get($url, [...$options, 'headers' => [...$headers, ...$this->authorization($key)]]);
        if ($response->getStatusCode() !== 401) {
            return $response;
        }

        preg_match_all('/(\w+)="([^"]*)"/', $response->getHeaderLine('WWW-Authenticate'), $pairs, PREG_SET_ORDER);
        $challenge = array_column($pairs, 2, 1);
        $token = $this->http->get((string) ($challenge['realm'] ?? ''), ['query' => [
            'service' => $challenge['service'] ?? '', 'scope' => "repository:{$repository}:pull",
        ]]);
        $data = json_decode((string) $token->getBody(), true);
        $value = is_array($data) ? ($data['token'] ?? $data['access_token'] ?? null) : null;
        if (! is_string($value)) {
            throw new RuntimeException("{$registry} refused an anonymous pull token for {$repository}.");
        }

        $this->authorizations[$key] = 'Bearer '.$value;

        return $this->http->get($url, [...$options, 'headers' => [...$headers, ...$this->authorization($key)]]);
    }

    /**
     * @return array<string, string>
     */
    private function authorization(string $key): array
    {
        return isset($this->authorizations[$key]) ? ['Authorization' => $this->authorizations[$key]] : [];
    }

    private function cachePath(string $digest): string
    {
        return $this->cache.'/'.str_replace(':', '-', $digest);
    }
}
