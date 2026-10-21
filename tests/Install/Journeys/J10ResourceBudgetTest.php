<?php

declare(strict_types=1);

use App\Domain\Instance\Compose\RenderContext;
use App\Domain\Instance\Compose\ResourceBudget;
use Carbon\CarbonImmutable;
use Symfony\Component\Process\Process;
use Tests\Support\Docker\AcmeHarness;
use Tests\Support\Docker\BudgetJourney;
use Tests\Support\Docker\CgroupReader;
use Tests\Support\Docker\EnvelopeMonitor;
use Tests\Support\Docker\InstanceJourney;
use Tests\Support\Docker\LocalCaClient;
use Tests\Support\Docker\ReleaseHarness;
use Tests\Support\Docker\SharedHarness;
use Tests\Support\Docker\TestImage;

/*
 * J10 (BUILD_PROMPT_V2 §11, E16, §2.3 "Small"; plan U15; ADR 0083): the shipped stack inside the E16 envelope. Domain mode
 * with capture mail against Pebble (as J2), no Keycloak, the scheduler running and production Horizon settings. Memory:
 * the generated compose file's own limits (ResourceBudget, small). CPU: EnvelopeMonitor pins every container of the
 * instance, its one-offs and the fsa ops containers to the same two CPUs as soon as Docker creates them, the way a
 * 2-vCPU host shares its CPUs. Under that envelope: install (time to the sign-in link, pulls reported separately) → the
 * smoke E2E in Chromium → `fsa demo`, the 1.0 load (`app:seed-load demo 100000`, perf fixtures with 150k audit entries)
 * → a 10-page PDF (scripts/perf/pdf.php) → scripts/perf/admin-pages.mjs and api.mjs at their 1.0 settings → a scheduled
 * backup at that data volume with the backup service's memory peak against its slot (plan U22) → no container was
 * OOM-killed or restarted → uninstall. Timings and p95s are recorded against the 1.0 targets with a verdict each; a
 * miss is reported in the evidence and ADR 0083, never asserted away (rule 4 of §13), while OOM kills, restarts and
 * failed steps fail the journey. Evidence goes to the milestone of FIELDSET_EVIDENCE_MILESTONE (J10-*.json).
 */

const J10_DOMAIN = 'fsa-test-j10.test';
const J10_OWNER = 'owner@example.test';
const J10_SERVICES = ['app', 'backup', 'edge', 'gotenberg', 'mailpit', 'postgres', 'queue', 'redis', 'scheduler'];
/** The 1.0 targets (BUILD_PROMPT §11, ADR 0046) and E16's install target (§2.3), in ms. */
const J10_TARGETS = ['install_ms' => 300_000, 'admin_p95_ms' => 500, 'api_p95_ms' => 300, 'pdf_queued_ms' => 10_000];

beforeAll(function (): void {
    if (TestImage::dockerUnavailableReason() !== null) {
        return;
    }

    try {
        [$harness, $release] = SharedHarness::get();
        $GLOBALS['fsaJ10'] = new InstanceJourney($harness, $release, $harness->directory.'/j10');
        $GLOBALS['fsaJ10Acme'] = AcmeHarness::start($harness);
        $GLOBALS['fsaJ10Cpuset'] = EnvelopeMonitor::cpuset();
        $GLOBALS['fsaJ10Monitor'] = EnvelopeMonitor::spawn($GLOBALS['fsaJ10']->work.'/envelope.json', $GLOBALS['fsaJ10']->work.'/fsa-test-j10', $GLOBALS['fsaJ10Cpuset']);
    } catch (Throwable $exception) {
        $GLOBALS['fsaJ10Error'] = $exception;
    }
});

afterAll(function (): void {
    if (isset($GLOBALS['fsaJ10Monitor']) && $GLOBALS['fsaJ10Monitor']->isRunning()) {
        try {
            EnvelopeMonitor::finish($GLOBALS['fsaJ10Monitor'], $GLOBALS['fsaJ10']->work.'/envelope.json');
        } catch (RuntimeException) {
            // no summary yet: nothing to report, and the reader (if any) removes itself
        }
    }
    if (isset($GLOBALS['fsaJ10'])) {
        InstanceJourney::cleanup($GLOBALS['fsaJ10']->work.'/fsa-test-j10', $GLOBALS['fsaJ10Uuid'] ?? null);
    }
    if (isset($GLOBALS['fsaJ10Acme'])) {
        $GLOBALS['fsaJ10Acme']->stop();
    }
    unset($GLOBALS['fsaJ10'], $GLOBALS['fsaJ10Acme'], $GLOBALS['fsaJ10Error'], $GLOBALS['fsaJ10Uuid'], $GLOBALS['fsaJ10Monitor'], $GLOBALS['fsaJ10Cpuset'], $GLOBALS['fsaJ10Chromium']);
});

beforeEach(function (): void {
    $reason = TestImage::dockerUnavailableReason();
    if ($reason !== null) {
        expect(getenv('FIELDSET_REQUIRE_DOCKER'))->not->toBe('1', $reason);
        $this->markTestSkipped($reason.' (install journeys need a Docker engine)');
    }
    if (isset($GLOBALS['fsaJ10Error'])) {
        throw $GLOBALS['fsaJ10Error'];
    }
    $this->journey = $GLOBALS['fsaJ10'];
    $this->acme = $GLOBALS['fsaJ10Acme'];
    $this->dir = $this->journey->work.'/fsa-test-j10';
    $this->monitor = $this->journey->work.'/envelope.json';
    $this->env = ['FIELDSET_TEST_DNS' => J10_DOMAIN.'='.(LocalCaClient::lanAddress() ?? '1.1.1.1')];
});

/** Nearest-rank percentile, as scripts/perf/lib.mjs computes it; null without values. @param  list<int|float>  $values */
function j10Percentile(array $values, int $p): ?float
{
    sort($values);

    return $values === [] ? null : (float) $values[max(1, min(count($values), (int) ceil($p / 100 * count($values)))) - 1];
}

/** @return array<string, mixed> the JSON document a one-off printed after any other output */
function j10Json(Process $process): array
{
    $output = $process->getOutput();
    $decoded = json_decode(substr($output, (int) strpos($output, '{')), true);

    return is_array($decoded) ? $decoded : ['raw' => substr($output, -2000), 'stderr' => substr($process->getErrorOutput(), -2000)];
}

/** The release platform: native, or the emulated one of FIELDSET_TEST_PLATFORM / FIELDSET_HARNESS_PLATFORMS. */
function j10Platform(): string
{
    return ReleaseHarness::platforms()[0];
}

/** fsa doubles every memory limit when the images run emulated (ADR 0070, RenderContext); 1 on a native run. */
function j10MemoryFactor(): int
{
    return ReleaseHarness::platformEnvironment(ReleaseHarness::platforms()) === [] ? 1 : RenderContext::EMULATION_MEMORY_FACTOR;
}

/** @return array<string, string> the settings scripts/perf/*.mjs need for this instance (lib.mjs) */
function j10PerfEnv(): array
{
    $work = $GLOBALS['fsaJ10']->work;

    return [
        'PERF_BASE_URL' => 'https://'.J10_DOMAIN, 'PERF_OUT_DIR' => $work.'/perf', 'PERF_PASSWORDS_FILE' => $work.'/demo-secrets.json',
        'PERF_CHROMIUM_ARGS' => json_encode($GLOBALS['fsaJ10Chromium'], JSON_THROW_ON_ERROR), 'PERF_RESOLVE' => J10_DOMAIN.':443:127.0.0.1',
        'PERF_CA_FILE' => $work.'/pebble-issuing-root.pem', 'PERF_QUIET_LOAD' => (string) (getenv('PERF_QUIET_LOAD') ?: '10'), 'PERF_QUIET_WAIT' => '300',
    ];
}

it('installs domain mode with capture mail inside the envelope and records the time to the sign-in link', function (): void {
    $flags = ['--org', 'J10 Org', '--owner-email', J10_OWNER, '--locale', 'en', '--domain', J10_DOMAIN, '--acme-email', 'ops@example.test',
        '--acme-ca', AcmeHarness::DIRECTORY_URL, '--acme-ca-root-file', $this->acme->httpsRoot, '--secrets-file', $this->journey->work.'/owner-link.json', '--json'];
    EnvelopeMonitor::phase($this->monitor, 'install');
    $started = microtime(true);
    $process = $this->journey->installProcess($this->dir, $flags, $this->env);
    $process->start();
    [$lines, $partial, $connected] = [[], '', false];
    while ($process->isRunning() || $partial !== '') {
        $chunk = explode("\n", $partial.$process->getIncrementalErrorOutput());
        $partial = $process->isRunning() ? array_pop($chunk) : '';
        foreach (array_filter($chunk) as $line) {
            $lines[] = ['at' => round(microtime(true) - $started, 2), 'line' => $line];
        }
        $uuid = InstanceJourney::uuid($this->dir);
        $edge = $uuid === null || $connected ? '' : trim(new Process(['docker', 'ps', '-aq', '--filter', 'label=app.fieldset.role=edge', '--filter', "label=app.fieldset.instance={$uuid}"])->mustRun()->getOutput());
        if ($edge !== '') {
            $this->acme->connectEdge($edge);
            $connected = true;
        }
        usleep(200_000);
    }
    $seconds = round(microtime(true) - $started, 1);
    $json = InstanceJourney::json($process);
    $GLOBALS['fsaJ10Uuid'] = InstanceJourney::uuid($this->dir);
    $timing = BudgetJourney::phases($lines);
    $withoutPull = round($seconds - $timing['pull_seconds'], 1);
    $this->journey->evidence('J10-install.json', [
        'command' => 'install.sh --dir <work>/fsa-test-j10 --version '.ReleaseHarness::version().' '.implode(' ', array_slice($flags, 0, 14)).' --acme-ca-root-file <harness>/pebble-https-root.pem --secrets-file <work>/owner-link.json --json',
        'exit_code' => $process->getExitCode(), 'seconds' => $seconds, 'pull_seconds' => $timing['pull_seconds'], 'seconds_without_pull' => $withoutPull,
        'target_seconds' => J10_TARGETS['install_ms'] / 1000, 'verdict' => $withoutPull * 1000 <= J10_TARGETS['install_ms'] ? 'met' : 'missed',
        'phases' => $timing['phases'], 'pull_size' => BudgetJourney::pullSize($this->journey->release, j10Platform()),
        'stdout' => $json, 'stderr_ndjson' => $lines,
    ]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput()."\n".$this->acme->logs())
        ->and($json['data'])->toMatchArray(['url' => 'https://'.J10_DOMAIN, 'mode' => 'domain'])
        ->and($json['data']['mail']['mode'])->toBe('capture')
        ->and($json['data']['sign_in_link']['delivered'])->toBe('secrets_file')
        ->and($connected)->toBeTrue();
});

it('runs every service pinned to the two CPUs with the small budget\'s memory limits', function (): void {
    $budget = ResourceBudget::forSize('small');
    $containers = BudgetJourney::containers($GLOBALS['fsaJ10Uuid']);
    $limits = array_map(static fn (array $c): int => $c['memory_limit_mib'], $containers);
    $info = json_decode(new Process(['docker', 'info', '--format', '{{json .}}'])->mustRun()->getOutput(), true);
    $this->journey->evidence('J10-envelope.json', [
        'engine' => ['cpus' => $info['NCPU'] ?? null, 'memory_mib' => intdiv((int) ($info['MemTotal'] ?? 0), 1048576), 'architecture' => $info['Architecture'] ?? null,
            'kernel' => $info['KernelVersion'] ?? null, 'operating_system' => $info['OperatingSystem'] ?? null, 'server_version' => $info['ServerVersion'] ?? null, 'cgroup' => ($info['CgroupDriver'] ?? '').' v'.($info['CgroupVersion'] ?? '')],
        'platform' => j10Platform(), 'memory_factor' => j10MemoryFactor(),
        'cpuset' => $GLOBALS['fsaJ10Cpuset'], 'memory_limits_mib' => $limits, 'limits_sum_mib' => array_sum($limits),
        'budget' => ['host_mib' => $budget->hostMib(), 'headroom_mib' => $budget->headroomMib(), 'available_mib' => $budget->availableMib(), 'worst_case_mib' => $budget->worstCaseMib()],
        'containers' => $containers,
    ]);

    expect(array_keys($containers))->toBe(J10_SERVICES)
        ->and(array_unique(array_column($containers, 'cpuset')))->toBe([$GLOBALS['fsaJ10Cpuset']])
        ->and(array_sum($limits))->toBeLessThanOrEqual($budget->availableMib() * j10MemoryFactor());
    foreach ($limits as $service => $mib) {
        expect($mib)->toBe($budget->limitMib($service) * j10MemoryFactor(), $service);
    }
});

it('passes the smoke E2E in Chromium against the instance URL', function (): void {
    $work = $this->journey->work;
    file_put_contents($work.'/pebble-issuing-root.pem', $this->acme->issuingRoot());
    $chain = BudgetJourney::servedChain(J10_DOMAIN, $work.'/pebble-issuing-root.pem');
    $GLOBALS['fsaJ10Chromium'] = ['--host-resolver-rules=MAP '.J10_DOMAIN.' 127.0.0.1', '--ignore-certificate-errors-spki-list='.LocalCaClient::spki([...$chain, $this->acme->issuingRoot()])];
    $link = (string) (json_decode((string) file_get_contents($work.'/owner-link.json'), true)['secrets'][0]['value'] ?? '');
    EnvelopeMonitor::phase($this->monitor, 'smoke');
    $process = new Process([TestImage::repository().'/node_modules/.bin/playwright', 'test', 'tests/e2e/fsa-smoke.spec.ts', '--project=fsa-instance', '--reporter=line'], TestImage::repository(), [
        'PLAYWRIGHT_BROWSERS_PATH' => BudgetJourney::browsers(), 'FIELDSET_INSTANCE_URL' => 'https://'.J10_DOMAIN, 'FIELDSET_SMOKE_LINK' => $link,
        'FIELDSET_SMOKE_PASSWORD' => 'J10-'.bin2hex(random_bytes(12)), 'FIELDSET_CHROMIUM_ARGS' => json_encode($GLOBALS['fsaJ10Chromium'], JSON_THROW_ON_ERROR),
    ]);
    $process->setTimeout(600);
    $process->run();
    $this->journey->evidence('J10-smoke.json', ['command' => 'playwright test tests/e2e/fsa-smoke.spec.ts --project=fsa-instance', 'exit_code' => $process->getExitCode(), 'output' => array_slice(explode("\n", trim($process->getOutput())), -12)]);

    expect($link)->toContain('/login-link#')
        ->and($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput())
        ->and($process->getOutput())->toContain('3 passed');
});

it('adds the demo organization and loads the 1.0 test volume', function (): void {
    EnvelopeMonitor::phase($this->monitor, 'demo');
    $demo = $this->journey->fsa($this->dir, ['demo', '--secrets-file', $this->journey->work.'/demo-secrets.json', '--json'], $this->env);
    EnvelopeMonitor::phase($this->monitor, 'seed');
    $started = microtime(true);
    $seed = BudgetJourney::oneOff($this->dir, ['php', 'artisan', 'app:seed-load', 'demo', '100000', '--force', '--no-ansi']);
    $seedSeconds = round(microtime(true) - $started, 1);
    $renditions = j10FillRenditionGap();
    EnvelopeMonitor::phase($this->monitor, 'fixtures');
    $started = microtime(true);
    $fixtures = BudgetJourney::oneOff($this->dir, ['php', '--', 'demo', '150000'], ['PERF_THROWAWAY_INSTANCE' => '1'], (string) file_get_contents(TestImage::repository().'/scripts/perf/fixtures.php'));
    $fixturesSeconds = round(microtime(true) - $started, 1);
    $data = j10Json($fixtures);
    @mkdir($this->journey->work.'/perf', 0755, true);
    file_put_contents($this->journey->work.'/perf/fixtures.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    unset($data['token']);
    // What documents:sweep dispatched while the load was generated (before the gap was filled) drains before measuring.
    EnvelopeMonitor::phase($this->monitor, 'settle');
    $settle = BudgetJourney::settleQueue(BudgetJourney::containers($GLOBALS['fsaJ10Uuid'])['app']['id']);
    $this->journey->evidence('J10-data.json', [
        'demo' => ['exit_code' => $demo->getExitCode(), 'stdout' => InstanceJourney::json($demo)],
        'seed_load' => ['command' => 'php artisan app:seed-load demo 100000 --force (one-off)', 'exit_code' => $seed->getExitCode(), 'seconds' => $seedSeconds, 'output' => array_slice(explode("\n", trim($seed->getOutput())), -12)],
        'rendition_gap' => ['exit_code' => $renditions->getExitCode(), 'output' => trim($renditions->getOutput().$renditions->getErrorOutput())],
        'fixtures' => ['command' => 'php -- demo 150000 < scripts/perf/fixtures.php (one-off)', 'exit_code' => $fixtures->getExitCode(), 'seconds' => $fixturesSeconds, 'result' => $data],
        'queue_settled' => $settle,
    ]);

    expect($demo->getExitCode())->toBe(0, $demo->getErrorOutput().$demo->getOutput())
        ->and($seed->getExitCode())->toBe(0, $seed->getErrorOutput().$seed->getOutput())
        ->and($renditions->getExitCode())->toBe(0, $renditions->getErrorOutput())
        ->and($fixtures->getExitCode())->toBe(0, $fixtures->getErrorOutput())
        ->and($data['counts']['submissions'] ?? 0)->toBeGreaterThanOrEqual(100_000)
        ->and($data['counts']['activity_log'] ?? 0)->toBeGreaterThanOrEqual(150_000)
        ->and($settle['settled'])->toBeTrue('the queue did not drain within 15 minutes');
});

/**
 * Rendition rows (no file) for the generated submissions a real instance would have rendered on submit (render_on_submit):
 * the generator gives only approved and completed ones a row. Without the rest, the running scheduler's documents:sweep
 * would render ~22k PDFs during the measurement, the reason 1.0 stopped its scheduler instead (ADR 0083).
 */
function j10FillRenditionGap(): Process
{
    return BudgetJourney::psql(BudgetJourney::containers($GLOBALS['fsaJ10Uuid'])['postgres']['id'], j10RenditionGapSql());
}

function j10RenditionGapSql(): string
{
    return <<<'SQL'
        WITH template AS (SELECT organization_id, form_version_id, design_version_id FROM renditions WHERE renderer_version = 'load-test' LIMIT 1),
        inserted AS (
            INSERT INTO renditions (uuid, organization_id, submission_uuid, form_version_id, design_version_id, input_hash, file_sha256,
                renderer_version, page_count, disk, path, size, reason, created_at)
            SELECT s.id, t.organization_id, s.uuid, t.form_version_id, t.design_version_id, encode(sha256(s.id::text::bytea), 'hex'),
                repeat('0', 64), 'load-test', 1, 'local', 'load-test/' || s.id || '.pdf', 0, 'submitted', s.submitted_at + interval '2 minutes'
            FROM (
                -- gen_random_uuid() in a select list runs per row (a lateral subquery without outer references runs once)
                SELECT gen_random_uuid() AS id, uuid, organization_id, submitted_at FROM submissions
                WHERE device->>'generator' = 'app:seed-load' AND submitted_at IS NOT NULL AND status IN ('submitted', 'in_review', 'changes_requested')
            ) s
            CROSS JOIN template t
            WHERE s.organization_id = t.organization_id AND NOT EXISTS (SELECT 1 FROM renditions r WHERE r.submission_uuid = s.uuid)
            RETURNING 1
        )
        SELECT count(*) FROM inserted;
        SQL;
}

it('renders a 10-page PDF inline and through the queue', function (): void {
    EnvelopeMonitor::phase($this->monitor, 'pdf');
    $process = BudgetJourney::oneOff($this->dir, ['php', '--', '160', '6', '10'], ['PERF_THROWAWAY_INSTANCE' => '1'], (string) file_get_contents(TestImage::repository().'/scripts/perf/pdf.php'));
    $result = j10Json($process);
    $queued = $result['queuedMs'] ?? [];
    $p95 = j10Percentile($queued, 95);
    $this->journey->evidence('J10-pdf.json', [
        'command' => 'php -- 160 6 10 < scripts/perf/pdf.php (one-off)', 'exit_code' => $process->getExitCode(), 'result' => $result,
        'inline_p95_ms' => j10Percentile($result['inlineMs'] ?? [], 95), 'queued_p95_ms' => $p95, 'target_ms' => J10_TARGETS['pdf_queued_ms'],
        'verdict' => $p95 !== null && $p95 <= J10_TARGETS['pdf_queued_ms'] ? 'met' : 'missed',
    ]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and($result['pages'] ?? 0)->toBeGreaterThanOrEqual(10)
        ->and($queued)->toHaveCount(10);
});

it('measures the admin pages at the 1.0 volume and settings', function (): void {
    EnvelopeMonitor::phase($this->monitor, 'admin-pages');
    // Postgres' I/O counters from here on cover only the two measured scripts (J10-postgres.json).
    BudgetJourney::psql(BudgetJourney::containers($GLOBALS['fsaJ10Uuid'])['postgres']['id'], 'SELECT pg_stat_reset()');
    $process = BudgetJourney::perf('admin-pages.mjs', [], j10PerfEnv());
    $result = json_decode((string) @file_get_contents($this->journey->work.'/perf/admin-pages.json'), true) ?? [];
    $pages = array_map(static fn (array $row): array => isset($row['error']) ? $row : [
        'page' => $row['page'], 'user' => $row['user'], 'doc_p50' => $row['doc']['p50'], 'doc_p95' => $row['doc']['p95'], 'doc_max' => $row['doc']['max'],
        'lw_p95' => $row['lw']['p95'], 'complete_p95' => $row['complete']['p95'], 'html_kb' => $row['htmlKb'], 'load' => $row['load'],
        'verdict' => $row['doc']['p95'] < J10_TARGETS['admin_p95_ms'] && $row['lw']['p95'] < J10_TARGETS['admin_p95_ms'] ? 'met' : 'missed',
    ], $result['pages'] ?? []);
    $interactions = array_map(static fn (array $row): array => [...$row, 'verdict' => $row['p95'] < J10_TARGETS['admin_p95_ms'] ? 'met' : 'missed'], $result['interactions'] ?? []);
    $this->journey->evidence('J10-admin-pages.json', ['command' => 'node scripts/perf/admin-pages.mjs (30 runs, 3 warm-up)', 'exit_code' => $process->getExitCode(),
        'target_p95_ms' => J10_TARGETS['admin_p95_ms'], 'pages' => $pages, 'interactions' => $interactions, 'stderr' => array_slice(explode("\n", trim($process->getErrorOutput())), -10)]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($pages)->toHaveCount(27)
        ->and(array_column($pages, 'error'))->toBe([])
        ->and($interactions)->toHaveCount(4);
});

it('measures the API at the 1.0 volume and settings', function (): void {
    EnvelopeMonitor::phase($this->monitor, 'api');
    $process = BudgetJourney::perf('api.mjs', [], j10PerfEnv());
    $result = json_decode((string) @file_get_contents($this->journey->work.'/perf/api.json'), true) ?? [];
    $endpoints = array_map(static fn (array $row): array => isset($row['error']) ? $row : [...$row, 'verdict' => $row['p95'] < J10_TARGETS['api_p95_ms'] ? 'met' : 'missed'], $result['endpoints'] ?? []);
    $this->journey->evidence('J10-api.json', ['command' => 'node scripts/perf/api.mjs (30 runs, 3 warm-up)', 'exit_code' => $process->getExitCode(),
        'target_p95_ms' => J10_TARGETS['api_p95_ms'], 'endpoints' => $endpoints, 'stderr' => array_slice(explode("\n", trim($process->getErrorOutput())), -10)]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($endpoints)->toHaveCount(23)
        ->and(array_column($endpoints, 'error'))->toBe([]);
});

it('runs a scheduled backup at the 1.0 data volume inside the backup service\'s memory slot', function (): void {
    EnvelopeMonitor::phase($this->monitor, 'backup');
    $state = json_decode((string) file_get_contents($this->dir.'/state.json'), true);
    $zone = is_string($state['settings']['time_zone'] ?? null) ? $state['settings']['time_zone'] : 'UTC';
    $slot = CarbonImmutable::now($zone)->addMinutes(3)->startOfMinute();
    // Recreates the backup service (a fresh cgroup, so memory.peak covers only the idle daemon and this run).
    $schedule = $this->journey->fsa($this->dir, ['config', 'set', 'backup-schedule', $slot->format('H:i'), '--json'], $this->env);
    $containers = BudgetJourney::containers($GLOBALS['fsaJ10Uuid']);
    $database = trim(BudgetJourney::psql($containers['postgres']['id'], "SELECT pg_database_size('form_schema')")->getOutput());
    [$run, $samples] = j10SampleBackup($this->dir, $containers['backup']['id'], $slot->utc(), $slot->utc()->addMinutes(45));
    $cgroup = BudgetJourney::cgroup($containers['backup']['id']);
    $limit = (int) $containers['backup']['memory_limit_mib'];
    $peak = (float) ($cgroup['memory_peak_mib'] ?? 0);
    $unreclaimable = $samples === [] ? null : max(array_map(static fn (array $s): float => round($s['anon'] + $s['shmem'], 1), $samples));
    $this->journey->evidence('J10-backup.json', [
        'command' => 'fsa config set backup-schedule <now + 3 min> --json, then the daemon\'s scheduled run (unencrypted)', 'schedule_exit_code' => $schedule->getExitCode(),
        'data_volume' => ['database_bytes' => (int) $database, 'database_mib' => round((int) $database / 1048576, 1), 'note' => 'after app:seed-load demo 100000 and the perf fixtures (150k audit entries)'],
        'run' => $run, 'limit_mib' => $limit, 'memory_peak_mib' => $peak, 'unreclaimable_peak_mib' => $unreclaimable,
        'margin_rule' => "unreclaimable peak + 30 MiB <= {$limit} MiB (ADR 0083; memory.peak includes page cache the kernel reclaims at the limit)",
        'verdict' => $unreclaimable !== null && $unreclaimable + 30 <= $limit ? 'met' : 'missed', 'm12_demo_peak_mib' => 68,
        'cgroup' => $cgroup, 'samples' => $samples,
    ]);

    expect($schedule->getExitCode())->toBe(0, $schedule->getErrorOutput().$schedule->getOutput())
        ->and($run)->toMatchArray(['kind' => 'scheduled', 'status' => 'succeeded'])
        ->and($samples)->not->toBe([])
        ->and($cgroup['memory_events']['oom_kill'] ?? null)->toBe(0);
});

/**
 * Waits for the ledger line (succeeded or failed) of the scheduled run at `$slot` or later, never an earlier one (the
 * default 03:30 slot may have run during the journey's earlier phases), while sampling the backup container's anonymous and
 * shared memory every second (CgroupReader, as J08 does).
 *
 * @return array{0: array<string, mixed>, 1: list<array{at: string, anon: float, shmem: float, file: float}>}
 */
function j10SampleBackup(string $dir, string $container, CarbonImmutable $slot, CarbonImmutable $until): array
{
    $reader = CgroupReader::start($GLOBALS['fsaJ10']->release->appReference);
    $samples = [];
    try {
        while (CarbonImmutable::now('UTC')->lessThan($until)) {
            $read = $reader?->read([$container])[$container] ?? null;
            if ($read !== null) {
                $samples[] = ['at' => CarbonImmutable::now('UTC')->format('H:i:s'), ...$read];
            }
            $lines = is_file($dir.'/backups/runs.log') ? file($dir.'/backups/runs.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
            $runs = array_values(array_filter(array_map(static fn (string $line): mixed => json_decode($line, true), $lines ?: []), is_array(...)));
            $run = array_find(array_reverse($runs), static fn (array $r): bool => ($r['kind'] ?? null) === 'scheduled' && in_array($r['status'] ?? null, ['succeeded', 'failed'], true)
                && is_string($r['started_at'] ?? null) && CarbonImmutable::parse($r['started_at'])->greaterThanOrEqualTo($slot));
            if ($run !== null) {
                return [$run, $samples];
            }
            usleep(1_000_000);
        }
    } finally {
        $reader?->stop();
    }

    return [[], $samples];
}

it('kept every container alive through all of it: no OOM kill, no restart', function (): void {
    EnvelopeMonitor::phase($this->monitor, 'end');
    sleep(3);
    $summary = EnvelopeMonitor::finish($GLOBALS['fsaJ10Monitor'], $this->monitor);
    $containers = BudgetJourney::containers($GLOBALS['fsaJ10Uuid']);
    $cgroups = array_map(static fn (array $c): array => BudgetJourney::cgroup($c['id']), $containers);
    $tracked = collect($summary['containers']);
    $peaks = [];
    foreach ($containers as $service => $state) {
        $monitored = $tracked->last(static fn (array $c): bool => $c['service'] === $service && ! $c['oneoff']) ?? [];
        $peaks[$service] = ['limit_mib' => $state['memory_limit_mib'], 'peak_mib' => $monitored['mem_peak_mib'] ?? null, 'anon_shmem_peak_mib' => $monitored['anon_shmem_peak_mib'] ?? null, 'cpu_peak_pct' => $monitored['cpu_peak_pct'] ?? null,
            'memory_peak_mib' => $cgroups[$service]['memory_peak_mib'] ?? null, 'memory_now_mib' => $cgroups[$service]['memory_now_mib'] ?? null, 'memory_events' => $cgroups[$service]['memory_events'] ?? null, 'cpu_seconds' => $cgroups[$service]['cpu_seconds'] ?? null,
            'oom_killed' => $state['oom_killed'], 'restart_count' => $state['restart_count'], 'status' => $state['status']];
    }
    $transient = $tracked->filter(static fn (array $c): bool => $c['oneoff'] || $c['service'] === 'ops' || ! in_array($c['service'], J10_SERVICES, true))
        ->map(static fn (array $c): array => ['service' => $c['service'], 'created_in' => $c['created_in'], 'mem_peak_mib' => $c['mem_peak_mib'], 'cpu_peak_pct' => $c['cpu_peak_pct'], 'pinned' => $c['pinned'], 'pin_error' => $c['pin_error'], 'exit_codes' => $c['exit_codes']])->values()->all();
    $this->journey->evidence('J10-peaks.json', ['cpuset' => $summary['cpuset'], 'services' => $peaks, 'transient' => $transient, 'phases' => $summary['phases'], 'incidents' => $summary['incidents']]);
    $this->journey->evidence('J10-postgres.json', ['covers' => 'admin-pages.mjs and api.mjs (pg_stat_reset() before them)', ...BudgetJourney::postgresStats($containers['postgres']['id'])]);

    expect($summary['final'])->toBeTrue()
        ->and($summary['incidents'])->toBe([])
        ->and(array_keys($containers))->toBe(J10_SERVICES)
        ->and(array_unique(array_column($containers, 'status')))->toBe(['running'])
        ->and(array_unique(array_column($containers, 'oom_killed')))->toBe([false])
        ->and(array_unique(array_column($containers, 'restart_count')))->toBe([0])
        ->and(array_filter($cgroups, static fn (array $c): bool => ($c['memory_events']['oom_kill'] ?? 0) > 0))->toBe([])
        ->and($tracked->filter(static fn (array $c): bool => $c['service'] !== 'other' && ! $c['pinned'])->pluck('name')->all())->toBe([]);
});

it('uninstalls without leaving anything labelled behind', function (): void {
    $process = $this->journey->fsa($this->dir, ['uninstall', '--yes', '--json'], $this->env);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and(InstanceJourney::labelled($GLOBALS['fsaJ10Uuid']))->toBe(['containers' => [], 'volumes' => [], 'networks' => []]);
});
