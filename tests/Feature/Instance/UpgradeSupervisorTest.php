<?php

declare(strict_types=1);

use App\Domain\Instance\Backup\BackupStore;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Operations\Ledger;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\Upgrade\StageRenderer;
use Tests\Support\Instance\BackupSetFixture;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;
use Tests\Support\Instance\UpgradeFixture;
use Tests\Support\Release\ReleaseFixtures;

/*
 * The upgrade supervisor's state transitions (plan U18 execution note, KTD19, R33–R36, R55, R57, R62, R69, ADR 0086),
 * against the recording Docker gateway: `fsa upgrade --supervise` is the detached supervisor's own run, in-process. Each
 * test drives one path of the upgrade journal: the happy order, the refusals before anything changes (exit 7, 3, 0), the
 * failures before the safety backup (exit 9 with the previous services running again), the failures from the safety
 * backup until the commit (automatic rollback, exit 5), and a rollback that fails itself (exit 8).
 */

beforeEach(function (): void {
    $this->upgrade = new UpgradeFixture;
    $this->dir = $this->upgrade->instance->directory->root;
    $this->config = $this->upgrade->restore->config;
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
});

/** @return list<string> */
function upgradePhases(ConfigFixture $config): array
{
    return array_map(static fn (array $phase): string => $phase['name'], $config->archivedJournal('upgrade')->phases());
}

it('upgrades in the KTD19 order and commits the target release with the previous one kept for a rollback', function (): void {
    $mark = $this->config->mark();

    [$code, $json] = $this->upgrade->upgrade();
    $state = $this->upgrade->instance->state();
    $pulls = array_column($this->config->callsSince($mark, 'pullImage'), 'reference');
    $history = $state->history();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and(upgradePhases($this->config))->toBe(['release', 'doctor', 'pull', 'quiesce', 'safety_backup', 'migrate', 'start', 'smoke', 'commit', 'open'])
        ->and($json['status'])->toBe('ok')->and($json['changed'])->toBeTrue()
        ->and($json['data'])->toMatchArray(['from' => '2.0.0-alpha.1', 'to' => UpgradeFixture::TARGET, 'safety_backup' => $this->upgrade->safetyBackups[0], 'migrated' => ['2026_10_20_000000_fsa_test_upgrade']])
        ->and($state->version())->toBe(UpgradeFixture::TARGET)
        ->and($state->digests()['app'])->toBe(UpgradeFixture::targetImage())
        ->and($state->previous())->toMatchArray(['version' => '2.0.0-alpha.1'])
        ->and($state->previous()['digests']['app'])->toBe(InstanceCommandFixture::appImage())
        ->and($state->previous()['compose'])->toContain(ReleaseFixtures::APP_DIGEST)
        ->and($this->upgrade->compose())->toContain(UpgradeFixture::TARGET_DIGEST)->not->toContain(ReleaseFixtures::APP_DIGEST)
        ->and($pulls[0])->toBe(UpgradeFixture::targetImage())
        ->and($this->upgrade->gate()['raised'])->toBeFalse()
        ->and($history[array_key_last($history)])->toMatchArray(['event' => 'upgraded', 'from' => '2.0.0-alpha.1', 'to' => UpgradeFixture::TARGET])
        ->and(new OperationJournal($this->upgrade->instance->directory)->current())->toBeNull()
        ->and(is_dir($this->dir.'/operations/upgrade-'.substr($this->config->archivedJournal('upgrade')->id(), 0, 12)))->toBeFalse();
});

it('removes the verified copy of the safety backup before migrating: the way back prepares its own (M12 gate)', function (): void {
    $scratch = null;
    $this->upgrade->stage = function () use (&$scratch): void { // runs while the child migrates
        $scratch = glob($this->dir.'/staging/restore-*');
    };

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($scratch)->toBe([]) // no second copy of the dump and the storage archive on disk during the upgrade
        ->and($this->config->archivedJournal('upgrade')->phaseData('safety_backup')['sums'] ?? null)->toMatch('/^[0-9a-f]{64}$/');
});

it('keeps the app answering 503 behind the gate while writers stop, and starts only the app and changed services before the commit', function (): void {
    $mark = $this->config->mark();
    $gates = [];
    $this->upgrade->instance->docker->onCall['composeStop'] = function (array $args) use (&$gates): void {
        $gates[] = [implode(',', $args['services']), $this->upgrade->gate()['raised'], $this->upgrade->gate()['operation']];
    };

    [$code] = $this->upgrade->upgrade();
    $ups = $this->upgrade->ups($mark);

    expect($code)->toBe(0)
        ->and($gates)->toBe([['scheduler,backup', true, 'upgrade'], ['queue', true, 'upgrade']])
        ->and(array_column($this->config->callsSince($mark, 'composeStop'), 'timeout'))->toBe([null, 100])
        ->and(array_filter($ups, static fn (string $services): bool => str_contains($services, 'app')))->not->toBeEmpty()
        ->and($ups[0])->not->toContain('queue')->not->toContain('scheduler')->not->toContain('backup')->not->toContain('mailpit')
        ->and($ups[array_key_last($ups)])->toBe('queue,scheduler,backup');
});

it('runs the target image as a child without the socket, on the instance network, with the instance read-only', function (): void {
    [$code] = $this->upgrade->upgrade();
    $state = $this->upgrade->instance->state();
    $child = array_values(array_filter($this->upgrade->instance->runSpecs('fsa'), static fn ($spec): bool => ($spec->command[0] ?? null) === 'upgrade-child'))[0];
    $stage = $child->env[StageRenderer::STAGE_ENV];

    expect($code)->toBe(0)
        ->and($child->image)->toBe(UpgradeFixture::targetImage())
        ->and($child->network)->toBe($state->project().'_default')
        ->and($child->detach)->toBeTrue()->and($child->remove)->toBeFalse()
        ->and($child->volumes)->toContain($this->dir.':'.$this->dir.':ro', $stage.':'.$stage)
        ->and(json_encode($child))->not->toContain('docker.sock')
        ->and($child->user)->toBe($state->containerUid().':'.$state->hostGid())
        ->and($this->upgrade->childRequests[0])->toMatchArray(['version' => UpgradeFixture::TARGET, 'from' => '2.0.0-alpha.1'])
        ->and($this->upgrade->childRequests[0]['digests']['app'])->toBe(UpgradeFixture::targetImage())
        ->and($this->upgrade->instance->docker->called('removeContainer'))->toContain(['id' => UpgradeFixture::CHILD_ID]);
});

it('refuses an older --to, a major without --major and a source below the oldest upgrade source with exit 7, before anything changes', function (string $version, ?string $upgradesFrom, string $error, string $names): void {
    $this->upgrade->serve($version, upgradesFrom: $upgradesFrom);
    $mark = $this->config->mark();

    [$code, $json] = $this->upgrade->upgrade(['--to' => $version]);

    expect($code)->toBe(7, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe($error)
        ->and($json['errors'][0]['hint'])->toContain($names)
        ->and($this->config->callsSince($mark, 'composeStop'))->toBe([])
        ->and($this->upgrade->gate()['raised'])->toBeFalse()
        ->and($this->upgrade->instance->state()->version())->toBe('2.0.0-alpha.1')
        ->and(new OperationJournal($this->upgrade->instance->directory)->current())->toBeNull();
})->with([
    'older' => ['2.0.0-alpha.0', '2.0.0-alpha.0', 'downgrade_refused', 'fsa rollback'],
    'major' => ['3.0.0', null, 'major_upgrade', '--major'],
    'too old a source' => ['2.0.0-beta.1', '2.0.0-alpha.3', 'upgrade_path', 'fsa upgrade --to 2.0.0-alpha.3'],
]);

it('crosses a major with --major', function (): void {
    $this->upgrade->serve('3.0.0');

    [$code, $json] = $this->upgrade->upgrade(['--to' => '3.0.0', '--major' => true]);

    expect($code)->toBe(0, (string) json_encode($json))->and($this->upgrade->instance->state()->version())->toBe('3.0.0');
});

it('changes nothing when the instance already runs the target', function (): void {
    $this->upgrade->serve('2.0.0-alpha.1', ReleaseFixtures::APP_DIGEST);
    $mark = $this->config->mark();

    [$code, $json] = $this->upgrade->upgrade(['--to' => '2.0.0-alpha.1']);

    expect($code)->toBe(0)->and($json['changed'])->toBeFalse()->and($json['data']['to'])->toBe('2.0.0-alpha.1')
        ->and($this->config->callsSince($mark, 'composeStop'))->toBe([]);
});

it('takes the newest release without --to, and exits 2 when there is no latest release yet', function (): void {
    $this->upgrade->latest = UpgradeFixture::TARGET;

    [$code, $json] = $this->upgrade->upgrade(['--to' => null]);

    expect($code)->toBe(0, (string) json_encode($json))->and($this->upgrade->instance->state()->version())->toBe(UpgradeFixture::TARGET);

    $this->upgrade->latest = null;
    [$code, $json] = $this->upgrade->upgrade(['--to' => null]);

    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe('no_latest_release')->and($json['errors'][0]['hint'])->toContain('--to');
});

it('exits 3 before stopping anything when the safety backup and a second database do not fit', function (): void {
    $this->upgrade->restore->freeBytes = 10 * 1024 ** 2;
    $mark = $this->config->mark();

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(3)->and($json['errors'][0]['code'])->toBe('low_disk_space')
        ->and($this->config->callsSince($mark, 'composeStop'))->toBe([])
        ->and($this->upgrade->gate()['raised'])->toBeFalse();
});

it('exits 9 on a drain timeout without --drop-jobs, with the gate lowered and the previous services running again', function (): void {
    $this->upgrade->drain = [['pending' => 3, 'reserved' => 1, 'delayed' => 0]];
    $mark = $this->config->mark();

    [$code, $json] = $this->upgrade->upgrade();
    $ups = $this->upgrade->ups($mark);

    expect($code)->toBe(9, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('drain_timeout')
        ->and($json['errors'][0]['hint'])->toContain('--drop-jobs')
        ->and($this->upgrade->gate()['raised'])->toBeFalse()
        ->and($ups[array_key_last($ups)])->toBe('queue,scheduler,backup')
        ->and($this->upgrade->safetyBackups)->toBe([])
        ->and($this->upgrade->instance->state()->version())->toBe('2.0.0-alpha.1')
        ->and(new OperationJournal($this->upgrade->instance->directory)->current())->toBeNull();
});

it('saves the jobs left pending or reserved after the workers stopped, then drops them, with --drop-jobs, and goes on', function (): void {
    $this->upgrade->drain = [['pending' => 2, 'reserved' => 1, 'delayed' => 4]];
    $order = [];
    $this->upgrade->instance->docker->onCall['composeStop'] = function (array $args) use (&$order): void {
        $order[] = 'stop:'.implode(',', $args['services']);
    };
    $this->upgrade->instance->docker->onCall['composeRun'] = function (array $args) use (&$order): void {
        if (($args['command'][2] ?? null) === 'instance:queue-drain' && count($args['command']) > 4) {
            $order[] = $args['command'][3];
        }
    };

    [$code, $json] = $this->upgrade->upgrade(['--drop-jobs' => true]);
    $file = $this->dir.'/'.$json['data']['dropped_jobs']['file'];

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($order)->toBe(['stop:scheduler,backup', 'stop:queue', '--export', '--drop'])
        ->and($json['data']['dropped_jobs']['count'])->toBe(3)
        ->and(substr(sprintf('%o', fileperms($file)), -3))->toBe('600')
        ->and(json_decode((string) file_get_contents($file), true)['payloads'])->toHaveCount(3)
        ->and($json['warnings'][0]['code'] ?? null)->toBe('jobs_dropped');
});

it('names the saved jobs when the upgrade stops after dropping them', function (): void {
    $this->upgrade->drain = [['pending' => 1, 'reserved' => 0, 'delayed' => 0]];
    $this->upgrade->damagedSafety = true;

    [$code, $json] = $this->upgrade->upgrade(['--drop-jobs' => true]);

    expect($code)->toBe(9, (string) json_encode($json))
        ->and($json['data']['dropped_jobs']['count'])->toBe(1)
        ->and(array_column($json['warnings'], 'code'))->toContain('jobs_dropped');
});

it('exits 9 before any migration when the safety backup fails its verification', function (): void {
    $this->upgrade->damagedSafety = true;
    $mark = $this->config->mark();

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(9, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('safety_backup_unverified')
        ->and($this->upgrade->childRequests)->toBe([])
        ->and($this->upgrade->gate()['raised'])->toBeFalse()
        ->and($this->upgrade->ups($mark)[array_key_last($this->upgrade->ups($mark))])->toBe('queue,scheduler,backup');
});

it('rolls back when the child fails: the safety backup restored atomically without the carry, Valkey flushed, the previous release running', function (): void {
    $this->upgrade->child = 'fail';
    $before = $this->upgrade->instance->state();

    [$code, $json] = $this->upgrade->upgrade();
    $state = $this->upgrade->instance->state();
    $id = substr($this->config->archivedJournal('upgrade')->id(), 0, 12);
    $finish = $this->upgrade->restore->instance->oneOffCalls('instance:restore-finish')[0]['command'];
    $history = $state->history();

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($json['status'])->toBe('rolled_back')
        ->and($json['errors'][0]['code'])->toBe('migration_failed')
        ->and($json['data'])->toMatchArray(['from' => '2.0.0-alpha.1', 'to' => UpgradeFixture::TARGET, 'running' => '2.0.0-alpha.1', 'safety_backup' => $this->upgrade->safetyBackups[0]])
        ->and(upgradePhases($this->config))->toContain('rollback_database', 'rollback_finish', 'rollback_swap', 'rollback_verify', 'rollback_open', 'rollback_cleanup')
        ->and($finish)->toContain('--reason=rolled_back', '--no-carry', '--backup='.$this->upgrade->safetyBackups[0])
        ->and(implode(' ', $finish))->not->toContain('--source=')
        ->and($this->upgrade->restore->actions())->toBe(['drop', 'create', 'swap', 'drop'])
        ->and($this->upgrade->restore->databases)->toContain('form_schema')->not->toContain('form_schema_pre_restore_'.$id)
        ->and($state->version())->toBe('2.0.0-alpha.1')
        ->and($state->digests())->toBe($before->digests())
        ->and($this->upgrade->compose())->toContain(ReleaseFixtures::APP_DIGEST)->not->toContain(UpgradeFixture::TARGET_DIGEST)
        ->and(InstanceSettings::of($state)->storageVolume())->toBe($state->project().'_app-storage-r'.$id)
        ->and(array_column($this->upgrade->instance->docker->called('removeVolume'), 'name'))->toContain($state->project().'_app-storage')
        ->and($history[array_key_last($history)])->toMatchArray(['event' => 'upgrade_rolled_back', 'to' => UpgradeFixture::TARGET])
        ->and($this->upgrade->gate()['raised'])->toBeFalse()
        ->and(new OperationJournal($this->upgrade->instance->directory)->current())->toBeNull();
});

it('flushes Valkey and appends the ledger with the safety backup\'s heads before the rolled-back database gets its new epoch', function (): void {
    $this->upgrade->child = 'fail';
    $flushes = 0;
    $this->upgrade->instance->docker->onCall['composeRun'] = function (array $args) use (&$flushes): void {
        $flushes += ($args['command'][0] ?? null) === 'valkey-cli' ? 1 : 0;
    };

    [$code] = $this->upgrade->upgrade();
    $ledger = new Ledger($this->upgrade->instance->directory)->last('rollback');

    expect($code)->toBe(5)->and($flushes)->toBeGreaterThanOrEqual(1)
        ->and($ledger)->toMatchArray(['action' => 'rollback', 'reason' => 'automatic', 'backup' => $this->upgrade->safetyBackups[0]]);
});

it('rolls back a child that crashed (no result) with its last log lines, exit 5', function (): void {
    $this->upgrade->child = 'crash';
    $this->upgrade->instance->docker->logs = "fsa: segmentation fault\n";

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('child_crashed')
        ->and($json['data']['logs'])->toContain('segmentation fault')
        ->and($this->upgrade->instance->state()->version())->toBe('2.0.0-alpha.1');
});

it('removes a child whose backend stays idle past the liveness limit, terminates its backend and rolls back', function (): void {
    $this->upgrade->child = 'hang';
    $this->upgrade->childActive = false;

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('child_hung')
        ->and($this->upgrade->instance->docker->called('removeContainer'))->toContain(['id' => UpgradeFixture::CHILD_ID])
        ->and($this->upgrade->oneOffs('instance:upgrade-db'))->not->toBeEmpty()
        ->and(array_map(static fn (array $call): string => $call['command'][3], $this->upgrade->oneOffs('instance:upgrade-db')))->toContain('activity', 'terminate');
});

it('keeps a child alive while its backend is active, past the idle limit', function (): void {
    $this->upgrade->childRunningPolls = 80;

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and(array_map(static fn (array $call): string => $call['command'][3], $this->upgrade->oneOffs('instance:upgrade-db')))->not->toContain('terminate');
});

it('rolls back when the target app does not become healthy behind the gate, with its logs', function (): void {
    $this->upgrade->appUnhealthy = true;
    $this->upgrade->instance->docker->logs = "app: SQLSTATE connection refused\n";

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('target_unhealthy')
        ->and($json['data']['logs'])->toContain('connection refused')
        ->and($this->upgrade->compose())->toContain(ReleaseFixtures::APP_DIGEST)
        ->and($this->upgrade->instance->state()->version())->toBe('2.0.0-alpha.1');
});

it('rolls back when the smoke test fails or an index is left invalid', function (string $failure, string $error): void {
    if ($failure === 'smoke') {
        $this->config->smokeFails = fn (array $urls): bool => str_contains($this->upgrade->compose(), UpgradeFixture::TARGET_DIGEST);
    } else {
        $this->upgrade->invalidIndexes = ['submissions_number_unique'];
    }

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(5, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe($error);
})->with([['smoke', 'smoke_failed'], ['index', 'invalid_index']]);

it('stops everything and exits 8 with the backup path and both digest sets when the rollback\'s restore fails', function (): void {
    $this->upgrade->child = 'fail';
    $this->upgrade->restore->pgRestore = new ProcessOutcome(1, '', 'pg_restore: error: could not connect');
    $mark = $this->config->mark();

    [$code, $json] = $this->upgrade->upgrade();
    $stops = $this->upgrade->stops($mark);

    expect($code)->toBe(8, (string) json_encode($json))
        ->and($json['status'])->toBe('rollback_failed')
        ->and($json['errors'][0]['code'])->toBe('upgrade_rollback_failed')
        ->and($json['data']['safety_backup'])->toBe($this->dir.'/backups/'.$this->upgrade->safetyBackups[0])
        ->and($json['data']['digests']['previous']['app'])->toBe(InstanceCommandFixture::appImage())
        ->and($json['data']['digests']['target']['app'])->toBe(UpgradeFixture::targetImage())
        ->and($json['errors'][0]['hint'])->toContain('fsa upgrade --resume')->toContain('fsa install --from-backup')
        ->and($stops[array_key_last($stops)])->toBe('app,queue,scheduler,backup')
        ->and($this->upgrade->gate()['raised'])->toBeTrue()
        ->and(new OperationJournal($this->upgrade->instance->directory)->current()?->operation())->toBe('upgrade');
});

it('removes the superseded pre-upgrade safety backup after the commit and keeps the new one', function (): void {
    $old = BackupSetFixture::fsa($this->dir.'/backups/safety-upgrade-20261001T000000Z', ['kind' => 'safety-upgrade', ...$this->upgrade->restore->manifest()], appKey: $this->upgrade->restore->appKey());
    $restoreSafety = BackupSetFixture::fsa($this->dir.'/backups/safety-restore-20261001T000000Z', ['kind' => 'safety-restore', ...$this->upgrade->restore->manifest()], appKey: $this->upgrade->restore->appKey());

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and(is_dir($old))->toBeFalse()
        ->and(is_dir($restoreSafety))->toBeTrue()
        ->and(is_dir($this->dir.'/backups/'.$this->upgrade->safetyBackups[0]))->toBeTrue()
        ->and($json['data']['removed_safety_backups'])->toBe(['safety-upgrade-20261001T000000Z']);
});

it('halts instead of restoring another set put under the safety backup\'s name before the way back (M12 gate S4)', function (): void {
    $this->upgrade->child = 'fail';
    $this->upgrade->stage = function (): void {
        $name = $this->upgrade->safetyBackups[0];
        exec('rm -rf '.escapeshellarg($this->dir.'/backups/'.$name));
        BackupSetFixture::fsa($this->dir.'/backups/'.$name, ['kind' => 'safety-upgrade', ...$this->upgrade->restore->manifest()], appKey: $this->upgrade->restore->appKey(), settings: ['note' => 'another set']); // authenticated, but another set's content
    };

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(8, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('upgrade_rollback_failed')
        ->and((string) json_encode($json))->toContain('not the set the upgrade verified')
        ->and($this->upgrade->restore->actions())->not->toContain('swap');
});

it('never removes a superseded pre-upgrade safety backup the operator marked kept', function (): void {
    $kept = BackupSetFixture::fsa($this->dir.'/backups/safety-upgrade-20261001T000000Z', ['kind' => 'safety-upgrade', ...$this->upgrade->restore->manifest()], appKey: $this->upgrade->restore->appKey());
    touch($kept.'/'.BackupStore::KEEP_MARKER);

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and(is_dir($kept))->toBeTrue()
        ->and($json['data']['removed_safety_backups'])->toBe([]);
});

it('keeps the superseded safety backup when the upgrade rolls back', function (): void {
    $old = BackupSetFixture::fsa($this->dir.'/backups/safety-upgrade-20261001T000000Z', ['kind' => 'safety-upgrade', ...$this->upgrade->restore->manifest()], appKey: $this->upgrade->restore->appKey());
    $this->upgrade->child = 'fail';

    [$code] = $this->upgrade->upgrade();

    expect($code)->toBe(5)->and(is_dir($old))->toBeTrue();
});

it('catches an edge that fails with the target during the gate and rolls back before the commit', function (): void {
    $this->upgrade = new UpgradeFixture(['--domain' => ConfigFixture::DOMAIN, '--acme-email' => 'ops@example.com']);
    $this->upgrade->unhealthyService = 'edge';

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('target_unhealthy')
        ->and($this->upgrade->instance->state()->version())->toBe('2.0.0-alpha.1');
});
