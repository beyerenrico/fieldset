<?php

declare(strict_types=1);

use App\Domain\Instance\Release\ImageInspector;
use App\Domain\Instance\Release\ImagePin;
use App\Domain\Instance\Release\RegistryClient;
use App\Domain\Instance\Release\ReleaseException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Release\FakeRegistry;

/*
 * Registry reads for the release manifest (plan U7): manifests by digest, the token flow, digest checks, and
 * per-platform sizes from a multi-platform index.
 */

it('maps registries to their API endpoints', function (): void {
    expect(RegistryClient::baseUrl('docker.io'))->toBe('https://registry-1.docker.io')
        ->and(RegistryClient::baseUrl('ghcr.io'))->toBe('https://ghcr.io')
        ->and(RegistryClient::baseUrl('127.0.0.1:5000'))->toBe('http://127.0.0.1:5000')
        ->and(RegistryClient::baseUrl('localhost:5000'))->toBe('http://localhost:5000')
        ->and(RegistryClient::baseUrl('registry.example:5000'))->toBe('https://registry.example:5000');
});

it('reads a manifest anonymously and keeps its exact bytes', function (): void {
    $registry = new FakeRegistry;
    $digest = $registry->imageManifest('ghcr.io', 'owner/app');
    $registry->fake();

    $manifest = new RegistryClient()->manifest('ghcr.io', 'owner/app', $digest);

    expect($manifest->digest)->toBe($digest)
        ->and($manifest->isImage())->toBeTrue()
        ->and(FakeRegistry::digestOf($manifest->body))->toBe($digest);
    Http::assertSent(fn (Request $request): bool => str_contains($request->header('Accept')[0] ?? '', 'application/vnd.oci.image.index.v1+json'));
});

it('follows the bearer-token flow and sends credentials only to the token service', function (): void {
    $registry = new FakeRegistry(requireToken: true);
    $digest = $registry->imageManifest('ghcr.io', 'owner/app');
    $registry->fake();

    $client = new RegistryClient(['ghcr.io' => ['ci-user', 'ci-token']]);
    $client->manifest('ghcr.io', 'owner/app', $digest);
    $client->manifest('ghcr.io', 'owner/app', $digest);

    expect($registry->tokenRequests)->toHaveCount(1)
        ->and($registry->tokenRequests[0]->header('Authorization'))->toBe(['Basic '.base64_encode('ci-user:ci-token')])
        ->and($registry->tokenRequests[0]->url())->toContain('scope=repository%3Aowner%2Fapp%3Apull');
    Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer '.FakeRegistry::TOKEN]);
});

it('answers a basic challenge with the configured credentials', function (): void {
    $body = '{"schemaVersion":2,"mediaType":"application/vnd.oci.image.manifest.v1+json","config":{"size":1},"layers":[]}';
    $digest = FakeRegistry::digestOf($body);
    Http::fake(fn (Request $request) => $request->hasHeader('Authorization')
        ? Http::response($body, 200, ['Content-Type' => 'application/vnd.oci.image.manifest.v1+json; charset=utf-8'])
        : Http::response('', 401, ['WWW-Authenticate' => 'Basic realm="registry"']));

    $manifest = new RegistryClient(['registry.example' => ['u', 'p']])->manifest('registry.example', 'team/app', $digest);

    expect($manifest->mediaType)->toBe('application/vnd.oci.image.manifest.v1+json');
});

it('refuses access without a usable token service', function (string $challenge): void {
    Http::fake(fn () => Http::response('', 401, ['WWW-Authenticate' => $challenge]));

    expect(fn () => new RegistryClient()->manifest('ghcr.io', 'owner/app', 'sha256:'.str_repeat('a', 64)))
        ->toThrow(ReleaseException::class, 'offered no usable token service');
})->with(['Basic realm="registry"', 'Bearer realm="http://insecure.example/token"', '']);

it('fails when the token service refuses', function (): void {
    Http::fake([
        'auth.example/*' => Http::response(['details' => 'denied'], 403),
        '*' => Http::response('', 401, ['WWW-Authenticate' => 'Bearer realm="https://auth.example/token",service="x"']),
    ]);

    expect(fn () => new RegistryClient()->manifest('ghcr.io', 'owner/app', 'sha256:'.str_repeat('a', 64)))
        ->toThrow(ReleaseException::class, 'refused a pull token for owner/app (HTTP 403)');
});

it('fails on a missing manifest, an error answer or content that does not match the digest', function (): void {
    $digest = 'sha256:'.str_repeat('c', 64);
    Http::fake([
        '*/missing/*' => Http::response('', 404),
        '*/broken/*' => Http::response('', 500),
        '*/swapped/*' => Http::response('{"schemaVersion":2}', 200, ['Content-Type' => 'application/vnd.oci.image.index.v1+json']),
    ]);
    $client = new RegistryClient;

    expect(fn () => $client->manifest('docker.io', 'library/missing', $digest))->toThrow(ReleaseException::class, 'is not in the registry')
        ->and(fn () => $client->manifest('docker.io', 'library/broken', $digest))->toThrow(ReleaseException::class, 'answered HTTP 500')
        ->and(fn () => $client->manifest('docker.io', 'library/swapped', $digest))->toThrow(ReleaseException::class, 'does not match');
});

it('takes the media type from the body when the header is generic', function (): void {
    $body = '{"schemaVersion":2,"mediaType":"application/vnd.oci.image.index.v1+json","manifests":[]}';
    Http::fake(fn () => Http::response($body, 200, ['Content-Type' => 'application/json']));

    expect(new RegistryClient()->manifest('ghcr.io', 'owner/app', FakeRegistry::digestOf($body))->isIndex())->toBeTrue();
});

it('resolves the requested platforms of an index and skips attestation manifests', function (): void {
    $registry = new FakeRegistry;
    $digest = $registry->image('docker.io', 'library/postgres', ['linux/amd64' => [1_000, 50_000, 7], 'linux/arm64' => [900, 40_000]]);
    $registry->fake();

    $platforms = new ImageInspector(new RegistryClient)->inspect(new ImagePin('docker.io', 'library/postgres', '16-alpine', $digest), ['linux/arm64', 'linux/amd64']);

    expect(array_keys($platforms))->toBe(['linux/arm64', 'linux/amd64'])
        ->and($platforms['linux/amd64']->compressedSize)->toBe(51_007)
        ->and($platforms['linux/arm64']->compressedSize)->toBe(40_900)
        ->and($platforms['linux/arm64']->digest)->not->toBe($platforms['linux/amd64']->digest);
});

it('reads from the mirror instead of the image registry when one is given', function (): void {
    $registry = new FakeRegistry;
    $digest = $registry->image('127.0.0.1:5000', 'library/postgres');
    $registry->fake();

    $platforms = new ImageInspector(new RegistryClient)->inspect(new ImagePin('docker.io', 'library/postgres', '16', $digest), ['linux/amd64'], '127.0.0.1:5000');

    expect($platforms)->toHaveKey('linux/amd64');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'registry-1.docker.io'));
});

it('refuses images that lack a platform or are not multi-platform indexes', function (): void {
    $registry = new FakeRegistry;
    $amd64Only = $registry->image('docker.io', 'library/valkey', ['linux/amd64' => [1, 2]]);
    $single = $registry->imageManifest('docker.io', 'library/single');
    $nested = $registry->put('docker.io', 'library/nested', 'application/vnd.oci.image.index.v1+json', [
        'manifests' => [['digest' => $amd64Only, 'platform' => ['os' => 'linux', 'architecture' => 'amd64']]],
    ]);
    $sizeless = $registry->put('docker.io', 'library/sizeless', 'application/vnd.oci.image.index.v1+json', [
        'manifests' => [['digest' => $registry->put('docker.io', 'library/sizeless', 'application/vnd.oci.image.manifest.v1+json', ['config' => ['digest' => 'x'], 'layers' => []]), 'platform' => ['os' => 'linux', 'architecture' => 'amd64']]],
    ]);
    $registry->fake();
    $inspector = new ImageInspector(new RegistryClient);

    expect(fn () => $inspector->inspect(new ImagePin('docker.io', 'library/valkey', '8', $amd64Only), ['linux/amd64', 'linux/arm64']))
        ->toThrow(ReleaseException::class, 'has no linux/arm64 image')
        ->and(fn () => $inspector->inspect(new ImagePin('docker.io', 'library/single', '1', $single), ['linux/amd64']))
        ->toThrow(ReleaseException::class, 'is not a multi-platform image index')
        ->and(fn () => $inspector->inspect(new ImagePin('docker.io', 'library/nested', '1', $nested), ['linux/amd64']))
        ->toThrow(ReleaseException::class, 'is not in the registry')
        ->and(fn () => $inspector->inspect(new ImagePin('docker.io', 'library/sizeless', '1', $sizeless), ['linux/amd64']))
        ->toThrow(ReleaseException::class, 'lists a blob without a size');
});

it('refuses an index whose platform entry points at another index', function (): void {
    $registry = new FakeRegistry;
    $inner = $registry->image('docker.io', 'library/deep');
    $outer = $registry->put('docker.io', 'library/deep', 'application/vnd.oci.image.index.v1+json', [
        'manifests' => [['digest' => $inner, 'platform' => ['os' => 'linux', 'architecture' => 'amd64']]],
    ]);
    $registry->fake();

    expect(fn () => new ImageInspector(new RegistryClient)->inspect(new ImagePin('docker.io', 'library/deep', '1', $outer), ['linux/amd64']))
        ->toThrow(ReleaseException::class, 'is not an image manifest');
});
