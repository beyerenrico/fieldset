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
 * major, nor its minor below the server's within the same major (ADR 0093, amendment 2026-11-13). The image runs once
 * per platform with pg_dump as its entry point; the server version comes from the pin's tag.
 */

const PG_DUMP_16 = 'pg_dump (PostgreSQL) 16.16 (Debian 16.16-1.pgdg12+1)';

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
    $runner = new ScriptedCommandRunner()->push(new ProcessOutcome(1, '', 'Error: No such image'), new ProcessOutcome(0, PG_DUMP_16."\n"), new ProcessOutcome(0), new ProcessOutcome(0, PG_DUMP_16."\n"));

    $results = new PgDumpCompatibility($runner)->check(ReleaseFixtures::manifest(), '127.0.0.1:5000');

    $image = '127.0.0.1:5000/beyerenrico/fieldset@'.ReleaseFixtures::APP_DIGEST;
    expect(array_column($results, 'platform'))->toBe(['linux/amd64', 'linux/arm64'])
        ->and(array_unique(array_column($results, 'client_major')))->toBe([16])
        ->and(array_unique(array_column($results, 'server_major')))->toBe([16])
        ->and($results[0]['pg_dump'])->toBe(PG_DUMP_16)
        ->and($runner->runs[1]['command'])->toBe(['docker', 'run', '--rm', '--platform', 'linux/amd64', '--network', 'none', '--entrypoint', 'pg_dump', $image, '--version'])
        ->and($runner->runs[3]['command'][4])->toBe('linux/arm64');
});

it('fails when the image\'s pg_dump major is below the manifest\'s Postgres major', function (): void {
    $runner = new ScriptedCommandRunner()->push(new ProcessOutcome(1, '', 'Error: No such image'), new ProcessOutcome(0, PG_DUMP_16));

    expect(fn () => new PgDumpCompatibility($runner)->check(manifestWithPostgres('17.2-alpine')))
        ->toThrow(ReleaseException::class, "The app image's pg_dump 16 (linux/amd64) is older than the manifest's Postgres 17");
});

it('compares the minor within the same major', function (string $client, string $tag, ?string $refusal): void {
    $runner = new ScriptedCommandRunner()->push(new ProcessOutcome(0, 'sha256:'.str_repeat('c', 64)), new ProcessOutcome(0, "pg_dump (PostgreSQL) {$client} (Debian {$client}-1.pgdg12+1)"));
    $check = fn (): array => new PgDumpCompatibility($runner)->check(manifestWithPostgres($tag), null, ['linux/amd64']);

    $refusal === null
        ? expect($check()[0])->toMatchArray(['client_major' => (int) $client, 'client_minor' => (int) explode('.', $client)[1], 'server_major' => 16])
        : expect($check)->toThrow(ReleaseException::class, $refusal);
})->with([
    'same minor' => ['16.15', '16.15-alpine', null],
    'newer minor' => ['16.16', '16.15-alpine', null],
    'older minor' => ['16.14', '16.15-alpine', "The app image's pg_dump 16.14 (linux/amd64) is older than the manifest's Postgres 16.15"],
    'newer major' => ['17.1', '16.15-alpine', null],
    'tag without a minor' => ['16.2', '16-alpine', null],
]);

it('accepts a newer client, checks only the platforms asked for and pulls from the image\'s own registry without a mirror', function (): void {
    $runner = new ScriptedCommandRunner()->push(new ProcessOutcome(1, '', 'Error: No such image'), new ProcessOutcome(0, 'pg_dump (PostgreSQL) 17.1'));

    $results = new PgDumpCompatibility($runner)->check(manifestWithPostgres('16'), null, ['linux/arm64']);

    expect($results)->toHaveCount(1)
        ->and($results[0])->toMatchArray(['platform' => 'linux/arm64', 'client_major' => 17, 'server_major' => 16])
        ->and($runner->runs)->toHaveCount(3)
        ->and($runner->runs[1]['command'][9])->toBe('ghcr.io/beyerenrico/fieldset@'.ReleaseFixtures::APP_DIGEST);
});

it('fails when the image cannot run or prints no pg_dump version', function (ProcessOutcome $outcome, string $message): void {
    $runner = new ScriptedCommandRunner()->push(new ProcessOutcome(1, '', 'Error: No such image'), $outcome);

    expect(fn () => new PgDumpCompatibility($runner)->check(ReleaseFixtures::manifest()))->toThrow(ReleaseException::class, $message);
})->with([
    'pull refused' => [new ProcessOutcome(125, '', 'Unable to find image'), 'Unable to find image'],
    'timed out' => [new ProcessOutcome(-1, '', '', true), 'timed out'],
    'no pg_dump' => [new ProcessOutcome(0, 'exec: pg_dump: not found'), 'printed no pg_dump version'],
]);

it('removes the by-digest image after each platform when it was not local before, so the next platform can pull it', function (): void {
    // Docker's classic image store keeps one platform per by-digest reference: arm64 cannot pull over the amd64 copy
    $runner = new ScriptedCommandRunner()->push(new ProcessOutcome(1, '', 'Error: No such image'), new ProcessOutcome(0, PG_DUMP_16), new ProcessOutcome(0), new ProcessOutcome(1, '', 'cannot overwrite digest'));

    expect(fn () => new PgDumpCompatibility($runner)->check(ReleaseFixtures::manifest(), '127.0.0.1:5000'))->toThrow(ReleaseException::class, 'cannot overwrite digest');

    $image = '127.0.0.1:5000/beyerenrico/fieldset@'.ReleaseFixtures::APP_DIGEST;
    expect(array_column($runner->runs, 'command'))->toBe([
        ['docker', 'image', 'inspect', '--format', '{{.Id}}', $image],
        ['docker', 'run', '--rm', '--platform', 'linux/amd64', '--network', 'none', '--entrypoint', 'pg_dump', $image, '--version'],
        ['docker', 'image', 'rm', $image],
        ['docker', 'run', '--rm', '--platform', 'linux/arm64', '--network', 'none', '--entrypoint', 'pg_dump', $image, '--version'],
        ['docker', 'image', 'rm', $image],
    ]);
});

it('keeps an image that was local before the check', function (): void {
    $runner = new ScriptedCommandRunner()->push(new ProcessOutcome(0, 'sha256:'.str_repeat('c', 64)), new ProcessOutcome(0, PG_DUMP_16));

    new PgDumpCompatibility($runner)->check(ReleaseFixtures::manifest(), null, ['linux/arm64']);

    expect($runner->runs)->toHaveCount(2)->and($runner->lastCommand()[1])->toBe('run');
});

it('reads the server major from the postgres tag', function (string $tag, ?int $major): void {
    $pin = new ImagePin('docker.io', 'library/postgres', $tag, 'sha256:'.str_repeat('b', 64));

    $major === null
        ? expect(fn () => PgDumpCompatibility::serverMajor($pin))->toThrow(ReleaseException::class, 'names no major version')
        : expect(PgDumpCompatibility::serverMajor($pin))->toBe($major);
})->with([
    ['16.15-alpine', 16], ['16-alpine', 16], ['16', 16], ['17.0', 17], ['alpine', null], ['latest', null],
]);

it('reads the server minor from the postgres tag and the client minor from pg_dump --version', function (): void {
    $pin = fn (string $tag): ImagePin => new ImagePin('docker.io', 'library/postgres', $tag, 'sha256:'.str_repeat('b', 64));

    expect(PgDumpCompatibility::serverMinor($pin('16.15-alpine')))->toBe(15)
        ->and(PgDumpCompatibility::serverMinor($pin('17.0')))->toBe(0)
        ->and(PgDumpCompatibility::serverMinor($pin('16-alpine')))->toBeNull()
        ->and(PgDumpCompatibility::serverMinor($pin('16')))->toBeNull()
        ->and(PgDumpCompatibility::clientMinor(PG_DUMP_16))->toBe(16)
        ->and(PgDumpCompatibility::clientMinor('pg_dump (PostgreSQL) 17devel'))->toBeNull();
});

it('reads the client major from pg_dump --version', function (): void {
    expect(PgDumpCompatibility::clientMajor(PG_DUMP_16))->toBe(16)
        ->and(PgDumpCompatibility::clientMajor("warning: something\npg_dump (PostgreSQL) 15.8"))->toBe(15)
        ->and(fn () => PgDumpCompatibility::clientMajor('psql (PostgreSQL) 16.1'))->toThrow(ReleaseException::class);
});

it('runs as release:check-pg-dump: JSON on success, exit 1 with the reason on a mismatch or an unreadable manifest', function (): void {
    $local = new ProcessOutcome(0, 'sha256:'.str_repeat('c', 64));
    $runner = new ScriptedCommandRunner()->push($local, new ProcessOutcome(0, PG_DUMP_16), new ProcessOutcome(0, PG_DUMP_16), $local, new ProcessOutcome(0, PG_DUMP_16));
    app()->instance(CommandRunner::class, $runner);
    $good = tempnam(sys_get_temp_dir(), 'manifest');
    $newer = tempnam(sys_get_temp_dir(), 'manifest');
    file_put_contents($good, ReleaseFixtures::manifest()->toJson());
    file_put_contents($newer, manifestWithPostgres('17.2-alpine')->toJson());

    try {
        $this->artisan('release:check-pg-dump', ['manifest' => $good, '--mirror' => '127.0.0.1:5000'])
            ->expectsOutputToContain('"client_major":16,"client_minor":16,"server_major":16,"server_minor":16')
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

    expect($runner->runs)->toHaveCount(5)
        ->and($runner->runs[4]['command'][4])->toBe('linux/amd64');
});
