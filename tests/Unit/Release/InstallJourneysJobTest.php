<?php

declare(strict_types=1);

/*
 * The CI install-journeys job (BUILD_PROMPT_V2 §11, plan U22, ADR 0091): every journey on amd64, the dogfooding E2E run
 * included, with the tools they need present, so a missing tool fails instead of skipping.
 */

function installJourneysJob(): string
{
    $ci = (string) file_get_contents(base_path('.github/workflows/ci.yml'));
    preg_match('/^  install-journeys:\n(.*?)(?=^  [a-z][a-z0-9-]*:\n|\z)/ms', $ci, $match);

    return $match[1] ?? '';
}

it('runs the whole Install suite on an amd64 runner with Docker and Playwright required', function (): void {
    $job = installJourneysJob();

    expect($job)->toContain('runs-on: ubuntu-24.04')
        ->and($job)->toContain("FSA_REQUIRE_DOCKER: '1'")
        ->and($job)->toContain("FSA_REQUIRE_PLAYWRIGHT: '1'")
        ->and($job)->toContain('FSA_HARNESS_PLATFORMS: linux/amd64')
        ->and($job)->toContain('- run: vendor/bin/pest --testsuite=Install')
        ->and($job)->not->toContain('--filter');
});

it('provides what the journeys use: Playwright with Chromium, poppler, the v1.0.0 tag with history', function (): void {
    $job = installJourneysJob();

    expect($job)->toContain('- run: npm ci')
        ->and($job)->toContain('npx playwright install --with-deps chromium')
        ->and($job)->toContain('PLAYWRIGHT_BROWSERS_PATH: storage/playwright')
        ->and($job)->toContain('poppler-utils')
        ->and($job)->toContain('fetch-depth: 0')
        ->and($job)->toContain('git tag v1.0.0 70b97e5cc2fe8d6d668a9c9b2ade29a54dd288e0');
});
