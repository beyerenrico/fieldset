<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * The install journeys workflow (BUILD_PROMPT_V2 §11, plan U22, ADR 0091 as amended by ADR 0096): journeys in tiers
 * (Pest groups) and shards (a list of test classes per job), smoke nightly and on pull requests that touch the instance
 * tooling, every tier in parallel shards before a release, one gate job as the result. The workflow keeps one layout,
 * so text is enough and no YAML parser is needed (the shard list is JSON inside the plan job).
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

function journeyJob(string $job): string
{
    return workflowJob(installJourneysWorkflow(), $job);
}

/** @return list<array{shard: string, tier: string, timeout: int, filter: string}> */
function journeyShards(): array
{
    preg_match('/SHARDS: \|\n((?:            .*\n)+)/', journeyJob('plan'), $match);
    $json = preg_replace('/^ {12}/m', '', $match[1] ?? '');

    return json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<string> class names of the files a shard's filter selects, from the list of every Install test */
function shardClasses(string $filter, string $listing): array
{
    $classes = [];
    foreach (explode("\n", $listing) as $line) {
        if (preg_match('/^ - (P\\\\Tests\\\\Install\\\\Journeys\\\\(\w+))::/', $line, $m) === 1 && preg_match('/'.$filter.'/i', substr($line, 3)) === 1) {
            $classes[$m[2]] = true;
        }
    }
    $names = array_keys($classes);
    sort($names);

    return $names;
}

/** @return array<string, string> class name => tier group declared in its file */
function journeyTiers(): array
{
    $tiers = [];
    foreach (glob(base_path('tests/Install/Journeys/*.php')) ?: [] as $file) {
        preg_match_all("/^pest\(\)->group\('([^']+)'\);$/m", (string) file_get_contents($file), $groups);
        $tiers[basename($file, '.php')] = implode(',', $groups[1]);
    }

    return $tiers;
}

/** @return list<string> */
function shardFileClasses(array $shard): array
{
    preg_match_all('/\w+/', $shard['filter'], $words);
    sort($words[0]);

    return $words[0];
}

it('runs nightly, on pull requests that touch the instance tooling, on demand and when called, never on push', function (): void {
    $workflow = installJourneysWorkflow();

    expect($workflow)->toMatch("/^on:\n  schedule:\n    - cron: '[0-9]+ [0-9]+ \* \* \*'\n  pull_request:\n    paths:\n/m")
        ->and($workflow)->toContain("      - 'app/Domain/Instance/**'\n      - 'app/Domain/Platform/Instance/**'\n      - 'distribution/**'\n      - 'docker/**'\n      - 'tests/Install/**'\n      - 'tests/Support/Instance/**'\n      - '.github/workflows/install-journeys.yml'\n")
        ->and($workflow)->toContain("  workflow_dispatch:\n    inputs:\n      tier:")
        ->and($workflow)->toContain("  workflow_call:\n    inputs:\n      tier:")
        ->and($workflow)->toContain("\npermissions: {}\n")
        ->and($workflow)->not->toMatch('/^  push:/m');
});

it('never runs on pull_request_target, uses no secrets and no OIDC token, and keeps a read-only token', function (): void {
    $workflow = installJourneysWorkflow();

    expect($workflow)->not->toContain('pull_request_target')
        ->and($workflow)->not->toContain('secrets')
        ->and($workflow)->not->toContain('id-token')
        ->and($workflow)->not->toContain('environment:')
        ->and($workflow)->not->toContain('write');

    foreach (['plan', 'journeys', 'gate'] as $job) {
        expect(journeyJob($job))->toContain("    permissions:\n      contents: read\n");
    }
});

it('runs its jobs only for branches of this repository when a pull request triggers it', function (): void {
    $condition = "if: \${{ github.event_name != 'pull_request' || github.event.pull_request.head.repo.full_name == github.repository }}";
    $gateCondition = "if: \${{ always() && (github.event_name != 'pull_request' || github.event.pull_request.head.repo.full_name == github.repository) }}";

    expect(journeyJob('plan'))->toContain($condition)
        ->and(journeyJob('journeys'))->toContain($condition)
        ->and(journeyJob('gate'))->toContain($gateCondition);
});

it('cancels an older run of the same pull request and nothing else', function (): void {
    expect(installJourneysWorkflow())
        ->toContain("concurrency:\n  group: install-journeys-\${{ github.event.pull_request.number || github.run_id }}\n  cancel-in-progress: \${{ github.event_name == 'pull_request' }}\n");
});

it('is no longer part of CI on push', function (): void {
    $ci = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($ci)->not->toContain('install-journeys')
        ->and($ci)->not->toContain('--testsuite=Install');
});

it('gates the release: nothing is built before every tier passed on the tagged commit, without secrets', function (): void {
    $release = (string) file_get_contents(base_path('.github/workflows/release.yml'));
    $journeys = workflowJob($release, 'journeys');

    expect($journeys)->toContain('uses: ./.github/workflows/install-journeys.yml')
        ->and($journeys)->toContain("    with:\n      tier: all\n")
        ->and($journeys)->toContain("permissions:\n      contents: read\n")
        ->and($journeys)->not->toContain('id-token')
        ->and($journeys)->not->toContain('environment')
        ->and($journeys)->not->toContain('secrets')
        ->and(workflowJob($release, 'image'))->toContain('needs: [prepare, journeys]');
});

it('offers the tiers smoke, full, budget and all, and resolves an empty tier to smoke', function (): void {
    $workflow = installJourneysWorkflow();

    expect($workflow)->toContain('options: [smoke, full, budget, all]')
        ->and($workflow)->toContain("        type: string\n        default: smoke\n")
        ->and(journeyJob('plan'))->toContain('tier="${REQUESTED_TIER:-smoke}"')
        ->and(journeyJob('plan'))->toContain('REQUESTED_TIER: ${{ inputs.tier }}');
});

it('selects the shards of a tier: empty is smoke, full is five shards, budget is one, all is eight, anything else fails', function (): void {
    $jq = new Process(['jq', '--version']);
    $jq->run();
    if (! $jq->isSuccessful()) {
        $this->markTestSkipped('jq is not installed');
    }

    preg_match('/^          SHARDS: \|\n.*?\n        run: \|\n((?:          .*\n)+)/ms', journeyJob('plan'), $match);
    $script = (string) preg_replace('/^ {10}/m', '', $match[1] ?? '');
    $shards = json_encode(journeyShards(), JSON_THROW_ON_ERROR);
    $select = function (string $tier) use ($script, $shards): array {
        $out = tempnam(sys_get_temp_dir(), 'fsout');
        $process = new Process(['bash', '-c', $script], null, ['REQUESTED_TIER' => $tier, 'SHARDS' => $shards, 'GITHUB_OUTPUT' => $out]);
        $process->run();
        $matrix = [];
        if (preg_match('/^matrix=(.*)$/m', (string) file_get_contents($out), $m) === 1) {
            $matrix = array_column(json_decode($m[1], true)['include'], 'shard');
        }
        unlink($out);

        return [$process->getExitCode(), $matrix];
    };

    expect($select(''))->toBe([0, ['smoke-a', 'smoke-b']])
        ->and($select('smoke'))->toBe([0, ['smoke-a', 'smoke-b']])
        ->and($select('full'))->toBe([0, ['full-1', 'full-2', 'full-3', 'full-4', 'full-5']])
        ->and($select('budget'))->toBe([0, ['budget']])
        ->and($select('all'))->toBe([0, ['smoke-a', 'smoke-b', 'full-1', 'full-2', 'full-3', 'full-4', 'full-5', 'budget']])
        ->and($select('bogus')[0])->not->toBe(0);
});

it('runs every shard on an amd64 runner with Docker and Playwright required, selecting its test classes', function (): void {
    $job = journeyJob('journeys');

    expect($job)->toContain('runs-on: ubuntu-24.04')
        ->and($job)->toContain('needs: plan')
        ->and($job)->toContain('fail-fast: false')
        ->and($job)->toContain('matrix: ${{ fromJSON(needs.plan.outputs.matrix) }}')
        ->and($job)->toContain('timeout-minutes: ${{ matrix.timeout }}')
        ->and($job)->toContain("FIELDSET_REQUIRE_DOCKER: '1'")
        ->and($job)->toContain("FIELDSET_REQUIRE_PLAYWRIGHT: '1'")
        ->and($job)->toContain('FIELDSET_HARNESS_PLATFORMS: linux/amd64')
        ->and($job)->toContain('FIELDSET_EVIDENCE_MILESTONE: M13')
        ->and($job)->toContain('FILTER: ${{ matrix.filter }}')
        ->and($job)->toContain('run: vendor/bin/pest --testsuite=Install --filter="$FILTER"');
});

it('provides what the journeys use: Playwright with Chromium, poppler, the harness cache, the v1.0.0 tag with history', function (): void {
    $job = journeyJob('journeys');

    expect($job)->toContain('- run: npm ci')
        ->and($job)->toContain('npx playwright install --with-deps chromium')
        ->and($job)->toContain('PLAYWRIGHT_BROWSERS_PATH: storage/playwright')
        ->and($job)->toContain('poppler-utils')
        ->and($job)->toContain('path: storage/fieldset-test-cache')
        ->and($job)->toContain('fetch-depth: 0')
        ->and($job)->toContain('persist-credentials: false')
        ->and($job)->toContain('git tag v1.0.0 70b97e5cc2fe8d6d668a9c9b2ade29a54dd288e0');
});

it('uploads the logs of every shard, always, under a name that includes the shard', function (): void {
    $job = journeyJob('journeys');

    preg_match_all('/^\s+name: (install-journey-logs.*)$/m', $job, $names);
    expect($names[1])->toBe(['install-journey-logs-${{ matrix.shard }}'])
        ->and($job)->toMatch('/- if: always\(\)\n        uses: actions\/upload-artifact@/');
});

it('ends in a gate that is green only when the plan and every selected shard succeeded', function (): void {
    $gate = journeyJob('gate');

    expect($gate)->toContain('needs: [plan, journeys]')
        ->and($gate)->toContain('if: ${{ always() && ')
        ->and($gate)->toContain('PLAN_RESULT: ${{ needs.plan.result }}')
        ->and($gate)->toContain('JOURNEYS_RESULT: ${{ needs.journeys.result }}')
        ->and($gate)->toContain('[ "$PLAN_RESULT" = success ] && [ "$JOURNEYS_RESULT" = success ]');
});

it('gives every journey file exactly one tier group', function (): void {
    $tiers = journeyTiers();

    expect($tiers)->toHaveCount(20);
    foreach ($tiers as $class => $groups) {
        expect($groups)->toBeIn(['smoke', 'full', 'budget'], $class);
    }
});

it('holds only J10 in the budget tier, and the smoke set of ADR 0096 in smoke', function (): void {
    $tiers = journeyTiers();
    $in = fn (string $tier): array => array_keys(array_filter($tiers, fn (string $t): bool => $t === $tier));

    expect($in('budget'))->toBe(['J10ResourceBudgetTest'])
        ->and($in('smoke'))->toBe(['BootstrapJourneyTest', 'ComposeConfigJourneyTest', 'EdgeConfigJourneyTest', 'HarnessReleaseTest', 'J01LocalInstallTest', 'J07UpgradeTest', 'J08BackupRestoreTest']);

    $shards = journeyShards();
    $budget = array_values(array_filter($shards, fn (array $s): bool => $s['tier'] === 'budget'));
    expect($budget)->toHaveCount(1)
        ->and(shardFileClasses($budget[0]))->toBe(['J10ResourceBudgetTest']);
});

it('covers the journey files exactly once across the shards, each in the shard of its tier', function (): void {
    $tiers = journeyTiers();
    $seen = [];
    foreach (journeyShards() as $shard) {
        foreach (shardFileClasses($shard) as $class) {
            expect($tiers)->toHaveKey($class)
                ->and($seen)->not->toHaveKey($class) // in no two shards
                ->and($tiers[$class])->toBe($shard['tier'], $class.' is in shard '.$shard['shard']);
            $seen[$class] = $shard['shard'];
        }
        expect($shard['timeout'])->toBeLessThanOrEqual($shard['tier'] === 'budget' ? 120 : 45);
    }

    expect(array_keys($seen))->toEqualCanonicalizing(array_keys($tiers));
});

it('uses shard names that are unique', function (): void {
    $names = array_column(journeyShards(), 'shard');

    expect($names)->toBe(array_values(array_unique($names)))
        ->and($names)->toBe(['smoke-a', 'smoke-b', 'full-1', 'full-2', 'full-3', 'full-4', 'full-5', 'budget']);
});

it('selects, with each shard filter, exactly the test classes of its files (pest --list-tests)', function (): void {
    $process = new Process([PHP_BINARY, 'vendor/bin/pest', '--testsuite=Install', '--list-tests'], base_path());
    $process->setTimeout(120)->mustRun();
    $listing = $process->getOutput();

    $all = [];
    foreach (journeyShards() as $shard) {
        $selected = shardClasses($shard['filter'], $listing);
        $expected = shardFileClasses($shard);

        expect($selected)->toBe($expected, $shard['shard']);
        $all = [...$all, ...$selected];
    }

    expect($all)->toHaveCount(20)
        ->and(array_unique($all))->toHaveCount(20);
});
