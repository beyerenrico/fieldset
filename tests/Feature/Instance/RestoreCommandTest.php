<?php

declare(strict_types=1);

use App\Domain\Instance\Backup\KeyFingerprint;
use App\Domain\Instance\Operations\Ledger;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Restore\MigrationSet;
use App\Domain\Instance\Runtime\OperationGate;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceSettings;
use Tests\Support\Instance\BackupSetFixture;
use Tests\Support\Instance\RestoreFixture;
use Tests\Support\Instance\TarBuilder;
use Tests\Support\Instance\TemporaryInstance;

/*
 * `fsa restore` on the ops side (R41, R64, R67, R68, AE8, KTD18, KTD25, KTD26, plan U17, ADR 0085), against the recording
 * Docker gateway: the socket-less prepare step (the real BackupPreparer in-process), the space check, quiesce, the ledger,
 * the safety backup, the scratch database and the fresh volume, the finish one-off, the swap, the verification behind the
 * gate and the clean-up, and every way a restore is refused before it changes anything.
 */

beforeEach(function (): void {
    $this->restore = new RestoreFixture;
    $this->dir = $this->restore->instance->directory->root;
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
});

it('restores in place: prepare without network, quiesce, ledger, safety backup, scratch database and volume, finish, swap, verify, open, clean up', function (): void {
    $this->restore->backup();
    $mark = $this->restore->config->mark();

    [$code, $json] = $this->restore->restore();
    $state = $this->restore->config->state();
    $prepare = $this->restore->specs('restore-prepare')[0];
    $pgRestore = $this->restore->specs('pg_restore')[0];
    $tar = $this->restore->specs('tar')[0];
    $finish = $this->restore->instance->oneOffCalls('instance:restore-finish')[0];
    $id = substr((string) $this->restore->config->archivedJournal('restore')->id(), 0, 12);
    $smoke = array_values(array_filter($this->restore->config->callsSince($mark, 'runContainer'), static fn (array $call): bool => $call['spec']->entrypoint === 'curl' && in_array('@-', $call['spec']->command, true)));

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data'])->toMatchArray(['backup' => 'manual-20261019T120000Z', 'format' => 'fsa', 'authenticated' => true, 'epoch' => 2, 'previous_epoch' => 1,
            'safety_backup' => 'safety-restore-20261019T120000Z', 'storage_volume' => $state->project().'_app-storage-r'.$id, 'left_behind' => []])
        ->and($prepare->network)->toBe('none')->and(json_encode($prepare))->not->toContain('docker.sock')
        ->and($prepare->volumes)->toContain($this->dir.'/backups/manual-20261019T120000Z:'.$this->dir.'/backups/manual-20261019T120000Z:ro')
        ->and($pgRestore->network)->toBe($state->project().'_default')
        ->and($pgRestore->command)->toBe(['--dbname=form_schema_restore_'.$id, '--no-owner', '--no-privileges', '--single-transaction', '--exit-on-error', '--', $this->dir.'/backups/manual-20261019T120000Z/database.dump'])
        ->and(array_keys($pgRestore->env))->toContain('PGPASSWORD')->and($pgRestore->env['PGDATABASE'])->toBe('form_schema_restore_'.$id)
        ->and($tar->network)->toBe('none')->and($tar->user)->toBe('33:33')
        ->and($tar->volumes[0])->toBe($state->project().'_app-storage-r'.$id.':/var/www/html/storage/app')
        ->and($finish['env'])->toContain('DB_DATABASE', 'FSA_BACKUP_DB_PASSWORD')->and($finish['command'])->toContain('--source=form_schema', '--backup=manual-20261019T120000Z')
        ->and($this->restore->actions())->toBe(['drop', 'create', 'swap', 'drop'])
        ->and($this->restore->databases)->toContain('form_schema')->not->toContain('form_schema_pre_restore_'.$id)->not->toContain('form_schema_restore_'.$id)
        ->and(InstanceSettings::of($state)->storageVolume())->toBe($state->project().'_app-storage-r'.$id)
        ->and($this->restore->instance->docker->called('createVolume')[0]['name'])->toBe($state->project().'_app-storage-r'.$id)
        // the fresh volume's name is cleared first (a leftover of an interrupted attempt), the replaced volume goes last
        ->and(array_column($this->restore->instance->docker->called('removeVolume'), 'name'))->toBe([$state->project().'_app-storage-r'.$id, $state->project().'_app-storage'])
        ->and($smoke)->not->toBeEmpty()->and($smoke[0]['input'])->toStartWith(OperationGate::SMOKE_HEADER.': ')
        ->and(json_decode((string) file_get_contents($this->dir.'/runtime/runtime.json'), true)['gate']['raised'])->toBeFalse()
        ->and(is_dir($this->dir.'/backups/.restore-'.$id))->toBeFalse()
        ->and($state->history()[array_key_last($state->history())]['event'] ?? null)->toBe('restored')
        ->and(new OperationJournal($this->restore->instance->directory)->current())->toBeNull();
});

it('stops the writers before the ledger and records the final heads there', function (): void {
    $this->restore->backup();
    $mark = $this->restore->config->mark();

    $this->restore->restore();
    $stops = $this->restore->config->callsSince($mark, 'composeStop');
    $ledger = new Ledger($this->restore->instance->directory)->last('restore');

    expect($stops[0]['services'])->toBe(['app', 'scheduler', 'backup'])
        ->and($stops[1])->toMatchArray(['services' => ['queue'], 'timeout' => 100])
        ->and($ledger)->toMatchArray(['action' => 'restore', 'backup' => 'manual-20261019T120000Z', 'epoch' => 1, 'intact' => true])
        ->and($ledger['heads'])->toBe(['0' => ['seq' => 7, 'hash' => str_repeat('a', 64)]])
        ->and($this->restore->instance->oneOffCalls('audit:seal'))->toHaveCount(1);
});

it('asks before replacing the instance (exit 6 without --yes) and changes nothing', function (): void {
    $this->restore->backup();
    $mark = $this->restore->config->mark();

    [$code, $json] = $this->restore->instance->run('fsa:restore', ['backup' => 'manual-20261019T120000Z']);

    expect($code)->toBe(6)->and($json['errors'][0]['code'])->toBe('confirmation_required')
        ->and($this->restore->config->methodsSince($mark))->not->toContain('composeStop');
});

it('refuses a backup with a newer migration set: exit 7 and nothing changed (AE8)', function (): void {
    $this->restore->migrations = [...MigrationSet::image(base_path('database/migrations')), '2099_01_01_000000_from_the_future'];
    $this->restore->backup();
    $mark = $this->restore->config->mark();
    $before = $this->restore->config->state()->toJson();

    [$code, $json] = $this->restore->restore();

    expect($code)->toBe(7)->and($json['errors'][0]['code'])->toBe('backup_newer')->and($json['changed'])->toBeFalse()
        ->and($this->restore->config->methodsSince($mark))->not->toContain('composeStop')->not->toContain('createVolume')->not->toContain('composeUp')
        ->and($this->restore->actions())->toBe([])
        ->and($this->restore->config->state()->toJson())->toBe($before)
        ->and(json_decode((string) file_get_contents($this->dir.'/runtime/runtime.json'), true)['gate']['raised'])->toBeFalse()
        ->and(glob($this->dir.'/backups/.restore-*'))->toBe([]);
});

it('refuses an older backup in place until the upgrade child exists (exit 7, a hint to --from-backup)', function (): void {
    $this->restore->migrations = array_slice(MigrationSet::image(base_path('database/migrations')), 0, -2);
    $this->restore->backup();

    [$code, $json] = $this->restore->restore();

    expect($code)->toBe(7)->and($json['errors'][0]['code'])->toBe('upgrade_child_unavailable')
        ->and($json['errors'][0]['hint'])->toContain('fsa install --from-backup');
});

it('refuses a modified backup (damaged, exit 2) and one whose HMAC no instance key verifies', function (): void {
    $damaged = $this->restore->backup('manual-20261019T130000Z');
    file_put_contents($damaged.'/settings.json', '{"url":"https://evil.example"}');
    $foreign = $this->restore->backup('manual-20261019T140000Z', BackupSetFixture::OTHER_KEY);

    [$damagedCode, $damagedJson] = $this->restore->restore('manual-20261019T130000Z');
    [$foreignCode, $foreignJson] = $this->restore->restore('manual-20261019T140000Z');

    expect($damagedCode)->toBe(2)->and($damagedJson['errors'][0]['code'])->toBe('backup_damaged')
        ->and($foreignCode)->toBe(2)->and($foreignJson['errors'][0]['code'])->toBe('backup_unauthenticated')
        ->and($foreignJson['errors'][0]['hint'])->toContain('--accept-unauthenticated');
});

it('refuses a hostile archive before anything is written and keeps the instance running', function (): void {
    $this->restore->backup(storage: new TarBuilder()->file('./../../etc/cron.d/evil', '* * * * * root sh'));
    $mark = $this->restore->config->mark();

    [$code, $json] = $this->restore->restore();

    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe('backup_unsafe')
        ->and($this->restore->config->methodsSince($mark))->not->toContain('composeStop')
        ->and(file_exists('/etc/cron.d/evil'))->toBeFalse();
});

it('restores an unauthenticated archive only with --accept-unauthenticated, as a role owning nothing but the scratch database', function (): void {
    $set = $this->restore->backup();
    file_put_contents($set.'/settings.json', '{"schema_version":"1.0","settings":{}}'); // changed, and the sums recomputed without the key
    $sums = '';
    foreach (['audit-heads.json', 'database.dump', 'manifest.json', 'settings.json', 'storage.tar.gz'] as $file) {
        $sums .= hash_file('sha256', $set.'/'.$file).'  '.$file."\n";
    }
    file_put_contents($set.'/SHA256SUMS', $sums);

    [$refused, $refusedJson] = $this->restore->restore();
    [$code, $json] = $this->restore->restore(options: ['--accept-unauthenticated' => true]);
    $create = array_values(array_filter($this->restore->databaseCalls, static fn (array $call): bool => $call['action'] === 'create'))[0];
    $pgRestore = $this->restore->specs('pg_restore')[0];

    expect($refused)->toBe(2)->and($refusedJson['errors'][0]['code'])->toBe('backup_unauthenticated')
        ->and($code)->toBe(0, (string) json_encode($json))
        ->and(implode(' ', $create['options']))->toContain('--restricted-role=fsa_restore_')
        ->and($create['env'])->toBe(['FSA_RESTORE_DB_PASSWORD'])
        ->and($pgRestore->env['PGUSER'])->toStartWith('fsa_restore_')
        ->and(implode(' ', $pgRestore->command))->toMatch('#--use-list=\S+/backups/\.restore-[0-9a-f]{12}/restore\.list -- #') // review S1
        ->and($this->restore->actions())->toContain('adopt')
        ->and(array_search('audit', $this->restore->actions(), true))->toBeLessThan(array_search('adopt', $this->restore->actions(), true))
        ->and(implode(' ', $this->restore->databaseCalls[array_search('audit', $this->restore->actions(), true)]['options']))->toContain('--reference=form_schema')
        ->and($json['data']['authenticated'])->toBeFalse()
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('backup_unauthenticated');
});

it('refuses a backup encrypted under an unknown APP key unless the key file supplies it, then keeps that key as a previous key', function (): void {
    $this->restore->backup(appKey: BackupSetFixture::OTHER_KEY);
    $keyFile = sys_get_temp_dir().'/fsa-test-key-'.bin2hex(random_bytes(3));
    file_put_contents($keyFile, BackupSetFixture::OTHER_KEY."\n");

    [$refused, $refusedJson] = $this->restore->restore();
    [$code, $json] = $this->restore->restore(options: ['--app-key-file' => $keyFile]);
    $finish = $this->restore->instance->oneOffCalls('instance:restore-finish')[0];
    $app = new EnvFiles($this->restore->instance->directory)->read('app');
    @unlink($keyFile);

    expect($refused)->toBe(2)->and($refusedJson['errors'][0]['code'])->toBe('backup_unauthenticated')
        ->and($code)->toBe(0, (string) json_encode($json))
        ->and($finish['env'])->toContain('APP_PREVIOUS_KEYS')
        ->and($app['APP_PREVIOUS_KEYS'] ?? null)->toBe(BackupSetFixture::OTHER_KEY)
        ->and($json['data']['keys_added'])->toBe([KeyFingerprint::of(BackupSetFixture::OTHER_KEY)])
        ->and(json_encode($json))->not->toContain(BackupSetFixture::OTHER_KEY);
});

it('exits 3 when the disk cannot hold the safety backup, a second database and volume, before stopping anything', function (): void {
    $this->restore->backup();
    $this->restore->freeBytes = 1024;
    $mark = $this->restore->config->mark();

    [$code, $json] = $this->restore->restore();

    expect($code)->toBe(3)->and($json['errors'][0]['code'])->toBe('low_disk_space')
        ->and($this->restore->config->methodsSince($mark))->not->toContain('composeStop');
});

it('refuses a backup outside the instance directory and one that does not exist', function (): void {
    [$outside, $outsideJson] = $this->restore->restore('/etc');
    [$missing, $missingJson] = $this->restore->restore('manual-20990101T000000Z');

    expect($outside)->toBe(2)->and($outsideJson['errors'][0]['code'])->toBe('backup_not_visible')
        ->and($missing)->toBe(2)->and($missingJson['errors'][0]['code'])->toBe('backup_not_found');
});

it('lets uninstall archive and remove a restored storage volume like Compose\'s own (final backup, ADR 0073)', function (): void {
    $this->restore->backup();
    $this->restore->restore();
    $fresh = InstanceSettings::of($this->restore->config->state())->storageVolume();

    [$code, $json] = $this->restore->instance->run('fsa:uninstall', ['--yes' => true, '--keep-backups' => true]);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data']['final_backup']['files'])->toContain('app-storage.tar')
        ->and($json['data']['removed']['volumes'])->toContain($fresh);
});

it('refuses an unauthenticated archive whose schema carries code this version does not define, before adopting it (review)', function (): void {
    $set = $this->restore->backup();
    unlink($set.'/SHA256SUMS.hmac'); // no HMAC at all
    $this->restore->auditMismatch = 'unknown function:public.steal()';

    [$code, $json] = $this->restore->restore(options: ['--accept-unauthenticated' => true]);

    expect($code)->toBe(5, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe('backup_unsafe')
        ->and($json['errors'][0]['message'])->toContain('steal()')
        ->and($this->restore->actions())->not->toContain('adopt')->not->toContain('swap')
        ->and(InstanceSettings::of($this->restore->config->state())->storageVolume())->toBeNull();
});

it('authenticates only with the current APP key or a key file, never with a previous key that may have leaked (review)', function (): void {
    $env = new EnvFiles($this->restore->instance->directory);
    $env->write('app', [...$env->read('app'), 'APP_PREVIOUS_KEYS' => BackupSetFixture::OTHER_KEY]);
    $this->restore->backup(appKey: BackupSetFixture::OTHER_KEY);
    $keyFile = sys_get_temp_dir().'/fsa-test-key-'.bin2hex(random_bytes(3));
    file_put_contents($keyFile, BackupSetFixture::OTHER_KEY."\n");

    [$previous, $previousJson] = $this->restore->restore();
    [$explicit, $explicitJson] = $this->restore->restore(options: ['--app-key-file' => $keyFile]);
    @unlink($keyFile);

    expect($previous)->toBe(2)->and($previousJson['errors'][0]['code'])->toBe('backup_unauthenticated')
        ->and($explicit)->toBe(0, (string) json_encode($explicitJson))->and($explicitJson['data']['authenticated'])->toBeTrue();
});

it('refuses in place a backup another instance made and points to --from-backup: the carry matches accounts by number (review A3)', function (): void {
    $this->restore->backup(instance: '00000000-0000-4000-8000-0000000000a3');
    $mark = $this->restore->config->mark();

    [$code, $json] = $this->restore->restore();

    expect($code)->toBe(2, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe('backup_other_instance')
        ->and($json['errors'][0]['hint'])->toContain('fsa install --from-backup')
        ->and($json['data']['backup_instance'])->toBe('00000000-0000-4000-8000-0000000000a3')
        ->and($this->restore->config->methodsSince($mark))->not->toContain('composeStop');
});

it('takes a 1.0 backup in place only when the APP key in its env is one the instance holds (review A3)', function (): void {
    BackupSetFixture::legacy($this->dir.'/backups/20261001T020000Z', 'APP_KEY='.$this->restore->appKey()."\n");
    BackupSetFixture::legacy($this->dir.'/backups/20261002T020000Z', 'APP_KEY='.BackupSetFixture::OTHER_KEY."\n");
    BackupSetFixture::legacy($this->dir.'/backups/20261003T020000Z');

    [$other, $otherJson] = $this->restore->restore('20261002T020000Z', ['--accept-unauthenticated' => true]);
    [$bare, $bareJson] = $this->restore->restore('20261003T020000Z', ['--accept-unauthenticated' => true]);
    [$own, $ownJson] = $this->restore->restore('20261001T020000Z', ['--accept-unauthenticated' => true]);

    expect($other)->toBe(2, (string) json_encode($otherJson))->and($otherJson['errors'][0]['code'])->toBe('backup_other_instance')
        ->and($bare)->toBe(2)->and($bareJson['errors'][0]['message'])->toContain('without its env file')
        ->and($own)->toBe(0, (string) json_encode($ownJson));
});
