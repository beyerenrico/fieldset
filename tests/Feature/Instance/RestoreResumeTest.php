<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Restore\RestoreRecovery;
use App\Domain\Instance\Restore\RestoreSpace;
use App\Domain\Instance\State\InstanceSettings;
use Tests\Support\Instance\InterruptedRestore;
use Tests\Support\Instance\RestoreFixture;
use Tests\Support\Instance\TarBuilder;
use Tests\Support\Instance\TemporaryInstance;

/*
 * Resuming and finishing `fsa restore` after a killed run or an exit 8 (review of plan U17, ADR 0085): a return to the
 * previous instance that began is only ever finished (never verified, opened or cleaned up as a restore), a swap killed
 * between its two renames is renamed back, the gate goes down last and once it is down nothing swaps back, and a resumed
 * run on a quiesced instance never says "nothing changed".
 */

beforeEach(function (): void {
    $this->restore = new RestoreFixture;
    $this->dir = $this->restore->instance->directory->root;
    $this->restore->backup();
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
});

/** @return list<string> the names of the volumes removed since the mark */
function removedVolumes(RestoreFixture $restore, int $mark): array
{
    return array_column($restore->config->callsSince($mark, 'removeVolume'), 'name');
}

it('lowers the gate after a resumed clean-up instead of raising it again (review A1)', function (): void {
    InterruptedRestore::journal($this->restore, 'gate', started: 'cleanup');

    [$code, $json] = $this->restore->restore();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and(InterruptedRestore::gateRaised($this->dir))->toBeFalse()
        ->and(new OperationJournal($this->restore->instance->directory)->current())->toBeNull();
});

it('finishes lowering the gate when a run was killed after everything started behind it: no second verification (review A1, A4)', function (): void {
    InterruptedRestore::journal($this->restore, 'open', started: 'gate');
    InterruptedRestore::raiseGate($this->restore);
    $mark = $this->restore->config->mark();

    [$code, $json] = $this->restore->restore();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($this->restore->config->callsSince($mark, 'composeUp'))->toBe([])
        ->and($this->restore->actions())->not->toContain('swap')
        ->and(InterruptedRestore::gateRaised($this->dir))->toBeFalse();
});

it('continues a return after an exit 8 when the same command runs again: no second rename, no clean-up of the previous instance (review A2)', function (): void {
    $names = InterruptedRestore::returning($this->restore, [RestoreRecovery::DATABASE_RETURNING, RestoreRecovery::DATABASE_BACK]);
    InterruptedRestore::raiseGate($this->restore);
    $mark = $this->restore->config->mark();

    [$code, $json] = $this->restore->restore();

    expect($code)->toBe(5, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe('restore_interrupted')
        ->and($json['data']['returned_to_previous'])->toBeTrue()
        ->and($this->restore->actions())->not->toContain('swap')->not->toContain('rename')->not->toContain('list')
        ->and($this->restore->databases)->toContain($names->live)->not->toContain($names->scratch)
        ->and(removedVolumes($this->restore, $mark))->toBe([$names->freshVolume])
        ->and(InstanceSettings::of($this->restore->config->state())->storageVolume())->toBeNull()
        ->and(InterruptedRestore::gateRaised($this->dir))->toBeFalse()
        ->and(new OperationJournal($this->restore->instance->directory)->current())->toBeNull();
});

it('lets --abandon finish a return after an exit 8 whose rename was done (review A2)', function (): void {
    $names = InterruptedRestore::returning($this->restore, [RestoreRecovery::DATABASE_RETURNING, RestoreRecovery::DATABASE_BACK]);

    [$code, $json] = $this->restore->instance->run('fsa:restore', ['--abandon' => true, '--yes' => true]);

    expect($code)->toBe(0, (string) json_encode($json))->and($json['data'])->toMatchArray(['abandoned' => true, 'returned_to_previous' => true])
        ->and($this->restore->databases)->toContain($names->live)->not->toContain($names->scratch)
        ->and(new OperationJournal($this->restore->instance->directory)->current())->toBeNull();
});

it('finishes a return killed between renaming the databases back and recording it (review A2)', function (): void {
    $names = InterruptedRestore::returning($this->restore, [RestoreRecovery::DATABASE_RETURNING]); // renamed back, not yet noted

    [$code, $json] = $this->restore->restore();

    expect($code)->toBe(5, (string) json_encode($json))->and($json['data']['returned_to_previous'])->toBeTrue()
        ->and($this->restore->actions())->not->toContain('swap')->not->toContain('rename')
        ->and($this->restore->databases)->toContain($names->live)->not->toContain($names->scratch);
});

it('never repeats a completed return: a run killed before its clean-up only drops the restored database (review)', function (): void {
    $names = InterruptedRestore::returning($this->restore, [RestoreRecovery::DATABASE_RETURNING, RestoreRecovery::DATABASE_BACK]);
    new OperationJournal($this->restore->instance->directory)->completePhase('return', ['returned' => true]);
    $mark = $this->restore->config->mark();

    [$code, $json] = $this->restore->restore();
    $flushes = array_filter($this->restore->config->callsSince($mark, 'composeRun'), static fn (array $call): bool => ($call['command'][0] ?? null) === 'valkey-cli');

    expect($code)->toBe(5, (string) json_encode($json))->and($json['data']['returned_to_previous'])->toBeTrue()
        ->and($this->restore->config->callsSince($mark, 'composeUp'))->toBe([])->and($flushes)->toBe([])
        ->and($this->restore->databases)->not->toContain($names->scratch)
        ->and(new OperationJournal($this->restore->instance->directory)->current())->toBeNull();
});

it('still stops with exit 8 when the put-aside database vanished before any return renamed it', function (): void {
    InterruptedRestore::returning($this->restore, []);

    [$code, $json] = $this->restore->restore();

    expect($code)->toBe(8, (string) json_encode($json))->and($json['errors'][0]['message'])->toContain('is gone');
});

it('renames the put-aside database back when a run was killed between the two renames of the swap (review A8)', function (): void {
    $names = InterruptedRestore::journal($this->restore, 'erasures', started: 'swap');
    InterruptedRestore::halfSwapped($this->restore, $names);
    $mark = $this->restore->config->mark();

    [$code, $json] = $this->restore->restore();
    $rename = array_values(array_filter($this->restore->databaseCalls, static fn (array $call): bool => $call['action'] === 'rename'));

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($rename[0]['options'] ?? [])->toContain('--database='.$names->aside)->toContain('--to='.$names->live)
        ->and($this->restore->databases)->toContain($names->live)->not->toContain($names->aside)->not->toContain($names->scratch)
        ->and(removedVolumes($this->restore, $mark))->toBe([$names->freshVolume]);
});

it('swaps back when the swap renamed both databases before the journal recorded it, then drops the restored one and its volume', function (): void {
    $names = InterruptedRestore::journal($this->restore, 'erasures', started: 'swap');
    InterruptedRestore::swapped($this->restore, $names);
    $mark = $this->restore->config->mark();

    [$code, $json] = $this->restore->restore();
    $swaps = array_values(array_filter($this->restore->databaseCalls, static fn (array $call): bool => $call['action'] === 'swap'));
    $drops = array_values(array_filter($this->restore->databaseCalls, static fn (array $call): bool => $call['action'] === 'drop'));

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($swaps[0]['options'] ?? [])->toContain('--database='.$names->aside)->toContain('--live='.$names->live)->toContain('--aside='.$names->scratch)
        ->and($drops[0]['options'] ?? [])->toContain('--database='.$names->scratch)
        ->and($this->restore->databases)->toContain($names->live)->not->toContain($names->aside)->not->toContain($names->scratch)
        ->and(removedVolumes($this->restore, $mark))->toBe([$names->freshVolume]);
});

it('starts every service behind the gate and lowers it last (review A4)', function (): void {
    $gates = [];
    $this->restore->instance->docker->onCall['composeUp'] = function (array $args) use (&$gates): void {
        $gates[] = [$args['services'], InterruptedRestore::gateRaised($this->dir)];
    };

    [$code, $json] = $this->restore->restore();
    $full = array_values(array_filter($gates, static fn (array $gate): bool => $gate[0] === []));

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($full)->toHaveCount(1)->and($full[0][1])->toBeTrue()
        ->and(InterruptedRestore::gateRaised($this->dir))->toBeFalse();
});

it('returns to the previous instance when the full start fails behind the gate, before any request reached the restored data (review A4)', function (): void {
    $full = 0;
    $this->restore->instance->docker->onCall['composeUp'] = function (array $args) use (&$full): void {
        if ($args['services'] === [] && ++$full === 1) {
            throw new RuntimeException('dependency failed to start: container queue is unhealthy');
        }
    };

    [$code, $json] = $this->restore->restore();
    $runtime = json_decode((string) file_get_contents($this->dir.'/runtime/runtime.json'), true);
    $firstSwap = InterruptedRestore::position($this->restore, InterruptedRestore::databaseCall('swap'));
    $swapBack = InterruptedRestore::position($this->restore, InterruptedRestore::databaseCall('swap'), $firstSwap + 1);
    $stop = InterruptedRestore::position($this->restore, static fn (string $method, array $args): bool => $method === 'composeStop' && $args['services'] === ['app', 'queue', 'scheduler', 'backup'], $firstSwap + 1);

    expect($code)->toBe(5, (string) json_encode($json))->and($json['data']['returned_to_previous'])->toBeTrue()
        ->and(InstanceSettings::of($this->restore->config->state())->webhooksPaused())->toBeFalse()
        ->and($runtime['gate']['raised'])->toBeFalse()->and($runtime['webhooks']['paused'] ?? null)->toBeFalse()
        ->and($stop)->toBeGreaterThan($firstSwap)->toBeLessThan($swapBack); // the workers open started stop first (review M3)
});

it('pauses workflow webhooks after an in-place restore until the operator turns them on (review A15)', function (): void {
    [$code, $json] = $this->restore->restore();
    $runtime = json_decode((string) file_get_contents($this->dir.'/runtime/runtime.json'), true);

    expect($code)->toBe(0, (string) json_encode($json))->and($json['data']['webhooks'])->toBe('paused')
        ->and(collect($json['warnings'])->firstWhere('code', 'webhooks_paused')['hint'] ?? '')->toContain('fsa config set webhooks on')
        ->and(InstanceSettings::of($this->restore->config->state())->webhooksPaused())->toBeTrue()
        ->and($runtime['webhooks']['paused'])->toBeTrue();
});

it('seals the safety backup when the restore exits 5, not only after a completed one (review A6)', function (): void {
    $this->restore->instance->directory->saveState($this->restore->config->state()->withRecipients(['age1'.str_repeat('c', 58)]));
    $this->restore->pgRestore = new ProcessOutcome(1, '', 'pg_restore: error: connection lost');

    [$code, $json] = $this->restore->restore();
    $seal = InterruptedRestore::position($this->restore, static fn (string $method, array $args): bool => $method === 'composeRun' && str_starts_with($args['command'][2] ?? '', '--seal=safety-restore-'));
    $calls = $this->restore->instance->docker->calls;
    $reopen = max(array_keys(array_filter($calls, static fn (array $call): bool => $call['method'] === 'composeUp' && $call['args']['services'] === [])));

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($calls[$seal]['args']['command'][3] ?? '')->toStartWith('--lock-id=')
        ->and($seal)->toBeGreaterThan($reopen) // after the instance runs again: no longer downtime (review M2)
        ->and($this->restore->config->archivedJournal('restore')->hasCompleted('abort'))->toBeTrue()
        ->and(array_column($json['warnings'], 'code'))->not->toContain('safety_backup_not_sealed');
});

it('still exits 5 and warns when sealing the safety backup fails after an abort (review A6)', function (): void {
    $this->restore->instance->directory->saveState($this->restore->config->state()->withRecipients(['age1'.str_repeat('c', 58)]));
    $this->restore->pgRestore = new ProcessOutcome(1, '', 'pg_restore: error: connection lost');
    $this->restore->instance->docker->onCall['composeRun'] = function (array $args): void {
        if (str_starts_with($args['command'][2] ?? '', '--seal=')) {
            $this->restore->instance->backupOutcome = new ProcessOutcome(1, '', 'age: no recipients');
        }
    };

    [$code, $json] = $this->restore->restore();

    expect($code)->toBe(5, (string) json_encode($json))
        ->and(collect($json['warnings'])->firstWhere('code', 'safety_backup_not_sealed')['message'] ?? '')->toContain('safety-restore-');
});

it('goes through the way back when a resumed run fails to prepare on the instance a killed run quiesced (review A7)', function (): void {
    $names = InterruptedRestore::journal($this->restore, 'restore_storage');
    InterruptedRestore::raiseGate($this->restore);
    file_put_contents($this->dir.'/backups/manual-20261019T120000Z/database.dump', 'changed');
    $mark = $this->restore->config->mark();

    [$code, $json] = $this->restore->restore();
    $ups = $this->restore->config->callsSince($mark, 'composeUp');

    expect($code)->toBe(5, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe('backup_damaged')
        ->and($json['changed'])->toBeTrue()
        ->and($this->restore->databases)->not->toContain($names->scratch)
        ->and($ups[array_key_last($ups)]['services'] ?? null)->toBe([])
        ->and(InterruptedRestore::gateRaised($this->dir))->toBeFalse()
        ->and(new OperationJournal($this->restore->instance->directory)->current())->toBeNull();
});

it('does not count the safety backup a killed run already took when a resumed run checks the space (review A7)', function (): void {
    InterruptedRestore::journal($this->restore, 'safety_backup');
    $this->restore->backup('manual-20261019T130000Z', storage: new TarBuilder()->directory('./')->file('./private/big.bin', random_bytes(3 * 1024 ** 2)));
    $this->restore->freeBytes = RestoreSpace::HEADROOM + 1024 ** 2;

    [$code, $json] = $this->restore->restore();
    $space = $this->restore->config->archivedJournal('restore')->phaseData('space');

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($space['required'])->toBeLessThan(RestoreSpace::HEADROOM + 64 * 1024) // the 3 MiB backup is not counted
        ->and($space['free'])->toBe(RestoreSpace::HEADROOM + 1024 ** 2)
        ->and($this->restore->actions()[0] ?? null)->toBe('drop'); // the killed run's scratch database went first
});

it('removes what a killed run left in its scratch directory before it checks the room for the new copy (M12 gate)', function (): void {
    $names = InterruptedRestore::journal($this->restore, 'plan', started: 'prepare');
    $stale = $this->dir.'/staging/restore-'.$names->id.'/set/database.dump';
    mkdir(dirname($stale), 0750, true);
    file_put_contents($stale, 'a copy the killed run made');
    $seen = [];
    app()->instance(RestoreSpace::FREE_BYTES, static function () use ($stale, &$seen): int {
        $old = is_file($stale) && file_get_contents($stale) === 'a copy the killed run made';
        $seen[] = $old;

        return $old ? 0 : PHP_INT_MAX; // the old copy still takes the room
    });

    [$code, $json] = $this->restore->restore();

    expect($code)->toBe(0, (string) json_encode($json))->and($seen[0] ?? null)->toBeFalse();
});

it('warns when the free disk space cannot be read instead of passing silently (review A12)', function (): void {
    [$code, $json] = $this->restore->restore();
    $this->restore->freeBytes = PHP_INT_MAX;
    [$again, $known] = $this->restore->restore('manual-20261019T120000Z');

    expect($code)->toBe(0, (string) json_encode($json))->and(array_column($json['warnings'], 'code'))->toContain('disk_space_unknown')
        ->and($again)->toBe(0)->and(array_column($known['warnings'], 'code'))->not->toContain('disk_space_unknown');
});

it('reports a swap killed half-way as interrupted, not as "not swapped" (review A13)', function (): void {
    InterruptedRestore::journal($this->restore, 'erasures', started: 'swap');

    [, $status] = $this->restore->instance->run('fsa:status');

    expect($status['data']['restore']['interrupted'])->toMatchArray(['swap' => 'interrupted', 'swapped' => null, 'returning' => false]);
});
