<?php

declare(strict_types=1);

use App\Domain\Instance\Operations\OperationJournal;
use Tests\Support\Instance\InterruptedRestore;
use Tests\Support\Instance\RestoreFixture;
use Tests\Support\Instance\TemporaryInstance;
use Tests\Support\Instance\UpgradeFixture;

/*
 * Which command refuses what while an operation is unfinished, and what an exit 6 carries (M12 gate C4, O6): an open journal
 * is reported (exit 9 `operation_unfinished`) before any question, plan or count, also with --yes; a drill never runs
 * next to one; and the facts a confirmation question names are in `data`.
 */

beforeEach(function (): void {
    $this->restore = new RestoreFixture;
    $this->restore->backup();
    $this->journal = new OperationJournal($this->restore->instance->directory);
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
});

it('refuses a drill while any operation is unfinished, before it creates a scratch database (C4a)', function (): void {
    $this->journal->begin('reset', posix_getuid(), ['demo' => false, 'no_backup' => false]);
    $mark = count($this->restore->instance->docker->calls);

    [$code, $json] = $this->restore->instance->run('fsa:restore', ['backup' => 'manual-20261019T120000Z', '--drill' => true]);

    expect($code)->toBe(9, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe('operation_unfinished')
        ->and($json['data']['operation'])->toBe('reset')
        ->and(array_slice($this->restore->instance->docker->calls, $mark))->toBe([])
        ->and(is_dir($this->restore->instance->directory->root.'/locks/instance.lock'))->toBeFalse();
});

it('reports another unfinished operation before the question of restore and reset, with and without --yes (C4b)', function (string $command, array $parameters, bool $yes): void {
    InterruptedRestore::journal($this->restore, 'ledger'); // a restore of manual-20261019T120000Z
    $mark = count($this->restore->instance->docker->calls);

    [$code, $json] = $this->restore->instance->run($command, [...$parameters, ...($yes ? ['--yes' => true] : [])]);

    expect($code)->toBe(9, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe('operation_unfinished')
        ->and($json['data']['command'])->toBe('fsa restore manual-20261019T120000Z --yes')
        ->and(array_slice($this->restore->instance->docker->calls, $mark))->toBe([]);
})->with([
    'restore of another backup' => ['fsa:restore', ['backup' => 'manual-20261019T150000Z'], false],
    'restore of another backup, --yes' => ['fsa:restore', ['backup' => 'manual-20261019T150000Z'], true],
    'reset' => ['fsa:reset', [], false],
    'reset --yes' => ['fsa:reset', [], true],
]);

it('reports an unfinished operation before the rollback guard and the loss count, with and without --yes (C4b)', function (bool $yes): void {
    $upgrade = new UpgradeFixture;
    [$upgraded] = $upgrade->upgrade();
    InterruptedRestore::journal($upgrade->restore, 'ledger');
    $mark = count($upgrade->instance->docker->calls);

    [$code, $json] = $upgrade->instance->run('fsa:rollback', $yes ? ['--yes' => true] : []);

    expect($upgraded)->toBe(0)
        ->and($code)->toBe(9, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe('operation_unfinished')
        ->and(array_slice($upgrade->instance->docker->calls, $mark))->toBe([])
        ->and($upgrade->oneOffs('instance:rollback-loss'))->toBe([]);
})->with(['without --yes' => false, 'with --yes' => true]);

it('names the facts of the question in data when it exits 6: rollback versions, safety backup and loss (O6)', function (): void {
    $upgrade = new UpgradeFixture;
    $upgrade->upgrade();

    [$code, $json] = $upgrade->instance->run('fsa:rollback');

    expect($code)->toBe(6, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe('confirmation_required')
        ->and($json['data'])->toMatchArray(['from' => UpgradeFixture::TARGET, 'to' => '2.0.0-alpha.1', 'safety_backup' => $upgrade->safetyBackups[0],
            'loss' => ['submissions' => 12, 'activity_log' => 340, 'users' => 0]])
        ->and($json['data']['safety_backup_at'])->toBe('2026-10-20T10:00:00Z')
        ->and($json['data']['question'])->toContain('12 submissions');
});

it('names the facts of the restore, reset and uninstall questions in data (O6)', function (string $command, array $parameters, array $data): void {
    [$code, $json] = $this->restore->instance->run($command, $parameters);

    expect($code)->toBe(6, (string) json_encode($json))->and($json['data'])->toMatchArray($data)
        ->and($json['data']['question'])->toBe(substr($json['errors'][0]['message'], strlen('Confirmation required: ')));
})->with([
    'restore' => ['fsa:restore', ['backup' => 'manual-20261019T120000Z'], ['backup' => 'manual-20261019T120000Z', 'safety_backup' => true]],
    'restore --abandon' => ['fsa:restore', ['--abandon' => true], ['abandon' => true]],
    'reset --no-backup' => ['fsa:reset', ['--no-backup' => true], ['demo' => false, 'no_backup' => true, 'safety_backup' => false]],
    'uninstall --keep-backups' => ['fsa:uninstall', ['--keep-backups' => true], ['final_backup' => true, 'keeps_backups' => true, 'adopted_from' => null]],
    'uninstall' => ['fsa:uninstall', [], ['final_backup' => false, 'keeps_backups' => false]],
]);
