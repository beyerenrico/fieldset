<?php

declare(strict_types=1);

use App\Domain\Instance\Release\ImagePin;
use App\Domain\Instance\Release\ReleaseException;
use App\Domain\Instance\Release\ReleaseInputs;
use App\Domain\Instance\Release\ReleaseVersion;

/*
 * The committed release inputs (distribution/release.json): every third-party image is pinned by digest and
 * refreshed only by a deliberate commit (R4); a missing service or digest fails generation (plan U7).
 */

function releaseInputs(array $overrides = []): array
{
    $data = json_decode((string) file_get_contents(base_path('distribution/release.json')), true);

    return array_replace($data, $overrides);
}

it('pins every third-party service of the committed inputs by digest', function (): void {
    $inputs = ReleaseInputs::fromFile(base_path('distribution/release.json'));

    expect(array_keys($inputs->images))->toBe(ReleaseInputs::THIRD_PARTY_SERVICES)
        ->and($inputs->appRegistry)->toBe('ghcr.io')
        ->and($inputs->appRepository)->toBe('beyerenrico/form-schema-app')
        ->and($inputs->images['postgres']->repository)->toBe('library/postgres')
        ->and($inputs->images['postgres']->tag)->toStartWith('16.')
        ->and($inputs->images['postgres']->tag)->toEndWith('-alpine')
        ->and($inputs->notesUrl(ReleaseVersion::parse('2.0.0')))->toBe('https://github.com/beyerenrico/form-schema-app/releases/tag/v2.0.0');

    foreach ($inputs->images as $pin) {
        expect($pin->digest)->toMatch('/^sha256:[a-f0-9]{64}$/');
    }
});

it('builds the app pin from the version and the pushed digest', function (): void {
    $digest = 'sha256:'.str_repeat('a', 64);
    $pin = ReleaseInputs::fromArray(releaseInputs())->appImage(ReleaseVersion::parse('2.0.0-rc.1'), $digest);

    expect($pin->reference())->toBe("ghcr.io/beyerenrico/form-schema-app:2.0.0-rc.1@{$digest}")
        ->and($pin->pullReference())->toBe("ghcr.io/beyerenrico/form-schema-app@{$digest}")
        ->and($pin->pullReference('127.0.0.1:5000'))->toBe("127.0.0.1:5000/beyerenrico/form-schema-app@{$digest}");
});

it('fails when a third-party service has no pin', function (): void {
    $data = releaseInputs();
    unset($data['images']['cloudflared']);

    expect(fn () => ReleaseInputs::fromArray($data))->toThrow(ReleaseException::class, 'pins no image for the third-party service "cloudflared"');
});

it('fails when a third-party pin has no digest', function (): void {
    $data = releaseInputs();
    $data['images']['caddy'] = 'docker.io/library/caddy:2.11.7-alpine';

    expect(fn () => ReleaseInputs::fromArray($data))->toThrow(ReleaseException::class, 'is not pinned by digest');
});

it('rejects malformed inputs', function (array $overrides, string $message): void {
    expect(fn () => ReleaseInputs::fromArray(releaseInputs($overrides)))->toThrow(ReleaseException::class, $message);
})->with([
    'schema version' => [['schema_version' => 2], 'schema_version must be 1'],
    'app image with a tag' => [['app_image' => 'ghcr.io/beyerenrico/form-schema-app:latest'], 'app_image must be registry/repository'],
    'app image without registry' => [['app_image' => 'form-schema-app'], 'app_image must be registry/repository'],
    'notes url over http' => [['notes_url' => 'http://example.com/{version}'], 'notes_url must be an https URL'],
    'notes url without version' => [['notes_url' => 'https://example.com/notes'], 'notes_url must be an https URL'],
    'missing upgrade source' => [['upgrades_from' => ''], '"upgrades_from" must be a non-empty string'],
    'invalid upgrade source' => [['upgrades_from' => '2.0'], 'Invalid release version'],
]);

it('reads only existing JSON files', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'release-inputs');
    file_put_contents($file, '{not json');

    try {
        expect(fn () => ReleaseInputs::fromFile($file))->toThrow(ReleaseException::class, 'not valid JSON')
            ->and(fn () => ReleaseInputs::fromFile($file.'.missing'))->toThrow(ReleaseException::class, 'Cannot read');
    } finally {
        unlink($file);
    }
});

it('parses only fully qualified image references', function (string $reference, string $message): void {
    expect(fn () => ImagePin::parse($reference))->toThrow(ReleaseException::class, $message);
})->with([
    ['postgres:16@sha256:'.str_repeat('a', 64), 'must be fully qualified'],
    ['docker.io/library/postgres@sha256:'.str_repeat('a', 64), 'must be fully qualified'],
    ['docker.io/library/postgres:16@sha256:abc', 'must look like sha256:<64 hex>'],
    ['docker.io/Library/postgres:16@sha256:'.str_repeat('a', 64), 'Invalid image repository'],
    ['docker.io/library/postgres:-16@sha256:'.str_repeat('a', 64), 'Invalid image tag'],
    ['Docker.io/library/postgres:16@sha256:'.str_repeat('a', 64), 'Invalid image registry'],
]);

it('accepts registries with ports, the local harness included', function (): void {
    $pin = ImagePin::parse('127.0.0.1:5000/library/postgres:16.15-alpine@sha256:'.str_repeat('b', 64));

    expect($pin->registry)->toBe('127.0.0.1:5000')
        ->and($pin->name())->toBe('127.0.0.1:5000/library/postgres')
        ->and($pin->tag)->toBe('16.15-alpine');
});

it('uses the same image patterns as the manifest schema', function (): void {
    $schema = json_decode((string) file_get_contents(base_path('distribution/manifest.schema.json')), true);
    $image = $schema['$defs']['image']['properties'];

    expect($image['registry']['pattern'])->toBe(ImagePin::REGISTRY_PATTERN)
        ->and($image['repository']['pattern'])->toBe(ImagePin::REPOSITORY_PATTERN)
        ->and($image['tag']['pattern'])->toBe(ImagePin::TAG_PATTERN)
        ->and($schema['$defs']['digest']['pattern'])->toBe(ImagePin::DIGEST_PATTERN);
});
