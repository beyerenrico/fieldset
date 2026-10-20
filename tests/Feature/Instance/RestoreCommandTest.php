<?php

declare(strict_types=1);

use App\Domain\Instance\Backup\BackupChecksums;
use App\Domain\Instance\Backup\KeyFingerprint;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Operations\Ledger;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Restore\MigrationSet;
use App\Domain\Instance\Restore\RestoreDatabases;
use App\Domain\Instance\Runtime\OperationGate;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceSettings;
use Tests\Support\Instance\BackupSetFixture;
use Tests\Support\Instance\InstanceCommandFixture;
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
        ->and([$prepare->capabilities, $pgRestore->capabilities, $tar->capabilities])->toBe([[], [], []]) // M12 gate S6: no capability
        ->and([$prepare->readOnlyRoot, $pgRestore->readOnlyRoot, $tar->readOnlyRoot])->toBe([true, true, true])
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
        ->and(is_dir($this->dir.'/staging/restore-'.$id))->toBeFalse()
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
        ->and(glob($this->dir.'/staging/restore-*'))->toBe([]);
});

// U18 (ADR 0085 amendment): an older backup is no longer refused in place (it was exit 7 `upgrade_child_unavailable`):
// the upgrade child migrates the scratch database to this release before the finish step.
it('restores an older backup in place: the upgrade child migrates the scratch database before the finish step', function (): void {
    $this->restore->migrations = array_slice(MigrationSet::image(base_path('database/migrations')), 0, -2);
    $this->restore->backup('manual-20261019T120000Z');

    [$code, $json] = $this->restore->restore();
    $id = substr((string) $this->restore->config->archivedJournal('restore')->id(), 0, 12);
    $child = $this->restore->instance->oneOffCalls('instance:upgrade-migrate');
    $phases = array_map(static fn (array $phase): string => $phase['name'], $this->restore->config->archivedJournal('restore')->phases());

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($child)->toHaveCount(1)
        ->and($child[0]['env'])->toContain('DB_DATABASE', 'PGAPPNAME', 'PGOPTIONS')
        ->and($child[0]['command'])->toContain('--application-name=fsa_upgrade_'.$id, '--from='.InstanceCommandFixture::VERSION)
        ->and(array_search('upgrade_child', $phases, true))->toBeGreaterThan(array_search('restore_database', $phases, true))
        ->and(array_search('upgrade_child', $phases, true))->toBeLessThan(array_search('finish', $phases, true));
});

it('goes back when the upgrade child cannot migrate an older backup (exit 5), the live instance untouched', function (): void {
    $this->restore->migrations = array_slice(MigrationSet::image(base_path('database/migrations')), 0, -2);
    $this->restore->backup();
    $this->restore->upgradeMigrate = InstanceCommandFixture::oneOffJson(['command' => 'instance:upgrade-migrate', 'status' => 'failed', 'errors' => [['code' => 'migration_failed', 'message' => 'lock timeout', 'hint' => null]]]);

    [$code, $json] = $this->restore->restore();

    expect($code)->toBe(5, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe('upgrade_child_failed')
        ->and($json['errors'][0]['hint'])->toContain('`install.sh --dir <new directory> --from-backup <backup>`')->not->toContain('fsa install --from-backup') // gate review DOC14
        ->and($this->restore->actions())->not->toContain('swap')
        ->and(json_decode((string) file_get_contents($this->dir.'/runtime/runtime.json'), true)['gate']['raised'])->toBeFalse();
});

it('refuses a backup older than the release\'s oldest upgrade source (exit 7)', function (): void {
    $this->restore->backup('manual-20261019T120000Z');
    $manifest = $this->dir.'/backups/manual-20261019T120000Z/manifest.json';
    BackupSetFixture::fsa(dirname($manifest), ['versions' => ['app' => '2.0.0-alpha.0', 'postgres' => '16.15'], ...$this->restore->manifest()], appKey: $this->restore->appKey());

    [$code, $json] = $this->restore->restore();

    expect($code)->toBe(7)->and($json['errors'][0]['code'])->toBe('backup_too_old');
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
        ->and(implode(' ', $pgRestore->command))->toMatch('#--use-list=\S+/staging/restore-[0-9a-f]{12}/restore\.list -- #') // review S1; never in backups/ (M12 gate S3)
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

it('lets uninstall back up and remove a restored storage volume like Compose\'s own (final backup, ADR 0073)', function (): void {
    $this->restore->backup();
    $this->restore->restore();
    $state = $this->restore->config->state();
    $fresh = InstanceSettings::of($state)->storageVolume();
    $this->restore->instance->docker->withResource(new DockerResource(DockerResource::VOLUME, $state->project().'_pg-data', [Labels::INSTANCE => $state->uuid(), 'com.docker.compose.volume' => 'pg-data'])); // Compose made it at the install

    [$code, $json] = $this->restore->instance->run('fsa:uninstall', ['--yes' => true, '--keep-backups' => true]);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data']['final_backup']['dir'])->toStartWith('backups/final-')
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
        ->and($json['errors'][0]['hint'])->toContain('`install.sh --dir <new directory> --from-backup <backup>`')->not->toContain('fsa install --from-backup') // gate review DOC14
        ->and($json['data']['backup_instance'])->toBe('00000000-0000-4000-8000-0000000000a3')
        ->and($this->restore->config->methodsSince($mark))->not->toContain('composeStop');
});

it('flushes Valkey without the server flags the image entrypoint appends to every command (found by J08)', function (): void {
    $this->restore->backup();

    [$code, $json] = $this->restore->restore();
    $flush = array_values(array_filter($this->restore->instance->docker->called('composeRun'), static fn (array $call): bool => ($call['command'][0] ?? null) === 'valkey-cli'))[0];

    expect($code)->toBe(0, (string) json_encode($json))->and($json['data']['valkey_flushed'])->toBeTrue()
        ->and($flush['service'])->toBe('redis')
        ->and($flush['env'])->toBe(['VALKEY_EXTRA_FLAGS']); // emptied: `--requirepass <password>` after FLUSHALL is a syntax error
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

it('restores the adopt backup in place through the upgrade child, trusted only as the set the adopt recorded (U19 R1, U18)', function (): void {
    $name = 'safety-adopt-20261019T120000Z';
    $set = BackupSetFixture::legacy($this->dir.'/backups/'.$name, "APP_URL='http://localhost:8080'\n", "source=fsa adopt\napp_key_fingerprints=".KeyFingerprint::of($this->restore->appKey())."\n");
    $this->restore->migrations = array_slice(MigrationSet::image(base_path('database/migrations')), 0, -2); // 1.0 is older than the instance
    [$untrusted, $untrustedJson] = $this->restore->restore($name, ['--accept-unauthenticated' => true]);
    $directory = $this->restore->instance->directory;
    $directory->saveState($directory->state()->withHistoryEntry('adopted', ['backup' => $name, 'backup_sums' => hash_file('sha256', $set.'/SHA256SUMS')]));

    [$refused, $refusedJson] = $this->restore->restore($name);
    [$code, $json] = $this->restore->restore($name, ['--accept-unauthenticated' => true]);
    $journal = array_find($this->restore->config->archivedJournals('restore'), static fn ($record): bool => $record->hasCompleted('swap')); // the refused runs archived theirs too
    $phases = array_map(static fn (array $phase): string => $phase['name'], $journal?->phases() ?? []);

    expect($untrusted)->toBe(2, (string) json_encode($untrustedJson))->and($untrustedJson['errors'][0]['code'])->toBe('backup_other_instance')
        ->and($refused)->toBe(2)->and($refusedJson['errors'][0]['code'])->toBe('backup_unauthenticated')
        ->and($code)->toBe(0, (string) json_encode($json))
        ->and($this->restore->instance->oneOffCalls('instance:upgrade-migrate'))->toHaveCount(1)
        ->and($phases)->toContain('upgrade_child', 'swap')
        ->and($json['data']['authenticated'])->toBeFalse();
});

it('hands the finish step the journal ID and the backup time, and warns about changes the carry could not attribute (third review)', function (): void {
    $this->restore->backup();
    $this->restore->finish = InstanceCommandFixture::oneOffJson(['command' => 'instance:restore-finish', 'status' => 'ok', 'changed' => true, 'data' => [
        'epoch' => 2, 'previous_epoch' => 1, 'carried' => ['devices_revoked' => 0], 'carry_mode' => 'audit',
        'carry_skipped' => ['identity_providers_deleted' => 2, 'form_access_removed' => 0], 'cascade_skipped' => ['form_access_removed' => 3],
    ]]);

    [$code, $json] = $this->restore->restore();
    $finish = $this->restore->instance->oneOffCalls('instance:restore-finish')[0];

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($finish['command'])->toContain('--backup-created-at=2026-10-19T12:00:00Z')
        ->and(implode(' ', $finish['command']))->toMatch('/--journal=[0-9a-f]{12}( |$)/')
        ->and($json['data']['carry_mode'])->toBe('audit')
        ->and(collect($json['warnings'])->firstWhere('code', 'carry_partial')['message'] ?? '')->toContain('identity_providers_deleted: 2')->not->toContain('form_access_removed')
        ->and($json['data']['cascade_skipped'])->toBe(['form_access_removed' => 3])
        ->and(RestoreDatabases::createdAt('20261001T020000Z'))->toBe('2026-10-01T02:00:00Z')
        ->and(RestoreDatabases::createdAt('2026-10-19T14:00:00+02:00'))->toBe('2026-10-19T12:00:00Z')
        ->and(RestoreDatabases::createdAt('yesterday'))->toBeNull();
});

it('warns that nothing was carried from an instance reset after the backup (U23 review A5)', function (): void {
    $this->restore->backup();
    $this->restore->finish = InstanceCommandFixture::oneOffJson(['command' => 'instance:restore-finish', 'status' => 'ok', 'changed' => true, 'data' => [
        'epoch' => 3, 'previous_epoch' => 2, 'carried' => [], 'carry_mode' => 'reset', 'carry_skipped' => [], 'cascade_skipped' => [],
    ]]);

    [$code, $json] = $this->restore->restore();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data']['carry_mode'])->toBe('reset')
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('carry_skipped_after_reset')->not->toContain('carry_partial');
});

it('refuses a backup without a storage archive unless --without-files, and then keeps the previous storage volume (M12 gate D4)', function (): void {
    $set = $this->restore->backup();
    unlink($set.'/storage.tar.gz'); // a 1.0 `--no-storage` archive has none either
    BackupChecksums::write($set, ['audit-heads.json', 'database.dump', 'manifest.json', 'settings.json'], BackupSetFixture::hmacKey($this->restore->appKey()));
    $state = $this->restore->config->state();
    $mark = $this->restore->config->mark();

    [$refused, $refusedJson] = $this->restore->restore();
    $untouched = $this->restore->config->methodsSince($mark);
    [$code, $json] = $this->restore->restore(options: ['--without-files' => true]);
    $warning = collect($json['warnings'])->firstWhere('code', 'restored_without_files');

    expect($refused)->toBe(2, (string) json_encode($refusedJson))
        ->and($refusedJson['errors'][0]['code'])->toBe('backup_without_files')
        ->and($refusedJson['errors'][0]['hint'])->toContain('--without-files')
        ->and($untouched)->not->toContain('composeStop')->not->toContain('createVolume')
        ->and($code)->toBe(0, (string) json_encode($json))
        ->and($warning['message'] ?? '')->toContain('no storage archive')->toContain($state->project().'_app-storage')
        ->and(array_column($this->restore->instance->docker->called('removeVolume'), 'name'))->not->toContain($state->project().'_app-storage')
        ->and($this->restore->specs('tar'))->toBe([]);
});

it('refuses a backup renamed to another backup\'s name: its authenticated manifest names it (M12 gate S4)', function (): void {
    $this->restore->backup('manual-20261018T120000Z');
    rename($this->dir.'/backups/manual-20261018T120000Z', $this->dir.'/backups/manual-20261019T120000Z'); // an older set under a newer name
    $mark = $this->restore->config->mark();

    [$code, $json] = $this->restore->restore('manual-20261019T120000Z');

    expect($code)->toBe(2, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('backup_renamed')
        ->and($json['errors'][0]['message'])->toContain('manual-20261018T120000Z')
        ->and($this->restore->config->methodsSince($mark))->not->toContain('composeStop');
});
