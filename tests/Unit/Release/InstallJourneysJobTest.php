<?php

declare(strict_types=1);

/*
 * The install journeys (BUILD_PROMPT_V2 §11, plan U22, ADR 0091 and its amendment of 2026-10-22): their own workflow, run
 * every night, on demand and by the release workflow before it builds anything; every journey on amd64, the dogfooding
 * E2E run included, with the tools they need present, so a missing tool fails instead of skipping. Pushes and pull
 * requests run CI without them.
 */

function installJourneysWorkflow(): string
{
    return (string) file_get_contents(base_path('.github/workflows/install-journeys.yml'));
}

function workflowJob(string $workflow, string $job): string
{
    preg_match('/^  '.$job.':\n(.*?)(?=^  [a-z][a-z0-9-]*:\n|\z)/ms', $workflow, $match);

    return $match[1] ?? '';
}

function installJourneysJob(): string
{
    return workflowJob(installJourneysWorkflow(), 'install-journeys');
}

it('runs every night, on demand and when the release workflow calls it, never on push or pull request', function (): void {
    $workflow = installJourneysWorkflow();

    expect($workflow)->toMatch("/^on:\n  schedule:\n    - cron: '[0-9]+ [0-9]+ \* \* \*'\n  workflow_dispatch:\n  workflow_call:\n\n/m")
        ->and($workflow)->toContain("\npermissions: {}\n")
        ->and($workflow)->not->toContain('pull_request')
        ->and($workflow)->not->toMatch('/^  push:/m');
});

it('is no longer part of CI on push', function (): void {
    $ci = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($ci)->not->toContain('install-journeys')
        ->and($ci)->not->toContain('--testsuite=Install');
});

it('gates the release: nothing is built before the journeys pass on the tagged commit, without secrets', function (): void {
    $release = (string) file_get_contents(base_path('.github/workflows/release.yml'));
    $journeys = workflowJob($release, 'journeys');

    expect($journeys)->toContain('uses: ./.github/workflows/install-journeys.yml')
        ->and($journeys)->toContain("permissions:\n      contents: read\n")
        ->and($journeys)->not->toContain('id-token')
        ->and($journeys)->not->toContain('environment')
        ->and($journeys)->not->toContain('secrets')
        ->and(workflowJob($release, 'image'))->toContain('needs: [prepare, journeys]');
});

it('runs the whole Install suite on an amd64 runner with Docker and Playwright required', function (): void {
    $job = installJourneysJob();

    expect($job)->toContain('runs-on: ubuntu-24.04')
        ->and($job)->toContain("FIELDSET_REQUIRE_DOCKER: '1'")
        ->and($job)->toContain("FIELDSET_REQUIRE_PLAYWRIGHT: '1'")
        ->and($job)->toContain('FIELDSET_HARNESS_PLATFORMS: linux/amd64')
        ->and($job)->toContain('- run: vendor/bin/pest --testsuite=Install')
        ->and($job)->not->toContain('--filter')
        ->and($job)->not->toMatch('/^    if:/m'); // no job condition: every trigger runs the journeys
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
