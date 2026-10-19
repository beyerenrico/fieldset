<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\Docker\InstanceJourney;
use Tests\Support\Docker\RestoreJourney;
use Tests\Support\Docker\SharedHarness;
use Tests\Support\Docker\TestImage;
use Tests\Support\Docker\UpgradeJourney;

/*
 * J7 (BUILD_PROMPT_V2 §11, R33–R37, R55, R57, R62, AE7, F5; plan U18; ADR 0086) with real Docker. Releases built
 * locally into the harness: 2.0.0-rc.1-test (installed), and three targets derived from it: rc.2-test adds a migration
 * (the happy upgrade), rc.3-test answers 503 to everything (its health check fails), rc.4-test has an `fsa` entry point
 * that crashes. In local mode (`--demo`): the crashing target rolls back (exit 5); AE7: the unhealthy target rolls back
 * while an API client writes until the gate rises (the previous digests run again, the database equals the safety
 * backup, no acknowledged write is lost, exit 5); the happy upgrade runs on after its foreground `fsa` was killed, and
 * `fsa status` follows it; `fsa rollback` returns to rc.1 from the safety backup (the migration's table gone); a second
 * rollback and an older `--to` are refused (exit 7). In proxy mode (`--behind-proxy port`): AE7 again, a write during
 * the gate gets 503 and is retried after the rollback. Everything is named `fsa-test-j07…` and removed afterwards.
 */

const J07_FROM = '2.0.0-rc.1-test';

const J07_HAPPY = '2.0.0-rc.2-test';

const J07_UNHEALTHY = '2.0.0-rc.3-test';

const J07_CRASHING = '2.0.0-rc.4-test';

const J07_PROXY_HOST = 'j07.fsa-test.example';

beforeAll(function (): void {
    if (TestImage::dockerUnavailableReason() !== null) {
        return;
    }

    try {
        [$harness] = SharedHarness::get();
        $from = $harness->publish(J07_FROM);
        foreach ([J07_HAPPY => UpgradeJourney::migratingTarget(), J07_UNHEALTHY => UpgradeJourney::unhealthyTarget(), J07_CRASHING => UpgradeJourney::crashingTarget()] as $version => [$lines, $files]) {
            $GLOBALS['fsaJ07Targets'][$version] = $harness->publishDerived($version, $from, $lines, $files);
        }
        $GLOBALS['fsaJ07'] = new InstanceJourney($harness, $from, $harness->directory.'/j07');
        $GLOBALS['fsaJ07Url'] = $harness->containerReleaseUrl();
        $GLOBALS['fsaJ07Evidence'] = [];
    } catch (Throwable $exception) {
        $GLOBALS['fsaJ07Error'] = $exception;
    }
});

afterAll(function (): void {
    if (isset($GLOBALS['fsaJ07'])) {
        InstanceJourney::cleanup($GLOBALS['fsaJ07']->work.'/fsa-test-j07', $GLOBALS['fsaJ07Uuid'] ?? null);
        InstanceJourney::cleanup($GLOBALS['fsaJ07']->work.'/fsa-test-j07p', $GLOBALS['fsaJ07ProxyUuid'] ?? null);
    }
    unset($GLOBALS['fsaJ07'], $GLOBALS['fsaJ07Error'], $GLOBALS['fsaJ07Uuid'], $GLOBALS['fsaJ07ProxyUuid'], $GLOBALS['fsaJ07Targets'], $GLOBALS['fsaJ07Url'], $GLOBALS['fsaJ07Evidence']);
});

beforeEach(function (): void {
    $reason = TestImage::dockerUnavailableReason();
    if ($reason !== null) {
        expect(getenv('FSA_REQUIRE_DOCKER'))->not->toBe('1', $reason);
        $this->markTestSkipped($reason.' (install journeys need a Docker engine)');
    }
    if (isset($GLOBALS['fsaJ07Error'])) {
        throw $GLOBALS['fsaJ07Error'];
    }
    $this->journey = $GLOBALS['fsaJ07'];
    $this->dir = $this->journey->work.'/fsa-test-j07';
    $this->proxyDir = $this->journey->work.'/fsa-test-j07p';
    $this->upgradeEnv = ['FSA_RELEASE_URL' => $GLOBALS['fsaJ07Url']];
});

/** @param  array<string, mixed>  $values */
function j07Record(string $key, array $values): void
{
    $GLOBALS['fsaJ07Evidence'][$key] = $values;
}

/**
 * AE7: starts `fsa upgrade --to <unhealthy>` and writes through the API until a write is answered 503 (the gate rose),
 * then probes that write while the upgrade runs, and retries it after the upgrade ended.
 *
 * @return array{process: Process, acked: list<string>, gated: string|null, statuses: list<int>, retried: int, digest_live: string}
 */
function j07WriteThroughUpgrade(InstanceJourney $journey, string $dir, string $uuid, array $writer, int $port, array $env, ?string $host = null): array
{
    $http = InstanceJourney::http($port);
    $process = UpgradeJourney::start($journey->fsaProcess($dir, ['upgrade', '--to', J07_UNHEALTHY, '--json'], $env));
    [$acked, $gated, $statuses] = [[], null, []];
    while ($process->isRunning()) {
        $submission = $gated ?? (string) Str::uuid();
        $status = UpgradeJourney::write($http, $writer, $submission, $host);
        if ($gated === null && in_array($status, [200, 201], true)) {
            $acked[] = $submission;

            continue;
        }
        $gated ??= $status === 503 ? $submission : null;
        $statuses[] = $status;
        usleep($gated === null ? 200_000 : 3_000_000);
    }
    $digest = UpgradeJourney::digest($uuid, $gated);
    $retried = 0;
    UpgradeJourney::await(static function () use ($http, $writer, $gated, $host, &$retried): bool {
        $retried = $gated === null ? 0 : UpgradeJourney::write($http, $writer, $gated, $host);

        return in_array($retried, [200, 201], true);
    }, 120, 2);

    return ['process' => $process, 'acked' => $acked, 'gated' => $gated, 'statuses' => $statuses, 'retried' => $retried, 'digest_live' => $digest];
}

it('installs 2.0.0-rc.1-test with demo data in local mode and an API client', function (): void {
    $install = $this->journey->install($this->dir, ['--demo', '--org', 'J7 Org', '--owner-email', 'owner@example.test', '--owner-name', 'Pat Owner', '--locale', 'en', '--yes', '--json']);
    $json = InstanceJourney::json($install);
    $GLOBALS['fsaJ07Uuid'] = InstanceJourney::uuid($this->dir);
    $GLOBALS['fsaJ07Port'] = $json['data']['port'] ?? null;
    $GLOBALS['fsaJ07Writer'] = UpgradeJourney::writer($GLOBALS['fsaJ07Uuid']);
    $http = InstanceJourney::http($GLOBALS['fsaJ07Port']);
    $first = (string) Str::uuid();
    $GLOBALS['fsaJ07Before'] = [$first];
    j07Record('install', ['exit_code' => $install->getExitCode(), 'version' => $json['data']['version'] ?? null]);

    expect($install->getExitCode())->toBe(0, $install->getErrorOutput()."\n".$install->getOutput())
        ->and($json['data']['version'])->toBe(J07_FROM)
        ->and(UpgradeJourney::write($http, $GLOBALS['fsaJ07Writer'], $first))->toBe(201);
});

it('rolls back a target whose fsa entry point crashes (exit 5), and rc.1 keeps serving', function (): void {
    $uuid = $GLOBALS['fsaJ07Uuid'];
    $upgrade = $this->journey->fsa($this->dir, ['upgrade', '--to', J07_CRASHING, '--json'], $this->upgradeEnv);
    $json = InstanceJourney::json($upgrade);
    $status = InstanceJourney::json($this->journey->fsa($this->dir, ['status', '--json']));
    j07Record('crashing_target', ['exit_code' => $upgrade->getExitCode(), 'error' => $json['errors'][0] ?? null, 'running' => $json['data']['running'] ?? null, 'status_version' => $status['data']['version'] ?? null]);

    expect($upgrade->getExitCode())->toBe(5, $upgrade->getErrorOutput()."\n".$upgrade->getOutput())
        ->and($json['errors'][0]['code'])->toBe('child_crashed')
        ->and($json['data']['logs'])->toContain('crashes on purpose')
        ->and($json['data']['running'])->toBe(J07_FROM)
        ->and(UpgradeJourney::image($uuid, 'app'))->toBe(json_decode((string) file_get_contents($this->dir.'/state.json'), true)['digests']['app'])
        ->and($status['data'])->toMatchArray(['version' => J07_FROM, 'health' => 'ok', 'operation' => null])
        ->and(InstanceJourney::http($GLOBALS['fsaJ07Port'])->get('/up')->getStatusCode())->toBe(200);
});

it('AE7 in local mode: the unhealthy target rolls back while a client writes until the gate rises (exit 5)', function (): void {
    $uuid = $GLOBALS['fsaJ07Uuid'];
    $digests = json_decode((string) file_get_contents($this->dir.'/state.json'), true)['digests'];
    $run = j07WriteThroughUpgrade($this->journey, $this->dir, $uuid, $GLOBALS['fsaJ07Writer'], $GLOBALS['fsaJ07Port'], $this->upgradeEnv);
    $json = InstanceJourney::json($run['process']);
    $safety = $this->dir.'/backups/'.($json['data']['safety_backup'] ?? 'none');
    $GLOBALS['fsaJ07Before'] = [...$GLOBALS['fsaJ07Before'], ...$run['acked'], ...($run['gated'] === null ? [] : [$run['gated']])];
    j07Record('ae7_local', ['exit_code' => $run['process']->getExitCode(), 'error' => $json['errors'][0] ?? null, 'acknowledged_writes' => count($run['acked']),
        'statuses_after_the_gate' => array_count_values(array_map(strval(...), $run['statuses'])), 'retried_after_rollback' => $run['retried']]);

    expect($run['process']->getExitCode())->toBe(5, $run['process']->getErrorOutput()."\n".$run['process']->getOutput())
        ->and($json['errors'][0]['code'])->toBe('target_unhealthy')
        ->and($run['acked'])->not->toBeEmpty()
        ->and($run['gated'])->not->toBeNull()
        ->and($run['statuses'])->toContain(503)
        ->and(UpgradeJourney::image($uuid, 'app'))->toBe($digests['app'])
        ->and(json_decode((string) file_get_contents($this->dir.'/state.json'), true)['digests'])->toBe($digests)
        ->and(UpgradeJourney::present($uuid, $run['acked']))->toBe(count($run['acked']))
        ->and($run['digest_live'])->toBe(UpgradeJourney::backupDigest($uuid, $safety, $run['gated']))
        ->and($run['retried'])->toBeIn([200, 201]);
});

it('upgrades to rc.2 in a detached supervisor that keeps running when the foreground fsa is killed; fsa status follows it', function (): void {
    $uuid = $GLOBALS['fsaJ07Uuid'];
    $foreground = UpgradeJourney::start($this->journey->fsaProcess($this->dir, ['upgrade', '--to', J07_HAPPY, '--json'], $this->upgradeEnv));
    $journal = $this->dir.'/operations/current.json';
    $quiescing = UpgradeJourney::await(static fn (): bool => is_file($journal) && str_contains((string) file_get_contents($journal), '"quiesce"'), 300, 2);
    foreach (UpgradeJourney::opsContainers($this->dir) as $ops) { // this instance's foreground ops container only
        RestoreJourney::docker(['kill', $ops]);
    }
    $foreground->stop(5, SIGKILL);
    $supervisor = UpgradeJourney::running('app.form-schema.role', 'supervisor', $uuid);
    $during = InstanceJourney::json($this->journey->fsa($this->dir, ['status', '--json']));
    $finished = UpgradeJourney::await(static fn (): bool => UpgradeJourney::running('app.form-schema.role', 'supervisor', $uuid) === [], 1200, 5);
    $after = InstanceJourney::json($this->journey->fsa($this->dir, ['status', '--json']));
    $state = json_decode((string) file_get_contents($this->dir.'/state.json'), true);
    $history = $state['history'];
    j07Record('happy_upgrade', ['quiescing_seen' => $quiescing, 'supervisor_after_kill' => count($supervisor), 'status_during' => $during['data']['operation'] ?? null,
        'finished' => $finished, 'status_after' => ['version' => $after['data']['version'] ?? null, 'health' => $after['data']['health'] ?? null], 'last_history' => end($history)]);

    expect($quiescing)->toBeTrue()
        ->and($supervisor)->toHaveCount(1)
        ->and($during['data']['operation']['operation'] ?? null)->toBe('upgrade')
        ->and($finished)->toBeTrue()
        ->and($after['data'])->toMatchArray(['version' => J07_HAPPY, 'health' => 'ok', 'operation' => null])
        ->and($state['previous']['version'])->toBe(J07_FROM)
        ->and(end($history))->toMatchArray(['event' => 'upgraded', 'from' => J07_FROM, 'to' => J07_HAPPY])
        ->and(RestoreJourney::sql($uuid, 'SELECT note FROM '.UpgradeJourney::MARKER_TABLE))->toBe('migrated by the upgrade child')
        ->and(UpgradeJourney::image($uuid, 'app'))->toBe($state['digests']['app'])
        ->and(UpgradeJourney::present($uuid, $GLOBALS['fsaJ07Before']))->toBe(count($GLOBALS['fsaJ07Before']))
        ->and(glob($this->dir.'/backups/safety-upgrade-*'))->toHaveCount(1);
});

it('fsa rollback returns to rc.1 from the safety backup and says what it loses; the migration\'s table is gone', function (): void {
    $uuid = $GLOBALS['fsaJ07Uuid'];
    $after = (string) Str::uuid();
    expect(UpgradeJourney::write(InstanceJourney::http($GLOBALS['fsaJ07Port']), $GLOBALS['fsaJ07Writer'], $after))->toBe(201);
    $volumeBefore = RestoreJourney::storageVolume($uuid);

    $rollback = $this->journey->fsa($this->dir, ['rollback', '--yes', '--json']);
    $json = InstanceJourney::json($rollback);
    $state = json_decode((string) file_get_contents($this->dir.'/state.json'), true);
    $volume = RestoreJourney::storageVolume($uuid);
    j07Record('rollback', ['exit_code' => $rollback->getExitCode(), 'data' => $json['data'] ?? null, 'error' => $json['errors'][0] ?? null]);

    expect($rollback->getExitCode())->toBe(0, $rollback->getErrorOutput()."\n".$rollback->getOutput())
        ->and($json['data'])->toMatchArray(['from' => J07_HAPPY, 'to' => J07_FROM])
        ->and($json['data']['loss']['submissions'])->toBeGreaterThanOrEqual(1)
        ->and($state['version'])->toBe(J07_FROM)->and($state['previous'])->toBeNull()
        ->and(UpgradeJourney::image($uuid, 'app'))->toBe($state['digests']['app'])
        ->and(RestoreJourney::sql($uuid, "SELECT to_regclass('".UpgradeJourney::MARKER_TABLE."') IS NULL"))->toBe('t')
        ->and(UpgradeJourney::present($uuid, $GLOBALS['fsaJ07Before']))->toBe(count($GLOBALS['fsaJ07Before']))
        ->and(UpgradeJourney::present($uuid, [$after]))->toBe(0)
        ->and($volume)->toBe($state['settings']['storage_volume'])->not->toBe($volumeBefore)
        ->and(trim(RestoreJourney::docker(['volume', 'ls', '-q', '--filter', 'name=^'.$volumeBefore.'$'])->getOutput()))->toBe('')
        // the automatic rollbacks above wrote theirs before the safety backup; the newest names this rollback's backup
        ->and(RestoreJourney::sql($uuid, "SELECT properties::jsonb ->> 'backup' FROM activity_log WHERE event = 'instance.rolled_back' ORDER BY id DESC LIMIT 1"))->toBe($json['data']['backup']);
});

it('refuses a second rollback and an older --to (exit 7)', function (): void {
    $again = $this->journey->fsa($this->dir, ['rollback', '--yes', '--json']);
    $older = $this->journey->fsa($this->dir, ['upgrade', '--to', '2.0.0-alpha.1-test', '--json'], $this->upgradeEnv);
    j07Record('refusals', ['rollback' => InstanceJourney::json($again)['errors'][0] ?? null, 'older' => InstanceJourney::json($older)['errors'][0] ?? null]);

    expect($again->getExitCode())->toBe(7)->and(InstanceJourney::json($again)['errors'][0]['code'])->toBe('no_previous_release')
        ->and($older->getExitCode())->toBe(7)->and(InstanceJourney::json($older)['errors'][0]['code'])->toBe('downgrade_refused');
});

it('AE7 in proxy mode: a write during the gate gets 503 and is retried after the rollback (exit 5)', function (): void {
    $install = $this->journey->install($this->proxyDir, ['--behind-proxy', 'port', '--url', 'http://'.J07_PROXY_HOST, '--demo', '--org', 'J7 Proxy', '--owner-email', 'owner@example.test', '--owner-name', 'Pat Owner', '--locale', 'en', '--yes', '--json']);
    $installed = InstanceJourney::json($install);
    expect($install->getExitCode())->toBe(0, $install->getErrorOutput()."\n".$install->getOutput());
    $uuid = $GLOBALS['fsaJ07ProxyUuid'] = InstanceJourney::uuid($this->proxyDir);
    $digests = json_decode((string) file_get_contents($this->proxyDir.'/state.json'), true)['digests'];

    $run = j07WriteThroughUpgrade($this->journey, $this->proxyDir, $uuid, UpgradeJourney::writer($uuid), (int) $installed['data']['port'], $this->upgradeEnv, J07_PROXY_HOST);
    $json = InstanceJourney::json($run['process']);
    $safety = $this->proxyDir.'/backups/'.($json['data']['safety_backup'] ?? 'none');
    j07Record('ae7_proxy', ['exit_code' => $run['process']->getExitCode(), 'error' => $json['errors'][0] ?? null, 'acknowledged_writes' => count($run['acked']),
        'statuses_after_the_gate' => array_count_values(array_map(strval(...), $run['statuses'])), 'retried_after_rollback' => $run['retried']]);

    expect($run['process']->getExitCode())->toBe(5, $run['process']->getErrorOutput()."\n".$run['process']->getOutput())
        ->and($installed['data']['mode'])->toBe('proxy')
        ->and($run['statuses'])->toContain(503)
        ->and(UpgradeJourney::image($uuid, 'app'))->toBe($digests['app'])
        ->and(UpgradeJourney::present($uuid, $run['acked']))->toBe(count($run['acked']))
        ->and($run['digest_live'])->toBe(UpgradeJourney::backupDigest($uuid, $safety, $run['gated']))
        ->and($run['retried'])->toBeIn([200, 201]);
});

it('writes a redacted transcript for the milestone evidence', function (): void {
    $this->journey->evidence('J07-upgrade.json', ['releases' => [J07_FROM, J07_HAPPY, J07_UNHEALTHY, J07_CRASHING], 'built' => date('Y-m-d'), 'revision' => TestImage::revision(), ...$GLOBALS['fsaJ07Evidence']]);
    $json = (string) file_get_contents(TestImage::repository().'/docs/agent/milestones/evidence/'.InstanceJourney::evidenceMilestone().'/J07-upgrade.json');

    expect($json)->toContain('ae7_local')->not->toContain(TestImage::repository())->not->toContain(explode('|', $GLOBALS['fsaJ07Writer']['token'])[1]);
});
