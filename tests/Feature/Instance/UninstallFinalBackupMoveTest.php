<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Restore\BackupSet;
use App\Domain\Instance\State\EnvFiles;
use Tests\Support\Instance\BackupSetFixture;
use Tests\Support\Instance\FromBackupFixture;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * Uninstall's final backup is a 2.0 backup (ADR 0073 as amended by the M12 gate, DOC12/D3): what `fieldset uninstall
 * --keep-backups` leaves in backups/ is a set `fieldset install --from-backup` takes, authenticated with the APP keys saved
 * beside it. The backup service's one-off writes a real 2.0 set here (BackupSetFixture), as the service would.
 */

afterEach(fn () => TemporaryInstance::cleanup());

it('leaves a final backup that fieldset install --from-backup accepts, authenticated with the key file beside it', function (): void {
    $old = new InstanceCommandFixture;
    $old->run('fieldset:install', InstanceCommandFixture::installFlags());
    $old->withRunningStack();
    $state = $old->state();
    $old->docker->withResource(new DockerResource(DockerResource::VOLUME, $state->project().'_pg-data', [...Labels::forInstance($state->uuid()), 'com.docker.compose.volume' => 'pg-data']));
    $appKey = new EnvFiles($old->directory)->read('app')['APP_KEY'];
    $old->docker->onCall['composeRun'] = static function (array $args) use ($old, $appKey): void {
        if ($args['service'] === 'backup' && in_array('--kind=final', $args['command'], true)) {
            BackupSetFixture::fieldset($old->directory->root.'/backups/final-20261019T120000Z', ['kind' => 'final'], appKey: $appKey);
        }
    };

    [$code, $json] = $old->run('fieldset:uninstall', ['--yes' => true, '--keep-backups' => true]);
    $set = $old->directory->root.'/'.$json['data']['final_backup']['dir'];
    $keyFile = $old->directory->root.'/'.$json['data']['final_backup']['key_file'];

    expect($code)->toBe(0, (string) json_encode($json))
        ->and(BackupSet::detect($set))->toMatchArray(['format' => BackupSet::FIELDSET, 'encrypted' => false]);

    $move = new FromBackupFixture;
    exec('cp -R '.escapeshellarg($set).' '.escapeshellarg($keyFile).' '.escapeshellarg($move->outside));
    try {
        [$moved, $movedJson] = $move->install($move->outside.'/final-20261019T120000Z', ['--app-key-file' => $move->outside.'/'.basename($keyFile)]);

        expect($moved)->toBe(0, (string) json_encode($movedJson))
            ->and($movedJson['data']['from_backup'])->toMatchArray(['backup' => 'final-20261019T120000Z', 'format' => 'fieldset', 'authenticated' => true])
            ->and(new EnvFiles($move->instance->directory)->read('app')['APP_KEY'])->toBe($appKey);
    } finally {
        $move->cleanup();
    }
});
