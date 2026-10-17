<?php

declare(strict_types=1);

namespace Tests\Support\Release;

use App\Domain\Instance\Release\ImagePin;
use App\Domain\Instance\Release\RegistryClient;
use App\Domain\Instance\Release\ReleaseInputs;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * An OCI registry behind Http::fake for release unit tests: multi-platform indexes (with buildx-style
 * attestation manifests), single-image manifests and, optionally, the bearer-token flow. Bodies are real JSON
 * whose sha256 is the digest, so the client's digest check runs for real.
 */
final class FakeRegistry
{
    public const string REALM = 'https://auth.registry.test/token';

    public const string TOKEN = 'fake-pull-token';

    /** @var array<string, array{type: string, body: string}> manifest URL => answer */
    private array $manifests = [];

    /** @var list<Request> */
    public array $tokenRequests = [];

    public function __construct(private readonly bool $requireToken = false) {}

    /**
     * Adds a multi-platform index and returns its digest.
     *
     * @param  array<string, list<int>>  $platforms  "linux/arm64" => [config size, layer size, …]
     */
    public function image(string $registry, string $repository, array $platforms = ['linux/amd64' => [1_000, 20_000], 'linux/arm64' => [900, 18_000]]): string
    {
        $entries = [];
        foreach ($platforms as $platform => $sizes) {
            [$os, $architecture] = explode('/', $platform, 2);
            $digest = $this->imageManifest($registry, $repository, $sizes);
            $entries[] = ['mediaType' => 'application/vnd.oci.image.manifest.v1+json', 'digest' => $digest, 'size' => 500, 'platform' => ['architecture' => $architecture, 'os' => $os]];
            $entries[] = [
                'mediaType' => 'application/vnd.oci.image.manifest.v1+json', 'digest' => $this->imageManifest($registry, $repository, [10, 20]), 'size' => 300,
                'annotations' => ['vnd.docker.reference.digest' => $digest, 'vnd.docker.reference.type' => 'attestation-manifest'],
                'platform' => ['architecture' => 'unknown', 'os' => 'unknown'],
            ];
        }

        return $this->put($registry, $repository, 'application/vnd.oci.image.index.v1+json', [
            'schemaVersion' => 2, 'mediaType' => 'application/vnd.oci.image.index.v1+json', 'manifests' => $entries,
        ]);
    }

    /**
     * Adds a single-platform image manifest and returns its digest.
     *
     * @param  list<int>  $sizes  config size, then layer sizes
     */
    public function imageManifest(string $registry, string $repository, array $sizes = [1_000, 20_000]): string
    {
        $config = array_shift($sizes) ?? 0;

        return $this->put($registry, $repository, 'application/vnd.oci.image.manifest.v1+json', [
            'schemaVersion' => 2,
            'mediaType' => 'application/vnd.oci.image.manifest.v1+json',
            'config' => ['mediaType' => 'application/vnd.oci.image.config.v1+json', 'digest' => self::digestOf((string) $config), 'size' => $config],
            'layers' => array_map(static fn (int $size): array => [
                'mediaType' => 'application/vnd.oci.image.layer.v1.tar+gzip', 'digest' => self::digestOf((string) $size), 'size' => $size,
            ], $sizes),
        ]);
    }

    /**
     * Stores an arbitrary manifest body and returns its digest.
     *
     * @param  array<mixed>  $document
     */
    public function put(string $registry, string $repository, string $mediaType, array $document): string
    {
        $body = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $digest = self::digestOf($body);
        $this->manifests[RegistryClient::baseUrl($registry)."/v2/{$repository}/manifests/{$digest}"] = ['type' => $mediaType, 'body' => $body];

        return $digest;
    }

    /**
     * The committed release inputs with every third-party pin replaced by an image this registry holds (at the
     * pin's own registry, or at the mirror), plus the digest of a pushed app image.
     *
     * @param  array<string, list<int>>  $platforms
     * @return array{0: array<string, mixed>, 1: string} [release.json data, app digest]
     */
    public function releaseInputs(?string $mirror = null, array $platforms = ['linux/amd64' => [1_000, 20_000], 'linux/arm64' => [900, 18_000]]): array
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/distribution/release.json'), true);
        foreach ($data['images'] as $service => $reference) {
            $pin = ImagePin::parse($reference);
            $data['images'][$service] = "{$pin->name()}:{$pin->tag}@".$this->image($mirror ?? $pin->registry, $pin->repository, $platforms);
        }

        return [$data, $this->image($mirror ?? 'ghcr.io', 'beyerenrico/form-schema-app', $platforms)];
    }

    /**
     * @param  array<string, list<int>>  $platforms
     * @return array{0: ReleaseInputs, 1: string}
     */
    public function inputs(?string $mirror = null, array $platforms = ['linux/amd64' => [1_000, 20_000], 'linux/arm64' => [900, 18_000]]): array
    {
        [$data, $digest] = $this->releaseInputs($mirror, $platforms);

        return [ReleaseInputs::fromArray($data), $digest];
    }

    public function fake(): void
    {
        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), self::REALM)) {
                $this->tokenRequests[] = $request;

                return Http::response(['token' => self::TOKEN]);
            }

            if ($this->requireToken && $request->header('Authorization') !== ['Bearer '.self::TOKEN]) {
                return Http::response(['errors' => [['code' => 'UNAUTHORIZED']]], 401, [
                    'WWW-Authenticate' => 'Bearer realm="'.self::REALM.'",service="registry.test",scope="repository:x:pull"',
                ]);
            }

            $answer = $this->manifests[$request->url()] ?? null;

            return $answer === null
                ? Http::response(['errors' => [['code' => 'MANIFEST_UNKNOWN']]], 404)
                : Http::response($answer['body'], 200, ['Content-Type' => $answer['type']]);
        });
    }

    public static function digestOf(string $body): string
    {
        return 'sha256:'.hash('sha256', $body);
    }
}
