<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Restore\MigrationSet;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\RestoreFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * `fsa restore --drill` on the ops side (R41, plan U17, ADR 0085): the backup is prepared like a restore, restored into a
 * `restore_drill_*` scratch database and checked by `instance:restore-drill` in a one-off with a null mailer and a sync
 * queue; the scratch database is dropped whatever happened, and nothing of the instance changes: no gate, no stopped
 * service, no ledger line, no state change. And the erasure carry's data exports leave the restored volume.
 */

beforeEach(function (): void {
    $this->restore = new RestoreFixture;
    $this->dir = $this->restore->instance->directory->root;
    $this->restore->backup();
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
});

it('drills a backup in a scratch database with a null mailer and a sync queue, drops it, and changes nothing else', function (): void {
    $mark = $this->restore->config->mark();
    $state = $this->restore->config->state()->toJson();

    [$code, $json] = $this->restore->instance->run('fsa:restore', ['backup' => 'manual-20261019T120000Z', '--drill' => true]);
    $drill = $this->restore->instance->oneOffCalls('instance:restore-drill')[0];
    $create = $this->restore->databaseCalls[array_search('create', $this->restore->actions(), true)];

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['changed'])->toBeFalse()
        ->and($json['data'])->toMatchArray(['backup' => 'manual-20261019T120000Z', 'dropped' => true])
        ->and($json['data']['database'])->toStartWith('restore_drill_')
        ->and($json['data']['drill']['counts'])->toBe(['organizations' => 1, 'submissions' => 4])
        ->and(implode(' ', $create['options']))->toContain('--database=restore_drill_')
        ->and($drill['env'])->toContain('DB_DATABASE', 'MAIL_MAILER', 'QUEUE_CONNECTION')
        ->and($this->restore->actions()[array_key_last($this->restore->actions())])->toBe('drop')
        ->and(array_filter($this->restore->databases, static fn (string $db): bool => str_starts_with($db, 'restore_drill_')))->toBe([])
        ->and($this->restore->config->methodsSince($mark))->not->toContain('composeStop')->not->toContain('createVolume')->not->toContain('composeUp')
        ->and(is_file($this->dir.'/backups/ledger.log'))->toBeFalse()
        ->and($this->restore->config->state()->toJson())->toBe($state)
        ->and(glob($this->dir.'/staging/restore-*'))->toBe([]);
});

it('drops what a killed drill left together with its restricted role, and keeps the drill off the live cache (review A10, A14)', function (): void {
    $this->restore->databases[] = 'restore_drill_0123456789ab';

    [$code, $json] = $this->restore->instance->run('fsa:restore', ['backup' => 'manual-20261019T120000Z', '--drill' => true]);
    $drill = $this->restore->instance->oneOffCalls('instance:restore-drill')[0];
    $first = $this->restore->databaseCalls[array_search('drop', $this->restore->actions(), true)];

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($first['options'])->toContain('--database=restore_drill_0123456789ab', '--role=fieldset_drill_0123456789ab')
        ->and($this->restore->databases)->not->toContain('restore_drill_0123456789ab')
        ->and($drill['env'])->toContain('CACHE_STORE')
        ->and($json['data']['render_storage'])->toBe('live');
});

it('hands the storage archive\'s file list to the drill on stdin', function (): void {
    $inputs = [];
    $previous = $this->restore->instance->docker->composeRunResponder;
    $this->restore->instance->docker->respondToComposeRun(static function (array $command, ?string $input = null) use ($previous, &$inputs): ?ProcessOutcome {
        if (($command[2] ?? null) === 'instance:restore-drill') {
            $inputs[] = $input;
        }

        return $previous($command, $input);
    });

    $this->restore->instance->run('fsa:restore', ['backup' => 'manual-20261019T120000Z', '--drill' => true]);

    expect($inputs)->toBe(["private/a.txt\n"]);
});

it('exits 4 when a drill check fails, and still drops the scratch database', function (): void {
    $this->restore->drill = InstanceCommandFixture::oneOffJson(['command' => 'instance:restore-drill', 'status' => 'failed',
        'data' => ['checks' => [['name' => 'decrypt', 'status' => 'fail', 'message' => 'users.two_factor_secret']]],
        'errors' => [['code' => 'drill_failed', 'message' => 'decrypt failed', 'hint' => null]]]);

    [$code, $json] = $this->restore->instance->run('fsa:restore', ['backup' => 'manual-20261019T120000Z', '--drill' => true]);

    expect($code)->toBe(4)->and($json['errors'][0]['code'])->toBe('drill_failed')
        ->and(array_filter($this->restore->databases, static fn (string $db): bool => str_starts_with($db, 'restore_drill_')))->toBe([]);
});

it('drills an older backup (migrations pending) but refuses a newer one with exit 7', function (): void {
    $image = MigrationSet::image(base_path('database/migrations'));
    $this->restore->migrations = array_slice($image, 0, -1);
    [$older] = $this->restore->instance->run('fsa:restore', ['backup' => 'manual-20261019T120000Z', '--drill' => true]);
    $this->restore->migrations = [...$image, '2099_01_01_000000_from_the_future'];
    [$newer, $newerJson] = $this->restore->instance->run('fsa:restore', ['backup' => 'manual-20261019T120000Z', '--drill' => true]);

    expect($older)->toBe(0)->and($newer)->toBe(7)->and($newerJson['errors'][0]['code'])->toBe('backup_newer');
});

it('removes the data exports of accounts erased after the backup from the restored volume, never other paths', function (): void {
    $this->restore->finish = InstanceCommandFixture::oneOffJson(['command' => 'instance:restore-finish', 'status' => 'ok', 'data' => [
        'epoch' => 2, 'previous_epoch' => 1, 'carried' => ['export_directories' => [
            ['disk' => 'local', 'path' => 'data-exports/1/42'], ['disk' => 'local', 'path' => '../../etc'], ['disk' => 's3', 'path' => 'data-exports/1/43'],
        ]],
    ]]);

    [$code, $json] = $this->restore->restore();
    $rm = $this->restore->specs('rm');

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($rm)->toHaveCount(1)
        ->and($rm[0]->command)->toBe(['-rf', '--', '/var/www/html/storage/app/private/data-exports/1/42'])
        ->and($rm[0]->network)->toBe('none')->and($rm[0]->user)->toBe('33:33');
});
