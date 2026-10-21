<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\Ledger;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Reset\ResetOperation;
use App\Domain\Instance\Reset\ResetStatus;
use Tests\Support\Instance\ResetFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * The verification review of `fieldset reset` (plan U23, ADR 0088): a retake of the heads and the backup survives a kill inside
 * it (V1), every safety backup of the journal is sealed (V2), `drop_not_reached` covers only its own drop attempt (V3),
 * `--abandon` checks the journal again under the lock (V4), leftover restore databases go with their roles (V5), the
 * sequence floors are filtered before the drop (V6), the replaced volume goes by name even unlabelled (V8), and
 * `fieldset login-link` refuses while an operation is unfinished (V9).
 */

beforeEach(function (): void {
    $this->reset = new ResetFixture;
    $this->dir = $this->reset->instance->directory->root;
    $this->journal = new OperationJournal($this->reset->instance->directory);
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
});

/** An open reset journal: the plan, then each [phase, data|false (started, not completed), start data]. */
function verifiedJournal(object $test, array $phases): void
{
    $journal = $test->journal;
    $journal->begin('reset', posix_getuid(), ['demo' => false, 'no_backup' => false]);
    $journal->startPhase('plan', [], false);
    $journal->note('subject', ['owner_email' => 'owner@example.com', 'owner_name' => 'Olivia Owner']);
    $journal->completePhase('plan', ['organization' => 'Acme Forms', 'locale' => 'en', 'organizations' => 1, 'non_demo' => 1, 'storage_volume' => $test->reset->config->state()->project().'_app-storage']);
    foreach ($phases as [$name, $data, $start]) {
        $journal->startPhase($name, $start);
        if ($data !== false) {
            $journal->completePhase($name, $data);
        }
    }
}

function verifiedStopWriters(object $test): void
{
    foreach ($test->reset->instance->docker->containers as $id => $container) {
        if (in_array($container->service(), ['app', 'queue', 'scheduler', 'backup'], true)) {
            $test->reset->instance->docker->stopContainer((string) $id);
        }
    }
}

it('finishes a retake of the heads and the backup a kill cut short, although no writer runs any more (V1)', function (): void {
    $heads = ['seed' => 1, 'epoch' => 1, 'heads' => ['0' => ['seq' => 3, 'hash' => ResetFixture::HEAD]], 'non_demo' => 1];
    verifiedJournal($this, [
        ['quiesce', ['gate' => 'raised'], ['writers_ran' => false]], ['ledger', $heads, []], ['safety_backup', ['backup' => 'safety-reset-20261019T100000Z'], []],
        ['quiesce', ['gate' => 'raised'], ['writers_ran' => true]], ['ledger', [...$heads, 'heads' => ['0' => ['seq' => 5, 'hash' => ResetFixture::HEAD]]], []], ['safety_backup', false, []],
    ]);
    verifiedStopWriters($this); // run 2's quiesce stopped them before it was killed

    [$code, $json] = $this->reset->reset();
    $phases = array_count_values(ResetFixture::phases($this->reset->archived()));

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($phases)->toMatchArray(['safety_backup' => 3, 'ledger' => 2]) // the backup again, the heads of run 2 stand
        ->and($json['data']['safety_backup'])->toBe(ResetFixture::SAFETY)
        ->and(json_decode((string) $this->reset->calls[array_search('epoch', $this->reset->actions(), true)]['input'], true)['previous_heads'])->toBe(['0' => ['seq' => 5, 'hash' => ResetFixture::HEAD]]);
});

it('seals every safety backup of the journal, also one a retake superseded (V2, R57)', function (): void {
    $this->reset->instance->directory->saveState($this->reset->config->state()->withRecipients(['age1'.str_repeat('c', 58)]));
    verifiedJournal($this, [['quiesce', ['gate' => 'raised'], ['writers_ran' => false]], ['ledger', ['seed' => 1, 'epoch' => 1, 'heads' => [], 'non_demo' => 1], []], ['safety_backup', ['backup' => 'safety-reset-20261019T100000Z'], []]]);
    $seals = [];
    $this->reset->instance->docker->onCall['composeRun'] = static function (array $args) use (&$seals): void {
        foreach ($args['command'] as $argument) {
            if (str_starts_with((string) $argument, '--seal=')) {
                $seals[] = substr((string) $argument, 7);
            }
        }
    };

    [$code, $json] = $this->reset->reset(); // the fixture's writers run: heads and backup are taken again

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($seals)->toEqualCanonicalizing(['safety-reset-20261019T100000Z', ResetFixture::SAFETY]);
});

it('counts a later drop attempt as the drop, whatever note an earlier, untouched attempt left (V3)', function (): void {
    verifiedJournal($this, [['quiesce', [], []], ['ledger', [], []], ['safety_backup', ['backup' => ResetFixture::SAFETY], []], ['recreate_database', false, []]]);
    $this->journal->note(ResetOperation::DROP_NOT_REACHED, ['message' => 'connection refused', 'attempt' => 1]);
    $before = $this->journal->current();
    $this->journal->startPhase('quiesce');
    $this->journal->startPhase('recreate_database'); // the second attempt reached the database and was killed
    $after = $this->journal->current();

    expect(ResetOperation::pastDrop($before))->toBeFalse()
        ->and(ResetOperation::pastDrop($after))->toBeTrue()
        ->and(ResetStatus::of($this->reset->config->state(), $after)['interrupted']['database_recreated'])->toBeTrue();
});

it('checks the journal of --abandon again under the lock: gone meanwhile, nothing is started (V4)', function (): void {
    verifiedJournal($this, [['quiesce', ['gate' => 'raised'], ['writers_ran' => false]]]);
    $docker = $this->reset->instance->docker;
    app()->instance(ContainerIdentity::class, new ContainerIdentity($docker, '/nonexistent', 'abcdefabcdef')); // the ops container asks Docker who it is
    $journal = $this->dir.'/'.OperationJournal::CURRENT;
    $docker->onCall['inspectContainer'] = static function () use ($journal): void {
        @unlink($journal); // another command finished the reset between the check and the lock
    };
    $mark = $this->reset->config->mark();

    [$code, $json] = $this->reset->reset(['--abandon' => true]);

    expect($code)->toBe(2, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('nothing_to_abandon')
        ->and($this->reset->config->methodsSince($mark))->not->toContain('composeUp')
        ->and($this->journal->current())->toBeNull();
});

it('asks again when the reset past its drop finished between the check and the lock (V4, reset path)', function (): void {
    verifiedJournal($this, [['quiesce', [], []], ['ledger', [], []], ['safety_backup', ['backup' => ResetFixture::SAFETY], []], ['recreate_database', false, []]]);
    $docker = $this->reset->instance->docker;
    app()->instance(ContainerIdentity::class, new ContainerIdentity($docker, '/nonexistent', 'abcdefabcdef'));
    $journal = $this->dir.'/'.OperationJournal::CURRENT;
    $docker->onCall['inspectContainer'] = static function () use ($journal): void {
        @unlink($journal);
    };

    [$code, $json] = $this->reset->reset([]); // no question before the lock: the journal was past its drop

    expect($code)->toBe(6, (string) json_encode($json))->and($this->reset->actions())->toBe([]);
});

it('drops the restricted role with each leftover restore or drill database (V5)', function (): void {
    $this->reset->databases = ['fieldset', 'postgres', 'fieldset_pre_restore_0a1b2c3d4e5f', 'fieldset_restore_0a1b2c3d4e5f', 'restore_drill_9f8e7d6c5b4a'];

    [$code] = $this->reset->reset();

    expect($code)->toBe(0)
        ->and($this->reset->droppedRoles)->toBe([
            'fieldset_pre_restore_0a1b2c3d4e5f' => null, 'fieldset_restore_0a1b2c3d4e5f' => 'fieldset_restore_0a1b2c3d4e5f', 'restore_drill_9f8e7d6c5b4a' => 'fieldset_drill_9f8e7d6c5b4a',
        ]);
});

it('keeps only well-formed sequence floors, decided before the drop (V6)', function (): void {
    $this->reset->plan['sequences'] = ['users_id_seq' => 40, 'Bad-Name' => 5, 'negative_seq' => -3, 'text_seq' => '12'];

    [$code, $json] = $this->reset->reset();
    $epoch = $this->reset->calls[array_search('epoch', $this->reset->actions(), true)];

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($this->reset->archived()->phaseData('ledger')['sequences'])->toBe(['users_id_seq' => 40])
        ->and(json_decode((string) $epoch['input'], true)['sequences'])->toBe(['users_id_seq' => 40]);
});

it('removes the replaced storage volume by name even without the instance label (V8)', function (): void {
    $state = $this->reset->config->state();
    $docker = $this->reset->instance->docker;
    $docker->resources = array_values(array_filter($docker->resources, static fn (DockerResource $resource): bool => $resource->name !== $state->project().'_app-storage'));
    $docker->withResource(new DockerResource(DockerResource::VOLUME, $state->project().'_app-storage', ['com.docker.compose.volume' => 'app-storage']));

    [$code, $json] = $this->reset->reset();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data']['removed'])->toContain('volume '.$state->project().'_app-storage')
        ->and(array_column($docker->called('removeVolume'), 'name'))->toContain($state->project().'_app-storage');
});

it('refuses fieldset login-link while an operation is unfinished: exit 9, no link, no audit entry in the old database (V9)', function (): void {
    verifiedJournal($this, [['quiesce', ['gate' => 'raised'], ['writers_ran' => false]], ['ledger', [], []]]);
    $mark = $this->reset->config->mark();

    [$code, $json] = $this->reset->instance->run('fieldset:login-link', ['email' => 'owner@example.com']);

    expect($code)->toBe(9, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('operation_unfinished')
        ->and($json['data']['command'])->toBe('fieldset reset')
        ->and(array_filter($this->reset->config->callsSince($mark, 'composeRun'), static fn (array $call): bool => ($call['command'][2] ?? null) === 'identity:login-link'))->toBe([])
        ->and(new Ledger($this->reset->instance->directory)->entries())->toBe([]);
});
