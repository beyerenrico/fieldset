<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Restore\RestoreNames;
use App\Domain\Instance\Runtime\OperationGate;
use App\Domain\Instance\State\InstanceSettings;
use Tests\Support\Instance\BackupSetFixture;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\InterruptedRestore;
use Tests\Support\Instance\RestoreFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * The ways back of `fsa restore` (plan U17, KTD18, R54, ADR 0085): a failure before the swap exits 5 with the old instance
 * running; a restored instance that fails its verification returns to the previous database and volume (exit 5); when
 * that fails too everything stops (exit 8) and the journal stays open; an interrupted restore is reported by `fsa status`,
 * resumed by running the same command again (a half-done swap is undone first) or cleaned up by `fsa restore --abandon`.
 */

beforeEach(function (): void {
    $this->restore = new RestoreFixture;
    $this->dir = $this->restore->instance->directory->root;
    $this->restore->backup();
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
});

function gateRaised(string $dir): bool
{
    return InterruptedRestore::gateRaised($dir);
}

/** @return RestoreNames the names the interrupted run's plan recorded */
function interruptedRestore(RestoreFixture $restore, string $until, ?string $started = null): RestoreNames
{
    return InterruptedRestore::journal($restore, $until, $started);
}

it('exits 5 with the old instance running when a step before the swap fails, and leaves nothing behind', function (): void {
    $this->restore->pgRestore = new ProcessOutcome(1, '', 'pg_restore: error: could not execute query: ERROR: relation exists');
    $mark = $this->restore->config->mark();

    [$code, $json] = $this->restore->restore();
    $ups = $this->restore->config->callsSince($mark, 'composeUp');

    expect($code)->toBe(5, (string) json_encode($json))->and($json['status'])->toBe('rolled_back')
        ->and($json['errors'][0]['code'])->toBe('pg_restore_failed')
        ->and($this->restore->actions())->toBe(['drop', 'create', 'drop'])->not->toContain('swap')
        ->and(array_values(array_filter($this->restore->databases, static fn (string $db): bool => str_contains($db, 'restore'))))->toBe([])
        ->and($ups[array_key_last($ups)]['services'])->toBe([])
        ->and(gateRaised($this->dir))->toBeFalse()
        ->and(InstanceSettings::of($this->restore->config->state())->storageVolume())->toBeNull()
        ->and(glob($this->dir.'/backups/.restore-*'))->toBe([])
        ->and(new OperationJournal($this->restore->instance->directory)->current())->toBeNull();
});

it('returns to the previous database and volume when the restored instance fails its verification (exit 5)', function (): void {
    $this->restore->config->smokeFails = static fn (array $urls, array $command): bool => in_array('@-', $command, true) && count(array_filter(
        $GLOBALS['restoreSmokeCalls'] = [...($GLOBALS['restoreSmokeCalls'] ?? []), 1],
    )) === 1; // only the first verification behind the gate fails
    $GLOBALS['restoreSmokeCalls'] = [];

    [$code, $json] = $this->restore->restore();
    $swaps = array_values(array_filter($this->restore->databaseCalls, static fn (array $call): bool => $call['action'] === 'swap'));
    $state = $this->restore->config->state();

    expect($code)->toBe(5, (string) json_encode($json))->and($json['data']['returned_to_previous'])->toBeTrue()
        ->and($swaps)->toHaveCount(2)
        ->and(implode(' ', $swaps[1]['options']))->toContain('--live=form_schema')->toContain('_pre_restore_')
        ->and($this->restore->databases)->toContain('form_schema')
        ->and(array_values(array_filter($this->restore->databases, static fn (string $db): bool => str_contains($db, 'restore'))))->toBe([])
        ->and(InstanceSettings::of($state)->storageVolume())->toBeNull()
        ->and(array_column($this->restore->instance->docker->called('removeVolume'), 'name'))->toContain($state->project().'_app-storage-r'.substr((string) $this->restore->config->archivedJournal('restore')->id(), 0, 12))
        ->and(gateRaised($this->dir))->toBeFalse()
        ->and(new OperationJournal($this->restore->instance->directory)->current())->toBeNull();
    unset($GLOBALS['restoreSmokeCalls']);
});

it('stops everything and exits 8 when returning to the previous instance fails too, keeping the journal open', function (): void {
    $this->restore->config->smokeFails = static fn (array $urls, array $command): bool => in_array('@-', $command, true);
    $swaps = 0;
    $this->restore->instance->docker->onCall['composeRun'] = function (array $args) use (&$swaps): void {
        if (($args['command'][2] ?? null) === 'instance:restore-db' && ($args['command'][3] ?? null) === 'swap' && ++$swaps === 2) {
            $this->restore->failDatabase['swap'] = true;
        }
    };
    $mark = $this->restore->config->mark();

    [$code, $json] = $this->restore->restore();
    $stops = $this->restore->config->callsSince($mark, 'composeStop');

    expect($code)->toBe(8, (string) json_encode($json))->and($json['status'])->toBe('rollback_failed')
        ->and($json['errors'][0]['code'])->toBe('restore_rollback_failed')
        ->and($json['data']['names']['aside'] ?? '')->toContain('_pre_restore_')
        ->and($json['data']['safety_backup'])->toBe('safety-restore-20261019T120000Z')
        ->and($stops[array_key_last($stops)]['services'])->toBe(['app', 'queue', 'scheduler', 'backup'])
        ->and(gateRaised($this->dir))->toBeTrue()
        ->and(new OperationJournal($this->restore->instance->directory)->current()?->operation())->toBe('restore');
});

it('reports a restore killed between the restore and the swap, leaves the live instance untouched, and abandons it cleanly', function (): void {
    $names = interruptedRestore($this->restore, 'erasures');
    [, $status] = $this->restore->instance->run('fsa:status');
    $mark = $this->restore->config->mark();

    [$code, $json] = $this->restore->instance->run('fsa:restore', ['--abandon' => true, '--yes' => true]);

    expect($status['data']['restore']['interrupted'])->toMatchArray(['backup' => 'manual-20261019T120000Z', 'swapped' => false, 'abandon' => 'fsa restore --abandon'])
        ->and(collect($status['warnings'])->firstWhere('code', 'operation_unfinished')['hint'] ?? '')->toContain('fsa restore --abandon')->toContain('fsa restore manual-20261019T120000Z')
        ->and($code)->toBe(0, (string) json_encode($json))->and($json['data'])->toMatchArray(['abandoned' => true, 'swapped' => false])
        ->and($this->restore->actions())->toBe(['drop'])
        ->and($this->restore->databases)->not->toContain($names->scratch)
        ->and(array_column($this->restore->config->callsSince($mark, 'removeVolume'), 'name'))->toBe([$names->freshVolume])
        ->and(is_dir($this->dir.'/backups/.restore-'.$names->id))->toBeFalse()
        ->and(gateRaised($this->dir))->toBeFalse()
        ->and(new OperationJournal($this->restore->instance->directory)->current())->toBeNull();
});

it('resumes an interrupted restore with the same command: the steps before the swap run again from a clean scratch state', function (): void {
    $names = interruptedRestore($this->restore, 'restore_storage');

    [$code, $json] = $this->restore->restore();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data']['safety_backup'])->toBe('safety-restore-20261019T120000Z')
        ->and($this->restore->actions())->toBe(['drop', 'drop', 'create', 'swap', 'drop']) // the leftovers go before the space check (review A7), then again before the restore
        ->and(array_filter($this->restore->instance->docker->called('composeRun'), static fn (array $call): bool => ($call['command'][1] ?? null) === 'backup-daemon'))->toBe([]) // the safety backup is not taken again
        ->and(InstanceSettings::of($this->restore->config->state())->storageVolume())->toBe($names->freshVolume)
        ->and(new OperationJournal($this->restore->instance->directory)->current())->toBeNull();
});

it('undoes a swap a killed run left unverified when the same command runs again (exit 5)', function (): void {
    interruptedRestore($this->restore, 'erasures', started: 'swap');

    [$code, $json] = $this->restore->restore();

    expect($code)->toBe(5, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe('restore_interrupted')
        ->and($this->restore->actions())->toContain('list')->not->toContain('swap')
        ->and(gateRaised($this->dir))->toBeFalse()
        ->and(new OperationJournal($this->restore->instance->directory)->current())->toBeNull();
});

it('restores an encrypted backup with the identity file and the passphrase from a file, never in an argument', function (): void {
    $file = BackupSetFixture::encrypt(BackupSetFixture::fsa($this->dir.'/backups/manual-20261019T150000Z', manifest: $this->restore->manifest(), appKey: $this->restore->appKey()));
    $outside = sys_get_temp_dir().'/fsa-test-identity-'.bin2hex(random_bytes(3));
    mkdir($outside, 0700);
    $identity = BackupSetFixture::identityFile($outside.'/identity.age');
    file_put_contents($outside.'/passphrase', BackupSetFixture::PASSPHRASE."\n");

    [$code, $json] = $this->restore->restore('manual-20261019T150000Z', ['--identity-file' => $identity, '--passphrase-file' => $outside.'/passphrase']);
    $prepare = $this->restore->specs('restore-prepare')[0];
    exec('rm -rf '.escapeshellarg($outside));

    expect($code)->toBe(0, (string) json_encode($json))->and($json['data']['encrypted'])->toBeTrue()
        ->and($prepare->volumes)->toContain($identity.':'.$identity.':ro')->not->toContain($outside.':'.$outside.':ro') // the file alone (review)
        ->and(implode(' ', $prepare->command))->not->toContain(BackupSetFixture::PASSPHRASE)->toContain('--identity-file='.$identity)
        ->and(json_encode($json))->not->toContain(BackupSetFixture::PASSPHRASE)
        ->and($file)->toEndWith('backup.tar.age');
});

it('shows the last restore and the storage volume in fsa status and config show', function (): void {
    $this->restore->restore();

    [, $status] = $this->restore->instance->run('fsa:status');
    [, $show] = $this->restore->config->show();

    expect($status['data']['last_restore'])->toMatchArray(['backup' => 'manual-20261019T120000Z', 'epoch' => 2, 'safety_backup' => 'safety-restore-20261019T120000Z'])
        ->and($status['data']['restore']['storage_volume'])->toContain('_app-storage-r')
        ->and($show['data']['settings']['last_restore']['backup'] ?? null)->toBe('manual-20261019T120000Z')
        ->and($show['data']['settings']['storage_volume'])->toContain('_app-storage-r');
});

it('raises the gate only with a token the smoke test sends from stdin', function (): void {
    $this->restore->restore();
    $curl = array_values(array_filter($this->restore->instance->docker->called('runContainer'), static fn (array $call): bool => in_array('@-', $call['spec']->command, true)));

    expect($curl[0]['input'])->toMatch('/^'.preg_quote(OperationGate::SMOKE_HEADER, '/').': [A-Za-z0-9_-]{32,128}\n$/')
        ->and(InstanceCommandFixture::VERSION)->toBe('2.0.0-alpha.1');
});

it('returns to the previous volume when a run killed inside the swap had already saved the fresh one (review)', function (): void {
    $names = interruptedRestore($this->restore, 'erasures', started: 'swap');
    $this->restore->instance->directory->saveState($this->restore->config->state()->withSetting('storage_volume', $names->freshVolume));

    [$code, $json] = $this->restore->restore();

    expect($code)->toBe(5, (string) json_encode($json))
        ->and(InstanceSettings::of($this->restore->config->state())->storageVolume())->toBeNull()
        ->and(ComposeFile::document($this->restore->instance->directory)['volumes']['app-storage'] ?? null)->not->toHaveKey('name');
});

it('stops with exit 8 instead of mixing data when a completed swap cannot be undone (the put-aside database is gone)', function (): void {
    interruptedRestore($this->restore, 'swap', started: 'verify');
    $this->restore->config->smokeFails = static fn (array $urls, array $command): bool => in_array('@-', $command, true);

    [$code, $json] = $this->restore->restore();

    expect($code)->toBe(8, (string) json_encode($json))
        ->and($json['errors'][0]['message'])->toContain('is gone')
        ->and(new OperationJournal($this->restore->instance->directory)->current()?->operation())->toBe('restore');
});

it('only finishes the clean-up when a run was killed during it: no second verification, no way back', function (): void {
    interruptedRestore($this->restore, 'open', started: 'cleanup');
    $mark = $this->restore->config->mark();

    [$code, $json] = $this->restore->restore();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and(array_filter($this->restore->config->callsSince($mark, 'composeUp'), static fn (array $call): bool => $call['services'] === ['app']))->toBe([])
        ->and($this->restore->actions())->not->toContain('swap')
        ->and(new OperationJournal($this->restore->instance->directory)->current())->toBeNull();
});
