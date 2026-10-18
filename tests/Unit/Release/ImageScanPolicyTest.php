<?php

declare(strict_types=1);

use App\Domain\Instance\Release\ReleaseInputs;
use Carbon\CarbonImmutable;
use Tests\Support\Docker\TrivyIgnoreFile;

/*
 * Image scan policy (ADR 0071): the app image is scanned strictly, with no exception file; a third-party image may
 * carry dated exceptions scoped to the component, one entry per finding, each with a statement and an expiry at
 * most 90 days out. Trivy stops honouring an entry once it has expired, so the release scan fails again.
 */

/** @return list<string> */
function trivyExceptionFiles(): array
{
    return glob(dirname(__DIR__, 3).'/distribution/trivy/*.trivyignore.yaml') ?: [];
}

it('keeps exception files only for third-party services of the release, never for the app image', function (): void {
    $services = array_map(fn (string $file): string => basename($file, '.trivyignore.yaml'), trivyExceptionFiles());

    expect($services)->toEqualCanonicalizing(['caddy', 'cloudflared', 'gotenberg', 'postgres'])
        ->and(array_diff($services, ReleaseInputs::THIRD_PARTY_SERVICES))->toBe([])
        ->and(glob(base_path('distribution/trivy/*')))->toBe(trivyExceptionFiles())
        ->and(glob(base_path('distribution/trivy/.*ignore*')))->toBe([]);

    foreach (['', 'docker/app/', 'distribution/'] as $directory) {
        foreach (['.trivyignore', '.trivyignore.yaml', 'app.trivyignore.yaml'] as $name) {
            expect(file_exists(base_path($directory.$name)))->toBeFalse("{$directory}{$name} must not exist");
        }
    }
});

it('gives every exception an id, a scope, a statement and an expiry at most 90 days out', function (string $file): void {
    $limit = CarbonImmutable::today('UTC')->addDays(90);
    $entries = TrivyIgnoreFile::fromFile($file)->entries;

    expect($entries)->not->toBeEmpty()
        ->and(array_unique(array_column($entries, 'id')))->toHaveCount(count($entries));

    foreach ($entries as $entry) {
        $label = basename($file).' '.$entry['id'];

        expect($entry['id'])->toMatch('/^(CVE-\d{4}-\d{4,}|GHSA(-[23456789cfghjmpqrvwx]{4}){3})$/', $label)
            ->and([...$entry['paths'], ...$entry['purls']])->not->toBeEmpty($label.' has no scope')
            ->and(strlen((string) $entry['statement']))->toBeGreaterThan(60, $label.' needs a statement')
            ->and($entry['expired_at'])->not->toBeNull($label.' needs expired_at')
            ->and(CarbonImmutable::parse((string) $entry['expired_at'], 'UTC')->lessThanOrEqualTo($limit))
            ->toBeTrue("{$label} expires more than 90 days out");

        foreach ($entry['paths'] as $path) {
            expect($path)->toMatch('#^[a-z0-9][\w./+-]*$#', "{$label}: a path names one file, relative, without wildcards");
        }

        foreach ($entry['purls'] as $purl) {
            expect($purl)->toMatch('#^pkg:[a-z]+/[\w./+-]+$#', "{$label}: a purl names one package, without version or qualifiers");
        }
    }
})->with(fn (): array => array_combine(array_map(basename(...), trivyExceptionFiles()), array_map(fn (string $file): array => [$file], trivyExceptionFiles())));

it('rejects an exception file outside the layout it can read', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'trivyignore');
    file_put_contents($file, "vulnerabilities:\n  - id: CVE-2026-1\n    statement: >-\n      folded\n");

    try {
        expect(fn () => TrivyIgnoreFile::fromFile($file))->toThrow(RuntimeException::class, 'unsupported line');
    } finally {
        unlink($file);
    }
});

it('scans the app image strictly and each third-party image with only its own exception file', function (): void {
    $release = (string) file_get_contents(base_path('.github/workflows/release.yml'));
    $ci = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($release)->toContain('.images | to_entries[] | "\(.key) \(.value.registry)/\(.value.repository)@\(.value.digest) \($platform)"')
        ->and($release)->toContain("exceptions=(--ignorefile '')")
        ->and($release)->toContain('if [ "$service" != app ] && [ -f "distribution/trivy/${service}.trivyignore.yaml" ]; then')
        ->and($release)->toContain('exceptions=(--ignorefile "/exceptions/${service}.trivyignore.yaml" --show-suppressed)')
        ->and($release)->toContain('--no-progress "${exceptions[@]}" "$reference"')
        ->and(substr_count($release, '--ignorefile'))->toBe(2)
        ->and($release)->toContain('--severity CRITICAL,HIGH --ignore-unfixed --exit-code 1')
        ->and($ci)->toContain("--ignore-unfixed --exit-code 1 --no-progress --ignorefile '' \\\n            form-schema-app:scan")
        ->and($ci)->not->toContain('trivyignore');
});
