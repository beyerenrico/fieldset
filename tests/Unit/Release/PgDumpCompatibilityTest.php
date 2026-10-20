<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\CommandRunner;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Release\ImagePin;
use App\Domain\Instance\Release\ManifestImage;
use App\Domain\Instance\Release\PgDumpCompatibility;
use App\Domain\Instance\Release\ReleaseException;
use App\Domain\Instance\Release\ReleaseManifest;
use Tests\Support\Instance\ScriptedCommandRunner;
use Tests\Support\Release\ReleaseFixtures;

/*
 * R39's release check (plan U22, ADR 0091): the app image's pg_dump major must not be below the manifest's Postgres
 * major. The image runs once per platform with pg_dump as its entry point; the server major comes from the pin's tag.
 */

const PG_DUMP_16 = 'pg_dump (PostgreSQL) 16.10 (Debian 16.10-1.pgdg13+1)';

/** The fixture manifest with another postgres tag (digest unchanged). */
function manifestWithPostgres(string $tag): ReleaseManifest
{
    $manifest = ReleaseFixtures::manifest();
    $postgres = $manifest->images['postgres'];
    $pin = new ImagePin($postgres->pin->registry, $postgres->pin->repository, $tag, $postgres->pin->digest);

    return new ReleaseManifest($manifest->version, $manifest->upgradesFrom, $manifest->notesUrl, $manifest->platforms,
        [...$manifest->images, 'postgres' => new ManifestImage($pin, $postgres->platforms)], $manifest->assets);
}

it('passes when the image\'s pg_dump major equals the server major, running it once per platform without network', function (): void {
    $runner = new ScriptedCommandRunner()->push(new ProcessOutcome(0, PG_DUMP_16."\n"), new ProcessOutcome(0, PG_DUMP_16."\n"));

    $results = new PgDumpCompatibility($runner)->check(ReleaseFixtures::manifest(), '127.0.0.1:5000');

    $image = '127.0.0.1:5000/beyerenrico/form-schema-app@'.ReleaseFixtures::APP_DIGEST;
    expect(array_column($results, 'platform'))->toBe(['linux/amd64', 'linux/arm64'])
        ->and(array_unique(array_column($results, 'client_major')))->toBe([16])
        ->and(array_unique(array_column($results, 'server_major')))->toBe([16])
        ->and($results[0]['pg_dump'])->toBe(PG_DUMP_16)
        ->and($runner->runs[0]['command'])->toBe(['docker', 'run', '--rm', '--platform', 'linux/amd64', '--network', 'none', '--entrypoint', 'pg_dump', $image, '--version'])
        ->and($runner->runs[1]['command'][4])->toBe('linux/arm64');
});

it('fails when the image\'s pg_dump major is below the manifest\'s Postgres major', function (): void {
    $runner = new ScriptedCommandRunner()->push(new ProcessOutcome(0, PG_DUMP_16));

    expect(fn () => new PgDumpCompatibility($runner)->check(manifestWithPostgres('17.2-alpine')))
        ->toThrow(ReleaseException::class, "The app image's pg_dump 16 (linux/amd64) is older than the manifest's Postgres 17");
});

it('accepts a newer client, checks only the platforms asked for and pulls from the image\'s own registry without a mirror', function (): void {
    $runner = new ScriptedCommandRunner()->push(new ProcessOutcome(0, 'pg_dump (PostgreSQL) 17.1'));

    $results = new PgDumpCompatibility($runner)->check(manifestWithPostgres('16'), null, ['linux/arm64']);

    expect($results)->toHaveCount(1)
        ->and($results[0])->toMatchArray(['platform' => 'linux/arm64', 'client_major' => 17, 'server_major' => 16])
        ->and($runner->runs)->toHaveCount(1)
        ->and($runner->runs[0]['command'][9])->toBe('ghcr.io/beyerenrico/form-schema-app@'.ReleaseFixtures::APP_DIGEST);
});

it('fails when the image cannot run or prints no pg_dump version', function (ProcessOutcome $outcome, string $message): void {
    $runner = new ScriptedCommandRunner()->push($outcome);

    expect(fn () => new PgDumpCompatibility($runner)->check(ReleaseFixtures::manifest()))->toThrow(ReleaseException::class, $message);
})->with([
    'pull refused' => [new ProcessOutcome(125, '', 'Unable to find image'), 'Unable to find image'],
    'timed out' => [new ProcessOutcome(-1, '', '', true), 'timed out'],
    'no pg_dump' => [new ProcessOutcome(0, 'exec: pg_dump: not found'), 'printed no pg_dump version'],
]);

it('reads the server major from the postgres tag', function (string $tag, ?int $major): void {
    $pin = new ImagePin('docker.io', 'library/postgres', $tag, 'sha256:'.str_repeat('b', 64));

    $major === null
        ? expect(fn () => PgDumpCompatibility::serverMajor($pin))->toThrow(ReleaseException::class, 'names no major version')
        : expect(PgDumpCompatibility::serverMajor($pin))->toBe($major);
})->with([
    ['16.15-alpine', 16], ['16-alpine', 16], ['16', 16], ['17.0', 17], ['alpine', null], ['latest', null],
]);

it('reads the client major from pg_dump --version', function (): void {
    expect(PgDumpCompatibility::clientMajor(PG_DUMP_16))->toBe(16)
        ->and(PgDumpCompatibility::clientMajor("warning: something\npg_dump (PostgreSQL) 15.8"))->toBe(15)
        ->and(fn () => PgDumpCompatibility::clientMajor('psql (PostgreSQL) 16.1'))->toThrow(ReleaseException::class);
});

it('runs as release:check-pg-dump: JSON on success, exit 1 with the reason on a mismatch or an unreadable manifest', function (): void {
    $runner = new ScriptedCommandRunner()->push(new ProcessOutcome(0, PG_DUMP_16), new ProcessOutcome(0, PG_DUMP_16), new ProcessOutcome(0, PG_DUMP_16));
    app()->instance(CommandRunner::class, $runner);
    $good = tempnam(sys_get_temp_dir(), 'manifest');
    $newer = tempnam(sys_get_temp_dir(), 'manifest');
    file_put_contents($good, ReleaseFixtures::manifest()->toJson());
    file_put_contents($newer, manifestWithPostgres('17.2-alpine')->toJson());

    try {
        $this->artisan('release:check-pg-dump', ['manifest' => $good, '--mirror' => '127.0.0.1:5000'])
            ->expectsOutputToContain('"client_major":16,"server_major":16')
            ->assertSuccessful();
        $this->artisan('release:check-pg-dump', ['manifest' => $newer, '--platform' => ['linux/amd64']])
            ->expectsOutputToContain('older than the manifest')
            ->assertFailed();
        $this->artisan('release:check-pg-dump', ['manifest' => $good.'.missing'])
            ->expectsOutputToContain('Cannot read the manifest')
            ->assertFailed();
    } finally {
        @unlink($good);
        @unlink($newer);
    }

    expect($runner->runs)->toHaveCount(3)
        ->and($runner->runs[2]['command'][4])->toBe('linux/amd64');
});
