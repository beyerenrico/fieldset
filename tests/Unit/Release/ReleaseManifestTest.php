<?php

declare(strict_types=1);

use App\Domain\Instance\Release\ImageInspector;
use App\Domain\Instance\Release\ManifestImage;
use App\Domain\Instance\Release\ManifestSchema;
use App\Domain\Instance\Release\PlatformImage;
use App\Domain\Instance\Release\RegistryClient;
use App\Domain\Instance\Release\ReleaseException;
use App\Domain\Instance\Release\ReleaseInputs;
use App\Domain\Instance\Release\ReleaseManifest;
use App\Domain\Instance\Release\ReleaseManifestBuilder;
use App\Domain\Instance\Release\ReleaseRequest;
use App\Domain\Instance\Release\ReleaseVersion;
use Tests\Support\Release\FakeRegistry;

/*
 * The release manifest (R2, R4, KTD22, plan U7): it validates against distribution/manifest.schema.json, lists
 * every service image by digest with per-platform sizes, carries asset checksums and no signer identity, and a
 * third-party digest the registry does not hold fails generation.
 */

function manifestBuilder(): ReleaseManifestBuilder
{
    return new ReleaseManifestBuilder(new ImageInspector(new RegistryClient), ManifestSchema::bundled());
}

/** @return list<string> the assets every release ships (U4) */
function requiredAssets(): array
{
    return [base_path('distribution/install.sh'), base_path('distribution/shim.sh')];
}

it('lists every service image by digest with per-platform sizes and validates against the schema', function (): void {
    $registry = new FakeRegistry;
    [$inputs, $appDigest] = $registry->inputs();
    $registry->fake();

    $manifest = manifestBuilder()->build(new ReleaseRequest(ReleaseVersion::parse('2.1.0-alpha.1'), $appDigest, $inputs, assets: requiredAssets()));
    $json = $manifest->toJson();
    $data = json_decode($json, true);

    expect(ManifestSchema::bundled()->errors($json))->toBe([])
        ->and(array_keys($data))->toBe(['schema_version', 'version', 'channel', 'notes_url', 'upgrades_from', 'platforms', 'images', 'assets'])
        ->and($data['schema_version'])->toBe(1)
        ->and($data['version'])->toBe('2.1.0-alpha.1')
        ->and($data['channel'])->toBe('alpha')
        ->and($data['upgrades_from'])->toBe('2.1.0-alpha.1')
        ->and($data['notes_url'])->toBe('https://github.com/beyerenrico/fieldset/releases/tag/v2.1.0-alpha.1')
        ->and($data['platforms'])->toBe(['linux/amd64', 'linux/arm64'])
        ->and(array_keys($data['images']))->toBe(ReleaseManifest::SERVICES)
        ->and($data['images']['app'])->toMatchArray([
            'registry' => 'ghcr.io', 'repository' => 'beyerenrico/fieldset', 'tag' => '2.1.0-alpha.1', 'digest' => $appDigest,
        ])
        ->and($data['images']['app']['platforms']['linux/amd64']['compressed_size'])->toBe(21_000)
        ->and($data['images']['postgres']['digest'])->toBe($inputs->images['postgres']->digest)
        ->and(array_keys($data['assets']))->toBe(['install.sh', 'shim.sh'])
        ->and($data['assets']['install.sh']['sha256'])->toBe(hash_file('sha256', base_path('distribution/install.sh')))
        ->and($json)->not->toContain('certificate-identity')
        ->and($json)->toEndWith("}\n");

    foreach ($data['images'] as $image) {
        expect($image['digest'])->toMatch('/^sha256:[a-f0-9]{64}$/')
            ->and(array_keys($image['platforms']))->toBe(['linux/amd64', 'linux/arm64']);
    }
});

it('renders the stack file from the same pins and lists it as an asset (R18, ADR 0076)', function (): void {
    $registry = new FakeRegistry;
    [$inputs, $appDigest] = $registry->inputs();
    $registry->fake();
    $stack = sys_get_temp_dir().'/release-stack-'.bin2hex(random_bytes(4)).'/compose.stack.yaml';
    mkdir(dirname($stack));

    try {
        $manifest = manifestBuilder()->build(new ReleaseRequest(ReleaseVersion::parse('2.1.0-alpha.2'), $appDigest, $inputs, assets: requiredAssets(), stackFile: $stack));
        $yaml = (string) file_get_contents($stack);
        preg_match_all('/^\s+image: "\$\{FIELDSET_REGISTRY:-([^}]+)\}\/(.+)"$/m', $yaml, $images, PREG_SET_ORDER);
        $pinned = array_map(fn (ManifestImage $image): string => $image->pin->pullReference(), $manifest->images);

        expect(array_keys($manifest->assets))->toBe(['compose.stack.yaml', 'install.sh', 'shim.sh'])
            ->and($manifest->assets['compose.stack.yaml']['sha256'])->toBe(hash('sha256', $yaml))
            ->and($yaml)->toContain('# form-schema-app 2.1.0-alpha.2 stack file')
            ->and($images)->toHaveCount(7);
        foreach ($images as [, $registryHost, $rest]) {
            expect($pinned)->toContain($registryHost.'/'.$rest);
        }
        expect(fn () => manifestBuilder()->build(new ReleaseRequest(ReleaseVersion::parse('2.1.0'), $appDigest, $inputs, assets: requiredAssets(), stackFile: '/nonexistent-dir/compose.stack.yaml')))
            ->toThrow(ReleaseException::class, 'Cannot write the stack file');
    } finally {
        @unlink($stack);
        rmdir(dirname($stack));
    }
});

it('checksums the release assets by file name', function (): void {
    $registry = new FakeRegistry;
    [$inputs, $appDigest] = $registry->inputs();
    $registry->fake();
    $directory = sys_get_temp_dir().'/release-assets-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents("{$directory}/shim.sh", "#!/bin/sh\n");
    file_put_contents("{$directory}/install.sh", "#!/bin/sh\necho install\n");

    try {
        $manifest = manifestBuilder()->build(new ReleaseRequest(
            ReleaseVersion::parse('2.1.0'), $appDigest, $inputs, assets: ["{$directory}/shim.sh", "{$directory}/install.sh"],
        ));

        expect($manifest->assets)->toBe([
            'install.sh' => ['sha256' => hash('sha256', "#!/bin/sh\necho install\n"), 'size' => 23],
            'shim.sh' => ['sha256' => hash('sha256', "#!/bin/sh\n"), 'size' => 10],
        ])
            ->and(fn () => manifestBuilder()->build(new ReleaseRequest(ReleaseVersion::parse('2.1.0'), $appDigest, $inputs, assets: ["{$directory}/missing.sh"])))
            ->toThrow(ReleaseException::class, 'Cannot read the release asset')
            ->and(fn () => manifestBuilder()->build(new ReleaseRequest(ReleaseVersion::parse('2.1.0'), $appDigest, $inputs, assets: ["{$directory}/shim.sh", "{$directory}/shim.sh"])))
            ->toThrow(ReleaseException::class, 'Two release assets are named "shim.sh"');
    } finally {
        array_map(unlink(...), glob("{$directory}/*") ?: []);
        rmdir($directory);
    }
});

it('fails when the registry does not hold a pinned third-party digest', function (): void {
    $registry = new FakeRegistry;
    [$data, $appDigest] = $registry->releaseInputs();
    $data['images']['mailpit'] = 'docker.io/axllent/mailpit:v1.31.6@sha256:'.str_repeat('d', 64);
    $registry->fake();

    expect(fn () => manifestBuilder()->build(new ReleaseRequest(ReleaseVersion::parse('2.1.0'), $appDigest, ReleaseInputs::fromArray($data), assets: requiredAssets())))
        ->toThrow(ReleaseException::class, 'docker.io/axllent/mailpit@sha256:'.str_repeat('d', 64).' is not in the registry');
});

it('lists only the requested platforms and reads a local mirror for the harness', function (): void {
    $registry = new FakeRegistry;
    [$inputs, $appDigest] = $registry->inputs('127.0.0.1:5000', ['linux/arm64' => [5, 10]]);
    $registry->fake();

    $manifest = manifestBuilder()->build(new ReleaseRequest(
        ReleaseVersion::parse('2.1.0-alpha.1-test'), $appDigest, $inputs, ['linux/arm64'], requiredAssets(), '127.0.0.1:5000',
    ));

    expect($manifest->platforms)->toBe(['linux/arm64'])
        ->and($manifest->images['app']->pin->registry)->toBe('ghcr.io')
        ->and($manifest->images['caddy']->pin->registry)->toBe('docker.io')
        ->and($manifest->images['caddy']->platforms['linux/arm64']->compressedSize)->toBe(15)
        ->and(fn () => manifestBuilder()->build(new ReleaseRequest(ReleaseVersion::parse('2.1.0'), $appDigest, $inputs, ['linux/arm64'], requiredAssets(), 'not a registry')))
        ->toThrow(ReleaseException::class, 'Invalid image registry');
});

it('honours an upgrade-source override but never one newer than the release', function (): void {
    $registry = new FakeRegistry;
    [$inputs, $appDigest] = $registry->inputs();
    $registry->fake();
    $request = fn (string $version, ?string $from): ReleaseRequest => new ReleaseRequest(
        ReleaseVersion::parse($version), $appDigest, $inputs, assets: requiredAssets(), upgradesFrom: $from === null ? null : ReleaseVersion::parse($from),
    );

    expect((string) manifestBuilder()->build($request('2.1.0', '2.0.0'))->upgradesFrom)->toBe('2.0.0')
        ->and(fn () => manifestBuilder()->build($request('2.1.0-alpha.0', null)))
        ->toThrow(ReleaseException::class, 'Release 2.1.0-alpha.0 cannot upgrade from the newer version 2.1.0-alpha.1');
});

it('round-trips through JSON after a schema check', function (): void {
    $registry = new FakeRegistry;
    [$inputs, $appDigest] = $registry->inputs();
    $registry->fake();
    $manifest = manifestBuilder()->build(new ReleaseRequest(ReleaseVersion::parse('2.1.0-rc.1'), $appDigest, $inputs, assets: requiredAssets()));

    $parsed = ReleaseManifest::fromJson($manifest->toJson(), ManifestSchema::bundled());

    expect($parsed->toJson())->toBe($manifest->toJson())
        ->and($parsed->images['valkey']->pin->pullReference('127.0.0.1:5000'))->toStartWith('127.0.0.1:5000/valkey/valkey@sha256:');
});

it('rejects manifests that break the schema', function (Closure $tamper, string $pointer): void {
    $registry = new FakeRegistry;
    [$inputs, $appDigest] = $registry->inputs();
    $registry->fake();
    $data = manifestBuilder()->build(new ReleaseRequest(ReleaseVersion::parse('2.1.0'), $appDigest, $inputs, assets: requiredAssets()))->toArray();
    $json = json_encode($tamper($data), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    expect(implode("\n", ManifestSchema::bundled()->errors($json)))->toContain($pointer)
        ->and(fn () => ReleaseManifest::fromJson($json, ManifestSchema::bundled()))->toThrow(ReleaseException::class, 'does not match its schema');
})->with([
    'missing service' => [function (array $data): array {
        unset($data['images']['cloudflared']);

        return $data;
    }, '/images'],
    'tag instead of digest' => [fn (array $data): array => array_replace_recursive($data, ['images' => ['app' => ['digest' => 'latest']]]), '/images/app/digest'],
    'unknown platform' => [fn (array $data): array => array_replace_recursive($data, ['platforms' => ['linux/s390x']]), '/platforms/0'],
    'zero size' => [fn (array $data): array => array_replace_recursive($data, ['images' => ['gotenberg' => ['platforms' => ['linux/amd64' => ['compressed_size' => 0]]]]]), '/images/gotenberg/platforms/linux~1amd64/compressed_size'],
    'unknown channel' => [fn (array $data): array => [...$data, 'channel' => 'nightly'], '/channel'],
    'newer schema version' => [fn (array $data): array => [...$data, 'schema_version' => 2], '/schema_version'],
    'http notes url' => [fn (array $data): array => [...$data, 'notes_url' => 'http://example.com'], '/notes_url'],
    'bad asset name' => [fn (array $data): array => [...$data, 'assets' => [...$data['assets'], '../install.sh' => ['sha256' => str_repeat('a', 64), 'size' => 1]]], '/assets'],
    'no install.sh' => [function (array $data): array {
        unset($data['assets']['install.sh']);

        return $data;
    }, '/assets'],
]);

it('reports invalid JSON as a schema error', function (): void {
    expect(ManifestSchema::bundled()->errors('{'))->toBe(['/: the manifest is not valid JSON']);
});

it('keeps its invariants when built directly', function (): void {
    $digest = 'sha256:'.str_repeat('e', 64);
    $image = fn (array $platforms): ManifestImage => new ManifestImage(
        ReleaseInputs::fromFile(base_path('distribution/release.json'))->images['postgres'],
        array_combine($platforms, array_map(fn (string $platform): PlatformImage => new PlatformImage($platform, $digest, 1), $platforms)),
    );
    $images = array_fill_keys(ReleaseManifest::SERVICES, $image(['linux/amd64']));
    $assets = array_fill_keys(ReleaseManifest::REQUIRED_ASSETS, ['sha256' => str_repeat('a', 64), 'size' => 1]);
    $make = fn (array $overrides): ReleaseManifest => new ReleaseManifest(...[
        'version' => ReleaseVersion::parse('2.0.0'), 'upgradesFrom' => ReleaseVersion::parse('2.0.0'),
        'notesUrl' => 'https://example.com/notes', 'platforms' => ['linux/amd64'], 'images' => $images, 'assets' => $assets, ...$overrides,
    ]);

    expect($make([])->channel()->value)->toBe('stable')
        ->and(fn () => $make(['notesUrl' => 'http://example.com']))->toThrow(ReleaseException::class, 'must use https')
        ->and(fn () => $make(['platforms' => []]))->toThrow(ReleaseException::class, 'distinct platforms')
        ->and(fn () => $make(['platforms' => ['linux/amd64', 'linux/amd64']]))->toThrow(ReleaseException::class, 'distinct platforms')
        ->and(fn () => $make(['platforms' => ['linux/riscv64']]))->toThrow(ReleaseException::class, 'Unsupported platform')
        ->and(fn () => $make(['images' => array_slice($images, 0, 3)]))->toThrow(ReleaseException::class, 'lists no image for the service "gotenberg"')
        ->and(fn () => $make(['images' => [...$images, 'caddy' => $image(['linux/amd64', 'linux/arm64'])]]))->toThrow(ReleaseException::class, 'The "caddy" image must list exactly')
        ->and(fn () => $make(['assets' => [...$assets, 'install.sh' => ['sha256' => 'abc', 'size' => 1]]]))->toThrow(ReleaseException::class, 'Invalid asset entry')
        ->and(fn () => $make(['assets' => [...$assets, 'install.sh' => ['sha256' => str_repeat('a', 64)."\n", 'size' => 1]]]))->toThrow(ReleaseException::class, 'Invalid asset entry')
        ->and(fn () => $make(['assets' => [...$assets, "notes.txt\n" => ['sha256' => str_repeat('a', 64), 'size' => 1]]]))->toThrow(ReleaseException::class, 'Invalid asset entry')
        ->and(fn () => $make(['assets' => ['install.sh' => $assets['install.sh']]]))->toThrow(ReleaseException::class, 'A release ships the asset "shim.sh"')
        ->and(fn () => new PlatformImage('linux/s390x', $digest, 1))->toThrow(ReleaseException::class, 'Unsupported platform')
        ->and(fn () => new PlatformImage('linux/amd64', $digest, 0))->toThrow(ReleaseException::class, 'size must be positive');
});
