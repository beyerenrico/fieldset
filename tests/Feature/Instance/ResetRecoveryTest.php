<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Operations\Interruption;
use App\Domain\Instance\Operations\Ledger;
use App\Domain\Instance\Operations\OperationJournal;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\ResetFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * `fsa reset` when something goes wrong (plan U23, ADR 0088): every way back before the drop (exit 5, or exit 8 with the
 * journal open), signals at a phase boundary, and the resume after the drop from the journal (no question, no repeated
 * ledger line, safety backup, recreate or epoch, a new owner link when the seeding happened earlier). The happy paths are
 * in ResetCommandTest.
 */

beforeEach(function (): void {
    $this->reset = new ResetFixture;
    $this->dir = $this->reset->instance->directory->root;
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
});

/** @return array<string, mixed> */
function resetRecoveryRuntime(string $dir): array
{
    return json_decode((string) file_get_contents($dir.'/runtime/runtime.json'), true);
}

it('brings the instance back when the safety backup fails: exit 5, nothing deleted, gate down, journal closed', function (): void {
    $this->reset->instance->backupOutcome = InstanceCommandFixture::oneOffJson(['command' => 'backup-daemon', 'status' => 'failed', 'errors' => [['code' => 'low_disk_space', 'message' => 'Not enough space.', 'hint' => null]]]);
    $mark = $this->reset->config->mark();

    [$code, $json] = $this->reset->reset();

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($json['status'])->toBe('rolled_back')
        ->and($json['errors'][0]['code'])->toBe('safety_backup_failed')
        ->and($this->reset->actions())->toBe(['plan', 'plan'])
        ->and(collect($this->reset->config->callsSince($mark, 'composeUp'))->contains(fn (array $call): bool => $call['services'] === []))->toBeTrue()
        ->and(resetRecoveryRuntime($this->dir)['gate']['raised'])->toBeFalse()
        ->and(new OperationJournal($this->reset->instance->directory)->current())->toBeNull();
});

it('handles a signal before the drop like a failure: the instance runs again (exit 5) and the ledger stays untouched', function (): void {
    $this->reset->hooks['audit:seal'] = static fn () => app(Interruption::class)->handle(15);

    [$code, $json] = $this->reset->reset();

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($this->reset->actions())->toBe(['plan'])
        ->and(new Ledger($this->reset->instance->directory)->entries())->toBe([])
        ->and(resetRecoveryRuntime($this->dir)['gate']['raised'])->toBeFalse();
});

it('resumes a reset that stopped before its epoch began: no question, no second ledger line or backup, the database recreated and migrated again; status shows it', function (): void {
    $this->reset->answers['epoch'] = [ResetFixture::failed('database_failed', 'The database step failed (SQLSTATE 57P01)')];
    $mark = $this->reset->config->mark();

    [$failed, $failedJson] = $this->reset->reset();
    [, $status] = $this->reset->instance->run('fsa:status');
    $gate = resetRecoveryRuntime($this->dir)['gate']['raised'];
    [$code, $json] = $this->reset->reset([]); // no --yes, no terminal: nothing more can be lost

    expect($failed)->toBe(4)->and($failedJson['errors'][0]['code'])->toBe('reset_epoch_failed')
        ->and($failedJson['errors'][0]['hint'])->toContain('fsa reset')->toContain('backups/'.ResetFixture::SAFETY)
        ->and($gate)->toBeTrue()
        ->and($status['data']['reset']['interrupted'])->toMatchArray(['last_phase' => 'epoch', 'database_recreated' => true, 'safety_backup' => ResetFixture::SAFETY, 'resume' => 'fsa reset'])
        ->and(collect($status['warnings'])->firstWhere('code', 'operation_unfinished')['hint'])->toContain('continues from there without asking')
        ->and($code)->toBe(0, (string) json_encode($json))
        ->and($this->reset->actions())->toBe(['plan', 'plan', 'recreate', 'epoch', 'recreate', 'epoch']) // review M1: no database_not_fresh dead end
        ->and(new Ledger($this->reset->instance->directory)->entries())->toHaveCount(1)
        ->and(array_filter($this->reset->instance->docker->called('composeRun'), static fn (array $call): bool => ($call['command'][3] ?? null) === '--kind=safety-reset'))->toHaveCount(1)
        ->and(array_filter($this->reset->config->callsSince($mark, 'composeRun'), static fn (array $call): bool => ($call['command'][2] ?? null) === 'migrate'))->toHaveCount(2)
        ->and(array_column($this->reset->config->state()->history(), 'event'))->toContain('reset')
        ->and($json['data']['sign_in_link']['delivered'])->toBe('secrets');
});

it('goes back when the first drop failed and the old database still answers: exit 5, nothing lost, journal closed', function (): void {
    $this->reset->answers['recreate'] = [ResetFixture::failed('database_failed', 'The database step failed (SQLSTATE 55006)')];

    [$code, $json] = $this->reset->reset();

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('database_recreate_failed')
        ->and($this->reset->actions())->toBe(['plan', 'plan', 'recreate', 'plan'])
        ->and(resetRecoveryRuntime($this->dir)['gate']['raised'])->toBeFalse()
        ->and(new OperationJournal($this->reset->instance->directory)->current())->toBeNull();
});

it('recreates the database again on the next run when a failed drop had reached it (the old database is gone)', function (): void {
    $this->reset->answers['recreate'] = [ResetFixture::failed('database_failed', 'The database step failed (SQLSTATE 3D000)')];
    $this->reset->answers['plan'] = [ResetFixture::ok($this->reset->plan, false), ResetFixture::ok($this->reset->plan, false), ResetFixture::failed('database_failed', 'relation "organizations" does not exist')];

    [$failed, $failedJson] = $this->reset->reset();
    [$code] = $this->reset->reset([]);

    expect($failed)->toBe(4)->and($failedJson['errors'][0]['message'])->toContain('began replacing the database')
        ->and($code)->toBe(0)
        ->and($this->reset->actions())->toBe(['plan', 'plan', 'recreate', 'plan', 'recreate', 'epoch']);
});

it('does not recreate the database again once the epoch began (a later failure resumes after it)', function (): void {
    $this->reset->instance->oneOffs['app:install'] = InstanceCommandFixture::oneOffJson(['command' => 'app:install', 'status' => 'failed', 'errors' => [['code' => 'database_failed', 'message' => 'connection reset', 'hint' => null]]]);
    $mark = $this->reset->config->mark();

    [$failed, $failedJson] = $this->reset->reset();
    unset($this->reset->instance->oneOffs['app:install']);
    [$code] = $this->reset->reset([]);

    expect($failed)->toBe(4)->and($failedJson['errors'][0]['code'])->toBe('app_install_failed')
        ->and($code)->toBe(0)
        ->and($this->reset->actions())->toBe(['plan', 'plan', 'recreate', 'epoch'])
        ->and(array_filter($this->reset->config->callsSince($mark, 'composeRun'), static fn (array $call): bool => ($call['command'][2] ?? null) === 'migrate'))->toHaveCount(1)
        ->and(count(array_filter($this->reset->config->callsSince($mark, 'createVolume'))))->toBe(1);
});

it('issues a new owner link when the seeding happened in an interrupted attempt', function (): void {
    $calls = 0;
    $this->reset->hooks['app:install'] = function () use (&$calls): void {
        $calls++;
    };
    $this->reset->config->smokeFails = static fn (): bool => true;

    [$failed] = $this->reset->reset();
    $this->reset->config->smokeFails = null;
    [$code, $json] = $this->reset->reset([]);

    expect($failed)->toBe(4)->and($code)->toBe(0, (string) json_encode($json))
        ->and($calls)->toBe(1)
        ->and($this->reset->actions())->toBe(['plan', 'plan', 'recreate', 'epoch'])
        ->and($this->reset->instance->oneOffCalls('identity:login-link'))->toHaveCount(1)
        ->and(array_column($json['secrets'], 'value', 'name')['sign_in_link'])->toBe(InstanceCommandFixture::ADMIN_LINK);
});

it('continues only with the flags it started with: another flag is exit 9 naming the command', function (): void {
    $this->reset->answers['epoch'] = [ResetFixture::failed('database_failed', 'down')];
    $this->reset->reset(['--yes' => true, '--demo' => true]);

    [$code, $json] = $this->reset->reset();

    expect($code)->toBe(9)->and($json['errors'][0]['code'])->toBe('operation_unfinished')
        ->and($json['data'])->toMatchArray(['command' => 'fsa reset --demo', 'options' => ['--demo' => true]]);
});

it('continues a reset killed before the drop on the next fsa reset --yes, which asks again without it (nothing was deleted yet)', function (): void {
    $journal = new OperationJournal($this->reset->instance->directory);
    $journal->begin('reset', posix_getuid(), ['demo' => false, 'no_backup' => false]);
    $journal->startPhase('plan', [], false);
    $journal->note('subject', ['owner_email' => 'owner@example.com', 'owner_name' => 'Olivia Owner']);
    $journal->completePhase('plan', ['organization' => 'Acme Forms', 'locale' => 'en', 'organizations' => 1, 'non_demo' => 1, 'export' => false, 'storage_volume' => $this->reset->config->state()->project().'_app-storage']);
    $journal->startPhase('quiesce');

    [, $status] = $this->reset->instance->run('fsa:status');
    [$asked] = $this->reset->reset([]);
    [$code, $json] = $this->reset->reset();

    expect($status['data']['reset']['interrupted'])->toMatchArray(['last_phase' => 'quiesce', 'database_recreated' => false])
        ->and(collect($status['warnings'])->firstWhere('code', 'operation_unfinished')['hint'])->toContain('fsa reset --yes')->toContain('Nothing was deleted yet')->toContain('fsa reset --abandon')
        ->and($asked)->toBe(6)
        ->and($code)->toBe(0, (string) json_encode($json))
        ->and($this->reset->actions())->toBe(['plan', 'recreate', 'epoch']) // the plan of the killed run stands; the ledger phase reads the final state
        ->and(new Ledger($this->reset->instance->directory)->entries())->toHaveCount(1);
});

it('stops every writer behind the gate when the app does not start again before the drop: exit 8, the journal open (review A1, A2)', function (): void {
    $this->reset->instance->backupOutcome = InstanceCommandFixture::oneOffJson(['command' => 'backup-daemon', 'status' => 'failed', 'errors' => [['code' => 'low_disk_space', 'message' => 'Not enough space.', 'hint' => null]]]);
    $this->reset->instance->docker->onCall['composeUp'] = static function (array $args): void {
        if ($args['services'] === ['app']) {
            throw new DockerCommandFailed('compose up', new ProcessOutcome(1, '', 'container app is unhealthy'));
        }
    };
    $mark = $this->reset->config->mark();

    [$code, $json] = $this->reset->reset();
    $stops = $this->reset->config->callsSince($mark, 'composeStop');

    // Changed deliberately (review A2): the hint names `fsa reset --abandon`, and every writer is stopped (A1).
    expect($code)->toBe(8, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('reset_rollback_failed')
        ->and($json['errors'][0]['hint'])->toContain('fsa reset --abandon')
        ->and($stops[array_key_last($stops)]['services'])->toBe(['app', 'queue', 'scheduler', 'backup'])
        ->and($this->reset->actions())->toBe(['plan', 'plan'])
        ->and(resetRecoveryRuntime($this->dir)['gate']['raised'])->toBeTrue()
        ->and(new OperationJournal($this->reset->instance->directory)->current()?->hasStarted('abort'))->toBeTrue();
});

it('finishes going back after an exit 8 on the next fsa reset, without a question, never forward (review A2)', function (): void {
    $this->reset->instance->backupOutcome = InstanceCommandFixture::oneOffJson(['command' => 'backup-daemon', 'status' => 'failed', 'errors' => [['code' => 'low_disk_space', 'message' => 'Not enough space.', 'hint' => null]]]);
    $this->reset->instance->docker->onCall['composeUp'] = static function (array $args): void {
        if ($args['services'] === ['app']) {
            throw new DockerCommandFailed('compose up', new ProcessOutcome(1, '', 'container app is unhealthy'));
        }
    };
    [$halted] = $this->reset->reset();
    [, $status] = $this->reset->instance->run('fsa:status');
    $this->reset->instance->docker->onCall = []; // the app starts again

    [$code, $json] = $this->reset->reset([]); // no --yes: going back deletes nothing

    expect($halted)->toBe(8)
        ->and($status['data']['reset']['interrupted'])->toMatchArray(['going_back' => true, 'database_recreated' => false, 'abandon' => 'fsa reset --abandon'])
        ->and(collect($status['warnings'])->firstWhere('code', 'operation_unfinished')['hint'])->toContain('stopped while going back')
        ->and($code)->toBe(5, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('safety_backup_failed')
        ->and($this->reset->actions())->toBe(['plan', 'plan'])
        ->and(resetRecoveryRuntime($this->dir)['gate']['raised'])->toBeFalse()
        ->and(new OperationJournal($this->reset->instance->directory)->current())->toBeNull();
});

it('removes a failed audit export and brings the instance back (exit 5)', function (): void {
    $this->reset->instance->docker->fileRunResponder = static function (?string $entrypoint, array $command, string $file): ProcessOutcome {
        file_put_contents($file, 'half');

        return new ProcessOutcome(1, '', 'SQLSTATE[08006] connection lost');
    };

    [$code, $json] = $this->reset->reset(['--yes' => true, '--no-backup' => true]);

    expect($code)->toBe(5)->and($json['errors'][0]['code'])->toBe('audit_export_failed')
        ->and(glob($this->dir.'/backups/audit-export-*'))->toBe([])
        ->and($this->reset->actions())->toBe(['plan', 'plan']); // the second: the final counts and sequences of the ledger phase
});

it('refuses an export that holds fewer entries than its header names', function (): void {
    $this->reset->instance->docker->fileRunResponder = static function (?string $entrypoint, array $command, string $file): ProcessOutcome {
        file_put_contents($file, json_encode(['format' => 'fieldset-audit-export', 'version' => '1.0', 'entries' => 3])."\n".'{"id":1}'."\n");

        return new ProcessOutcome(0);
    };

    [$code, $json] = $this->reset->reset(['--yes' => true, '--no-backup' => true]);

    expect($code)->toBe(5)->and($json['errors'][0]['code'])->toBe('audit_export_failed')
        ->and($json['errors'][0]['message'])->toContain('incomplete')
        ->and(glob($this->dir.'/backups/audit-export-*'))->toBe([]);
});
