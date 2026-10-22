<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\Docker\BudgetJourney;
use Tests\Support\Docker\DogfoodReport;
use Tests\Support\Docker\InstanceJourney;
use Tests\Support\Docker\ReleaseHarness;
use Tests\Support\Docker\SharedHarness;
use Tests\Support\Docker\TestImage;

/*
 * Dogfooding (BUILD_PROMPT_V2 §11, §15.6; plan U22; ADR 0091): the existing E2E suite (Playwright project `desktop`, two
 * workers) against a local-mode `--demo` instance that install.sh and `fieldset install` set up from the harness release.
 * The suite reaches the instance through E2E_TARGET=fieldset, E2E_BASE_URL, E2E_COMPOSE_PROJECT (artisan in the instance's app container) and
 * MAILPIT_URL/MAILPIT_AUTH (the instance's catcher behind its UI login). Specs that need dev-only services or settings
 * (Keycloak SSO, the SSO demo seed, open registration) skip themselves on an instance with a "dev stack only:" reason and
 * keep running on the dev stack (tests/e2e/support/target.ts). Playwright runs in its own working directory under the
 * harness workspace, so the specs' screenshots of earlier milestones' evidence never touch the checkout (LEARNINGS 159).
 *
 * Everything is named `fieldset-test-dogfood…` and removed afterwards. Evidence: dogfood-e2e.json in the milestone of
 * FIELDSET_EVIDENCE_MILESTONE (per spec file: passed, skipped with the reason, failed with the first error line; the
 * instance's containers that died or were OOM-killed during the suite). storage/agent-logs/dogfood-e2e-timeline.txt lists
 * every result by start time and worker, so a CI failure shows what ran beside it.
 */

/** Skip reasons a spec may give on an instance: the dev-only marker and the J4-only capture-banner spec. */
const DOGFOOD_ALLOWED_SKIPS = ['/^dev stack only: /', '/^runs from the J4 install journey/'];

beforeAll(function (): void {
    if (TestImage::dockerUnavailableReason() !== null) {
        return;
    }

    try {
        [$harness, $release] = SharedHarness::get();
        $GLOBALS['fieldsetDogfood'] = new InstanceJourney($harness, $release, $harness->directory.'/dogfood');
    } catch (Throwable $exception) {
        $GLOBALS['fieldsetDogfoodError'] = $exception;
    }
});

afterAll(function (): void {
    if (isset($GLOBALS['fieldsetDogfood'])) {
        InstanceJourney::cleanup($GLOBALS['fieldsetDogfood']->work.'/fieldset-test-dogfood', $GLOBALS['fieldsetDogfoodUuid'] ?? null);
    }
    unset($GLOBALS['fieldsetDogfood'], $GLOBALS['fieldsetDogfoodError'], $GLOBALS['fieldsetDogfoodUuid'], $GLOBALS['fieldsetDogfoodInstall']);
});

beforeEach(function (): void {
    $reason = TestImage::dockerUnavailableReason();
    if ($reason !== null) {
        expect(getenv('FIELDSET_REQUIRE_DOCKER'))->not->toBe('1', $reason);
        $this->markTestSkipped($reason.' (install journeys need a Docker engine)');
    }
    if (isset($GLOBALS['fieldsetDogfoodError'])) {
        throw $GLOBALS['fieldsetDogfoodError'];
    }
    $this->journey = $GLOBALS['fieldsetDogfood'];
    $this->dir = $this->journey->work.'/fieldset-test-dogfood';
});

it('installs a local-mode demo instance from the harness release', function (): void {
    $flags = ['--org', 'Dogfood Org', '--owner-email', 'owner@example.test', '--locale', 'en', '--demo', '--secrets-file', $this->journey->work.'/owner-link.json', '--json'];
    $process = $this->journey->install($this->dir, $flags);
    $json = InstanceJourney::json($process);
    $GLOBALS['fieldsetDogfoodUuid'] = InstanceJourney::uuid($this->dir);
    $GLOBALS['fieldsetDogfoodInstall'] = $json['data'] ?? [];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput())
        ->and($json['data']['mode'] ?? null)->toBe('local')
        ->and($json['data']['url'] ?? null)->toBe('http://localhost:'.($json['data']['port'] ?? '?'))
        ->and($json['data']['mail']['mode'] ?? null)->toBe('capture')
        ->and($json['data']['demo'] ?? [])->toMatchArray(['seeded' => true, 'passwords' => 'default']);
});

it('passes the E2E suite against the instance, dev-only specs skipped with their reason', function (): void {
    $playwright = TestImage::repository().'/node_modules/.bin/playwright';
    if (! is_file($playwright)) {
        expect(getenv('FIELDSET_REQUIRE_PLAYWRIGHT'))->not->toBe('1', 'Playwright is not installed (npm ci)');
        $this->markTestSkipped('Playwright is not installed (npm ci).');
    }
    $install = $GLOBALS['fieldsetDogfoodInstall'] ?? [];
    expect($install)->not->toBe([], 'the install did not finish');
    $report = $this->journey->work.'/e2e-report.json';
    $started = microtime(true);
    $process = new Process([$playwright, 'test', '--config='.TestImage::repository().'/playwright.config.ts', '--project=desktop', '--workers=2', '--reporter=json'],
        DogfoodReport::workingDirectory($this->journey->work.'/e2e'), [
            'PLAYWRIGHT_BROWSERS_PATH' => BudgetJourney::browsers(), 'PLAYWRIGHT_JSON_OUTPUT_NAME' => $report,
            'E2E_TARGET' => 'fieldset', 'E2E_BASE_URL' => $install['url'], 'E2E_COMPOSE_PROJECT' => $install['project'],
            'MAILPIT_URL' => $install['mail']['catcher_url'], 'MAILPIT_AUTH' => DogfoodReport::catcherLogin($this->dir),
        ]);
    $process->setTimeout(3600);
    $process->run();
    // A container of the instance that died or was OOM-killed while the suite ran (none on a healthy run).
    $events = InstanceJourney::events((int) $started, time(), ['type=container', 'label='.InstanceJourney::LABEL.'='.$GLOBALS['fieldsetDogfoodUuid'], 'event=die', 'event=oom'],
        '{{.Action}} {{.Actor.Attributes.name}} exit={{index .Actor.Attributes "exitCode"}}');
    file_put_contents(TestImage::logFile('dogfood-e2e-timeline.txt'), implode("\n", DogfoodReport::timeline((string) @file_get_contents($report)))."\n");
    $summary = DogfoodReport::summarize((string) @file_get_contents($report));
    $this->journey->evidence('dogfood-e2e.json', [
        'target' => ['install' => 'install.sh --dir <work>/fieldset-test-dogfood --version '.ReleaseHarness::version().' --org "Dogfood Org" --owner-email owner@example.test --locale en --demo --secrets-file <work>/owner-link.json --json',
            'url' => $install['url'], 'project' => $install['project'], 'catcher' => $install['mail']['catcher_url'], 'platform' => ReleaseHarness::platforms()[0]],
        'command' => 'playwright test --project=desktop --workers=2 (E2E_TARGET=fieldset, E2E_BASE_URL, E2E_COMPOSE_PROJECT, MAILPIT_URL, MAILPIT_AUTH set)',
        'exit_code' => $process->getExitCode(), 'seconds' => round(microtime(true) - $started, 1), ...$summary,
        'instance_events' => $events, 'stderr' => array_slice(explode("\n", trim($process->getErrorOutput())), -10),
    ]);
    $reasons = array_unique(array_column($summary['skipped'], 'reason'));

    expect($summary['stats']['unexpected'] ?? null)->toBe(0, json_encode($summary['failed'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n".DogfoodReport::failureDetails((string) @file_get_contents($report))."\ninstance container events: ".json_encode($events)."\n".$process->getErrorOutput())
        ->and($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($summary['stats']['expected'] ?? 0)->toBeGreaterThanOrEqual(60)
        ->and(array_filter($reasons, static fn (string $reason): bool => ! array_any(DOGFOOD_ALLOWED_SKIPS, static fn (string $pattern): bool => preg_match($pattern, $reason) === 1)))->toBe([])
        // The /platform instance page's axe check (plan U20) is in this test: it ran and passed on the instance.
        ->and($summary['passed'])->toContain('m8-accessibility.spec.ts › platform panel › login, MFA set-up, organizations, members and health');
});

it('uninstalls without leaving anything labelled behind', function (): void {
    $process = $this->journey->fieldset($this->dir, ['uninstall', '--yes', '--json']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and(InstanceJourney::labelled((string) $GLOBALS['fieldsetDogfoodUuid']))->toBe(['containers' => [], 'volumes' => [], 'networks' => []]);
});
