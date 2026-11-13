<?php

declare(strict_types=1);

use App\Domain\Instance\Release\PgDumpCompatibility;
use App\Domain\Instance\Release\ReleaseInputs;

/*
 * One Postgres server everywhere (ADR 0093, amendment 2026-11-13): the dev stack and every workflow service run the
 * image `distribution/release.json` pins for instances, tag and digest, and the image's client is not older than it.
 * A floating `postgres:16-alpine` moved to 16.16 on 2026-11-12 and broke restores into the pinned 16.15.
 */

/** @return list<string> every `image:` in the file that is a Postgres server */
function postgresImages(string $path): array
{
    preg_match_all('#^\s*image:\s*["\']?((?:docker\.io/)?(?:library/)?postgres[:@][^\s"\']+)#m', (string) file_get_contents(base_path($path)), $matches);

    return $matches[1];
}

it('runs the manifest\'s Postgres image, by tag and digest, in the dev stack and the workflows', function (string $path, int $services): void {
    $pin = ReleaseInputs::fromFile(base_path('distribution/release.json'))->images['postgres'];

    expect($pin->tag)->toMatch('/^\d+\.\d+-alpine$/')
        ->and(postgresImages($path))->toBe(array_fill(0, $services, $pin->reference()));
})->with([
    ['compose.yaml', 1],
    ['.github/workflows/ci.yml', 2],
    ['.github/workflows/docs.yml', 1],
]);

it('ships a client that is not older than the manifest\'s server', function (): void {
    $pin = ReleaseInputs::fromFile(base_path('distribution/release.json'))->images['postgres'];
    $inventory = json_decode((string) file_get_contents(base_path('docker/app/binaries.json')), true, flags: JSON_THROW_ON_ERROR);
    $client = array_column($inventory['binaries'], 'version', 'name')['postgresql-client-16'];

    [$major, $minor] = array_map(intval(...), explode('.', (string) $client));

    expect($major)->toBe(PgDumpCompatibility::serverMajor($pin))
        ->and($minor)->toBeGreaterThanOrEqual(PgDumpCompatibility::serverMinor($pin));
});
