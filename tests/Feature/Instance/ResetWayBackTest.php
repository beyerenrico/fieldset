<?php

declare(strict_types=1);

use App\Domain\Instance\Console\FieldsetContext;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Operations\Ledger;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Output\ProgressReporter;
use App\Domain\Instance\Output\ResultStatus;
use App\Domain\Instance\Output\SecretRedactor;
use App\Domain\Instance\Reset\ResetOperation;
use App\Domain\Instance\Reset\ResetRequest;
use App\Domain\Instance\Reset\ResetStatus;
use App\Domain\Instance\State\InstanceDirectory;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\Instance\ResetFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * The second review of `fieldset reset` (plan U23, ADR 0088): the journaled way back before the drop and `--abandon` (A2), the
 * writers stopped on exit 8 and before every drop, and the heads, backup and export taken again when writers ran before a
 * resumed reset (A1), a failed drop that never reached the database counted as before the drop (A3), the confirmation
 * checked under the lock (S4), leftovers of earlier restores removed (A4/S5), a repeated clean-up (A6) and a repeated
 * phase after its side effect, `fieldset backup` refused while a reset is unfinished (A8), status hints by phase (A9), and
 * the safety backup sealed to the recipients.
 */

beforeEach(function (): void {
    $this->reset = new ResetFixture;
    $this->dir = $this->reset->instance->directory->root;
    $this->journal = new OperationJournal($this->reset->instance->directory);
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
});

/** @return array<string, mixed> */
function wayBackRuntime(string $dir): array
{
    return json_decode((string) file_get_contents($dir.'/runtime/runtime.json'), true);
}

/** An open reset journal: the plan done (with the owner note), then the given phases (name => completed?). */
function wayBackJournal(object $test, array $phases): void
{
    $journal = $test->journal;
    $journal->begin('reset', posix_getuid(), ['demo' => false, 'no_backup' => false]);
    $journal->startPhase('plan', [], false);
    $journal->note('subject', ['owner_email' => 'owner@example.com', 'owner_name' => 'Olivia Owner']);
    $journal->completePhase('plan', ['organization' => 'Acme Forms', 'locale' => 'en', 'organizations' => 1, 'non_demo' => 1, 'storage_volume' => $test->reset->config->state()->project().'_app-storage']);
    foreach ($phases as $name => $completed) {
        $journal->startPhase($name);
        if ($completed !== false) {
            $journal->completePhase($name, is_array($completed) ? $completed : []);
        }
    }
}

/**
 * The archived journal of a finished reset reopened as if the run had been killed inside `$phase`, after its side effects:
 * the phases before it completed, `$phase` started, nothing after it (the owner note with its real values again).
 */
function wayBackKilledIn(object $test, string $phase): void
{
    $document = $test->reset->archived()->toArray();
    $phases = [];
    foreach ($document['phases'] as $entry) {
        if ($entry['name'] === $phase) {
            $phases[] = [...$entry, 'status' => 'started', 'finished_at' => null];
            break;
        }
        $phases[] = $entry;
    }
    $events = array_map(static fn (array $event): array => $event['type'] === 'subject' ? [...$event, 'data' => ['owner_email' => 'owner@example.com', 'owner_name' => 'Olivia Owner']] : $event, $document['events']);
    $test->reset->instance->directory->write(OperationJournal::CURRENT, (string) json_encode([...$document, 'phases' => $phases, 'events' => $events, 'outcome' => null, 'finished_at' => null, 'result' => []]), 0600);
}

function wayBackContext(): FieldsetContext
{
    $redactor = new SecretRedactor;

    return new FieldsetContext(app(HostFacts::class), new ProgressReporter(new NullOutput, true, $redactor), $redactor, true, false, app(InstanceDirectory::class));
}

it('abandons a reset killed before the drop: the instance starts again as it was, the journal closes (review A2)', function (): void {
    wayBackJournal($this, ['quiesce' => ['gate' => 'raised'], 'ledger' => [], 'safety_backup' => ['backup' => ResetFixture::SAFETY]]);
    $mark = $this->reset->config->mark();

    [$code, $json] = $this->reset->reset(['--abandon' => true]);
    $ups = array_column($this->reset->config->callsSince($mark, 'composeUp'), 'services');

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data'])->toMatchArray(['abandoned' => true, 'database_recreated' => false, 'safety_backup' => ResetFixture::SAFETY])
        ->and($ups)->toBe([['app'], []]) // reopenPrevious: the app first, then the gate goes down, then the rest
        ->and($this->reset->actions())->toBe([])
        ->and(wayBackRuntime($this->dir)['gate']['raised'])->toBeFalse()
        ->and($this->journal->current())->toBeNull()
        ->and($this->reset->config->archivedJournal('reset')->hasCompleted('abort'))->toBeTrue();
});

it('refuses --abandon after the drop (nothing to go back to) and without an interrupted reset', function (): void {
    [$nothing, $nothingJson] = $this->reset->reset(['--abandon' => true]);
    wayBackJournal($this, ['quiesce' => [], 'ledger' => [], 'safety_backup' => ['backup' => ResetFixture::SAFETY], 'recreate_database' => false]);

    [$code, $json] = $this->reset->reset(['--abandon' => true]);

    expect($nothing)->toBe(2)->and($nothingJson['errors'][0]['code'])->toBe('nothing_to_abandon')
        ->and($code)->toBe(2)->and($json['errors'][0]['code'])->toBe('reset_past_drop')
        ->and($json['errors'][0]['hint'])->toContain('fieldset reset')->toContain('backups/'.ResetFixture::SAFETY)
        ->and($this->journal->current()?->operation())->toBe('reset');
});

it('finishes a way back that was killed, never resuming forward (review A2)', function (): void {
    wayBackJournal($this, ['quiesce' => [], 'ledger' => [], 'abort' => false]);

    [$code, $json] = $this->reset->reset([]); // going back deletes nothing: no question

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('reset_interrupted')
        ->and($this->reset->actions())->toBe([])
        ->and(wayBackRuntime($this->dir)['gate']['raised'])->toBeFalse()
        ->and($this->journal->current())->toBeNull();
});

it('counts a failed drop that never reached the database as before the drop, also after exit 8 (review A3)', function (): void {
    $this->reset->answers['recreate'] = [ResetFixture::failed('database_failed', 'connection refused')];
    $this->reset->instance->docker->onCall['composeUp'] = static function (array $args): void {
        if ($args['services'] === ['app']) {
            throw new DockerCommandFailed('compose up', new ProcessOutcome(1, '', 'container app is unhealthy'));
        }
    };

    [$halted] = $this->reset->reset();
    $open = $this->journal->current();
    [, $status] = $this->reset->instance->run('fieldset:status');
    $this->reset->instance->docker->onCall = [];
    [$code, $json] = $this->reset->reset([]); // going back: no question, and never the drop again

    expect($halted)->toBe(8)
        ->and(ResetOperation::pastDrop($open))->toBeFalse()
        ->and(array_column($open?->events() ?? [], 'type'))->toContain(ResetOperation::DROP_NOT_REACHED)
        ->and($status['data']['reset']['interrupted'])->toMatchArray(['database_recreated' => false, 'going_back' => true, 'abandon' => 'fieldset reset --abandon'])
        ->and($code)->toBe(5, (string) json_encode($json))
        ->and($this->reset->actions())->toBe(['plan', 'plan', 'recreate', 'plan'])
        ->and($this->journal->current())->toBeNull();
});

it('takes the heads, the backup and the export again when writers ran before a resumed reset, and stops them before the drop (review A1)', function (): void {
    wayBackJournal($this, ['quiesce' => ['gate' => 'raised'], 'ledger' => ['seed' => 1, 'epoch' => 1, 'heads' => ['0' => ['seq' => 3, 'hash' => ResetFixture::HEAD]], 'non_demo' => 1], 'safety_backup' => ['backup' => 'safety-reset-20261019T110000Z']]);
    $mark = $this->reset->config->mark(); // the fixture's stack runs: an earlier way back started the writers

    [$code, $json] = $this->reset->reset();
    $phases = ResetFixture::phases($this->reset->archived());
    $stops = array_column($this->reset->config->callsSince($mark, 'composeStop'), 'services');
    $epoch = $this->reset->calls[array_search('epoch', $this->reset->actions(), true)];

    expect($code)->toBe(0, (string) json_encode($json))
        ->and(array_count_values($phases))->toMatchArray(['ledger' => 2, 'safety_backup' => 2, 'quiesce' => 2])
        ->and($this->reset->archived()->phaseData('quiesce')['writers_ran'])->toBeTrue()
        ->and(new Ledger($this->reset->instance->directory)->entries())->toHaveCount(1)
        ->and($json['data']['safety_backup'])->toBe(ResetFixture::SAFETY)
        ->and($epoch['command'])->toContain('--safety-backup='.ResetFixture::SAFETY)
        ->and(json_decode((string) $epoch['input'], true)['previous_heads'])->toBe(['0' => ['seq' => 7, 'hash' => ResetFixture::HEAD]])
        ->and($stops)->toContain(['app', 'queue', 'scheduler', 'backup']);
});

it('keeps the heads and the backup of a resumed reset when no writer ran since', function (): void {
    wayBackJournal($this, ['quiesce' => ['gate' => 'raised'], 'ledger' => ['seed' => 1, 'epoch' => 1, 'heads' => ['0' => ['seq' => 3, 'hash' => ResetFixture::HEAD]], 'non_demo' => 1], 'safety_backup' => ['backup' => ResetFixture::SAFETY]]);
    foreach ($this->reset->instance->docker->containers as $id => $container) {
        if (in_array($container->service(), ['app', 'queue', 'scheduler', 'backup'], true)) {
            $this->reset->instance->docker->stopContainer((string) $id);
        }
    }

    [$code] = $this->reset->reset();

    expect($code)->toBe(0)
        ->and(array_count_values(ResetFixture::phases($this->reset->archived())))->toMatchArray(['ledger' => 1, 'safety_backup' => 1])
        ->and(new Ledger($this->reset->instance->directory)->entries())->toBe([]);
});

it('asks again under the lock: a new reset without a yes is exit 6 and starts nothing (review S4)', function (): void {
    $mark = $this->reset->config->mark();

    $result = app(ResetOperation::class)->reset(new ResetRequest(false, false, posix_getuid(), confirmed: false), wayBackContext(), false);

    expect($result->exitCode->value)->toBe(6)
        ->and($result->errors[0]->code)->toBe('confirmation_required')
        ->and($this->reset->config->methodsSince($mark))->not->toContain('composeRun')->not->toContain('composeStop')
        ->and($this->journal->current())->toBeNull();
});

it('removes the leftovers of earlier restores: restore-made databases and older storage volumes (review A4/S5)', function (): void {
    $state = $this->reset->config->state();
    $this->reset->databases = ['fieldset', 'postgres', 'fieldset_pre_restore_0a1b2c3d4e5f', 'fieldset_restore_0a1b2c3d4e5f', 'restore_drill_9f8e7d6c5b4a', 'other_database'];
    $this->reset->instance->docker->withResource(new DockerResource(DockerResource::VOLUME, $state->project().'_app-storage-r0a1b2c3d4e5f', Labels::forInstance($state->uuid(), 'app-storage')));

    [$code, $json] = $this->reset->reset();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($this->reset->dropped)->toBe(['fieldset_pre_restore_0a1b2c3d4e5f', 'fieldset_restore_0a1b2c3d4e5f', 'restore_drill_9f8e7d6c5b4a'])
        ->and($json['data']['removed'])->toContain('volume '.$state->project().'_app-storage', 'volume '.$state->project().'_app-storage-r0a1b2c3d4e5f', 'database restore_drill_9f8e7d6c5b4a')
        ->and($json['data']['left_behind'])->toBe([]);
});

it('repeats a clean-up a killed run began without a false warning for the volume it removed already (review A6)', function (): void {
    $this->reset->reset();
    wayBackKilledIn($this, 'cleanup');
    $docker = $this->reset->instance->docker; // like Docker: removing a volume that is gone fails
    $docker->onCall['removeVolume'] = static function (array $args) use ($docker): void {
        if (! collect($docker->volumes([]))->contains(fn (DockerResource $volume): bool => $volume->name === $args['name'])) {
            throw new DockerCommandFailed('volume rm', new ProcessOutcome(1, '', "Error response from daemon: get {$args['name']}: no such volume"));
        }
    };

    [$code, $json] = $this->reset->reset([]);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['warnings'])->toBe([])
        ->and($json['data']['left_behind'])->toBe([]);
});

it('repeats a non-repeatable phase a killed run left after its side effect (the storage volume made, the state written)', function (): void {
    $this->reset->reset();
    $fresh = (string) $this->reset->config->state()->setting('storage_volume');
    wayBackKilledIn($this, 'storage');
    $mark = $this->reset->config->mark();

    [$code, $json] = $this->reset->reset([]);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and(array_column($this->reset->config->callsSince($mark, 'removeVolume'), 'name'))->toContain($fresh)
        ->and(array_column($this->reset->config->callsSince($mark, 'createVolume'), 'name'))->toBe([$fresh])
        ->and($json['data']['storage_volume'])->toBe($fresh)
        ->and($this->reset->config->state()->setting('storage_volume'))->toBe($fresh);
});

it('refuses fieldset backup while a reset is unfinished: exit 9 naming fieldset reset (review A8)', function (): void {
    wayBackJournal($this, ['quiesce' => [], 'ledger' => [], 'safety_backup' => ['backup' => ResetFixture::SAFETY], 'recreate_database' => false]);
    $mark = $this->reset->config->mark();

    [$code, $json] = $this->reset->instance->run('fieldset:backup');

    expect($code)->toBe(9)->and($json['errors'][0]['code'])->toBe('operation_unfinished')
        ->and($json['data']['command'])->toBe('fieldset reset')
        ->and(array_filter($this->reset->config->callsSince($mark, 'composeRun'), static fn (array $call): bool => ($call['command'][1] ?? null) === 'backup-daemon'))->toBe([]);
});

it('hints by the phase a reset stopped in: no gate before the quiesce, a running instance after the gate (review A9)', function (): void {
    wayBackJournal($this, []);
    $planOnly = $this->journal->current();
    $this->journal->finish(ResultStatus::Failed);
    wayBackJournal($this, ['quiesce' => [], 'ledger' => [], 'safety_backup' => ['backup' => ResetFixture::SAFETY], 'recreate_database' => [], 'migrate' => [], 'epoch' => [], 'storage' => [], 'flush' => [], 'seed' => [], 'verify' => [], 'open' => [], 'gate' => []]);
    $afterGate = $this->journal->current();

    expect(ResetStatus::hint($planOnly ?? throw new RuntimeException))->toContain('no gate is up')->not->toContain('503')
        ->and(ResetStatus::hint($afterGate ?? throw new RuntimeException))->toContain('runs already')->not->toContain('503');
});

it('seals the safety backup to the recipients once the reset closed', function (): void {
    $this->reset->instance->directory->saveState($this->reset->config->state()->withRecipients(['age1'.str_repeat('c', 58)]));
    $seals = [];
    $this->reset->instance->docker->onCall['composeRun'] = static function (array $args) use (&$seals): void {
        foreach ($args['command'] as $argument) {
            if (str_starts_with((string) $argument, '--seal=')) {
                $seals[] = substr((string) $argument, 7);
            }
        }
    };

    [$code, $json] = $this->reset->reset();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($seals)->toBe([ResetFixture::SAFETY])
        ->and(collect($json['warnings'])->pluck('code')->all())->not->toContain('safety_backup_not_sealed');
});

it('closes an interrupted reset that only planned when it is abandoned', function (): void {
    wayBackJournal($this, []);

    [$code, $json] = $this->reset->reset(['--abandon' => true]);

    expect($code)->toBe(0, (string) json_encode($json))->and($this->journal->current())->toBeNull();
});
