<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Restore\RestoreRecovery;
use App\Domain\Instance\Restore\RestoreSpace;
use App\Domain\Instance\State\InstanceSettings;
use Tests\Support\Instance\InterruptedRestore;
use Tests\Support\Instance\RestoreFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * Going back before the swap, and the dead ends of the way back (third review of plan U17, ADR 0085): the abort is
 * journaled, so a run killed while going back is finished as an abort and never resumed as a restore; the workers stop
 * before the databases are renamed back; an unchanged previous instance takes requests once its app is healthy, even
 * when another service does not start; and `--abandon` continues with the restored data when there is no way back.
 */

beforeEach(function (): void {
    $this->restore = new RestoreFixture;
    $this->dir = $this->restore->instance->directory->root;
    $this->restore->backup();
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
});

/** Matches `docker compose stop` of everything that writes: app, queue, scheduler and the backup service. */
function frontendStop(): Closure
{
    return static fn (string $method, array $args): bool => $method === 'composeStop' && $args['services'] === ['app', 'queue', 'scheduler', 'backup'];
}

it('finishes an abort a killed run left half done instead of restoring again: nothing is restored or swapped (review M2)', function (): void {
    $names = InterruptedRestore::aborting($this->restore);
    InterruptedRestore::raiseGate($this->restore);
    $mark = $this->restore->config->mark();

    [$code, $json] = $this->restore->restore();
    $ups = $this->restore->config->callsSince($mark, 'composeUp');

    expect($code)->toBe(5, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe('storage_restore_failed')
        ->and($this->restore->actions())->not->toContain('create')->not->toContain('swap')
        ->and($this->restore->specs('pg_restore'))->toBe([])
        ->and($this->restore->databases)->not->toContain($names->scratch)
        ->and($ups[array_key_last($ups)]['services'] ?? null)->toBe([])
        ->and(InterruptedRestore::gateRaised($this->dir))->toBeFalse()
        ->and(new OperationJournal($this->restore->instance->directory)->current())->toBeNull();
});

it('only seals and closes when the kill came during the seal after the abort (review M2)', function (): void {
    InterruptedRestore::aborting($this->restore, completed: true);
    $this->restore->instance->directory->saveState($this->restore->config->state()->withRecipients(['age1'.str_repeat('c', 58)]));
    $mark = $this->restore->config->mark();

    [$code, $json] = $this->restore->restore();
    $seals = array_filter($this->restore->config->callsSince($mark, 'composeRun'), static fn (array $call): bool => str_starts_with($call['command'][2] ?? '', '--seal=safety-restore-20261019T120000Z'));

    expect($code)->toBe(5, (string) json_encode($json))->and($this->restore->actions())->toBe([])
        ->and($this->restore->config->callsSince($mark, 'composeUp'))->toBe([])->and($seals)->toHaveCount(1)
        ->and(new OperationJournal($this->restore->instance->directory)->current())->toBeNull();
});

it('lets --abandon finish an abort a killed run left half done (review M2)', function (): void {
    $names = InterruptedRestore::aborting($this->restore);

    [$code, $json] = $this->restore->instance->run('fsa:restore', ['--abandon' => true, '--yes' => true]);

    expect($code)->toBe(0, (string) json_encode($json))->and($json['data'])->toMatchArray(['abandoned' => true, 'swapped' => false])
        ->and($this->restore->databases)->not->toContain($names->scratch)
        ->and(new OperationJournal($this->restore->instance->directory)->current())->toBeNull();
});

it('lowers the gate once the unchanged app is healthy and reports the services that did not start when it aborts (review L7)', function (): void {
    $this->restore->pgRestore = new ProcessOutcome(1, '', 'pg_restore: error: connection lost');
    $this->restore->instance->docker->onCall['composeUp'] = static function (array $args): void {
        if ($args['services'] === []) {
            throw new RuntimeException('dependency failed to start: container backup is unhealthy');
        }
    };

    [$code, $json] = $this->restore->restore();

    expect($code)->toBe(5, (string) json_encode($json))
        ->and(collect($json['warnings'])->firstWhere('code', 'services_degraded')['message'] ?? '')->toContain('did not start')
        ->and(InterruptedRestore::gateRaised($this->dir))->toBeFalse()
        ->and(new OperationJournal($this->restore->instance->directory)->current())->toBeNull();
});

it('gives the abort its own hint when even the previous app does not start: nothing was swapped (review L5)', function (): void {
    $this->restore->pgRestore = new ProcessOutcome(1, '', 'pg_restore: error: connection lost');
    $this->restore->instance->docker->onCall['composeUp'] = static function (array $args): void {
        throw new RuntimeException('container app is unhealthy');
    };

    [$code, $json] = $this->restore->restore();

    expect($code)->toBe(8, (string) json_encode($json))
        ->and($json['errors'][0]['hint'])->toContain('Nothing was swapped')->toContain('fsa restore --abandon')->not->toContain('continue returning');
});

it('stops app, queue, scheduler and backup before it renames the databases back (review M3)', function (): void {
    $full = 0;
    $this->restore->instance->docker->onCall['composeUp'] = function (array $args) use (&$full): void {
        if ($args['services'] === [] && ++$full === 1) {
            throw new RuntimeException('dependency failed to start: container queue is unhealthy'); // open fails with workers half up
        }
    };

    [$code, $json] = $this->restore->restore();
    $firstSwap = InterruptedRestore::position($this->restore, InterruptedRestore::databaseCall('swap'));
    $swapBack = InterruptedRestore::position($this->restore, InterruptedRestore::databaseCall('swap'), $firstSwap + 1);
    $stop = InterruptedRestore::position($this->restore, frontendStop(), $firstSwap + 1);

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($stop)->toBeGreaterThan($firstSwap)->toBeLessThan($swapBack);
});

it('stops the workers an earlier run started when --abandon returns after open (review M3)', function (): void {
    $names = InterruptedRestore::journal($this->restore, 'open');
    InterruptedRestore::swapped($this->restore, $names);
    $mark = $this->restore->config->mark();

    [$code, $json] = $this->restore->instance->run('fsa:restore', ['--abandon' => true, '--yes' => true]);
    $stop = InterruptedRestore::position($this->restore, frontendStop(), $mark);
    $swapBack = InterruptedRestore::position($this->restore, InterruptedRestore::databaseCall('swap'), $mark);

    expect($code)->toBe(0, (string) json_encode($json))->and($json['data']['returned_to_previous'])->toBeTrue()
        ->and($stop)->toBeGreaterThan(-1)->toBeLessThan($swapBack)
        ->and($this->restore->databases)->toContain($names->live)->not->toContain($names->aside);
});

it('continues with the restored data on --abandon when there is no way back, after the same restore stopped with exit 8 (review L4)', function (): void {
    InterruptedRestore::returning($this->restore, []); // the put-aside database vanished: nothing to rename back

    [$stuck, $stuckJson] = $this->restore->restore();
    [$code, $json] = $this->restore->instance->run('fsa:restore', ['--abandon' => true, '--yes' => true]);

    expect($stuck)->toBe(8, (string) json_encode($stuckJson))
        ->and($stuckJson['errors'][0]['hint'])->toContain('fsa restore --abandon --yes')->toContain('install.sh --dir <new directory> --from-backup')->not->toContain('fsa install --from-backup')->not->toContain('with `fsa restore`') // gate review DOC14, O9
        ->and($code)->toBe(0, (string) json_encode($json))->and($json['data'])->toMatchArray(['abandoned' => false, 'completed' => true, 'forward' => true])
        ->and(InterruptedRestore::gateRaised($this->dir))->toBeFalse()
        ->and(InstanceSettings::of($this->restore->config->state())->storageVolume())->toContain('_app-storage-r')
        ->and(new OperationJournal($this->restore->instance->directory)->current())->toBeNull();
});

it('names --abandon and a new directory, never `fsa restore` of the safety backup, when the way back fails (review L4)', function (): void {
    InterruptedRestore::returning($this->restore, [RestoreRecovery::DATABASE_RETURNING, RestoreRecovery::DATABASE_BACK]);
    $this->restore->config->smokeFails = static fn (array $urls, array $command): bool => in_array('@-', $command, true);

    [$code, $json] = $this->restore->restore();

    expect($code)->toBe(8, (string) json_encode($json))
        ->and($json['errors'][0]['hint'])->toContain('fsa restore --abandon --yes')->toContain('`install.sh --dir <new directory> --from-backup '.$this->dir.'/backups/safety-restore-20261019T120000Z`')->not->toContain('fsa install --from-backup'); // gate review DOC14, O9
});

it('warns when the free space for the scratch set cannot be read, in a drill too (review A12)', function (): void {
    app()->instance(RestoreSpace::FREE_BYTES, static fn (): ?int => null);

    [$code, $json] = $this->restore->instance->run('fsa:restore', ['backup' => 'manual-20261019T120000Z', '--drill' => true]);

    expect($code)->toBe(0, (string) json_encode($json))->and(array_column($json['warnings'], 'code'))->toContain('disk_space_unknown');
});
