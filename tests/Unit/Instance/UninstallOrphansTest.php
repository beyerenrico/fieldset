<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Install\ProjectConflicts;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * M9 gate fix (R63, ADR 0072): `fieldset uninstall --orphans <uuid>` removes only the leftovers of an installation that
 * `fieldset install` named as an orphan of this directory, never while one of its containers runs (it may have moved,
 * exit 7), and never its volumes while it has containers in another directory.
 */

const UNINSTALL_ORPHAN = '33333333-3333-4333-8333-333333333333';

beforeEach(function (): void {
    $this->fixture = new InstanceCommandFixture;
    $this->fixture->run('fieldset:install', InstanceCommandFixture::installFlags());
    $this->uuid = $this->fixture->state()->uuid();
    $this->fixture->withRunningStack();
    $this->fixture->docker->withRunningContainer(str_repeat('7', 64), '2026-10-18T09:00:00Z', [Labels::INSTANCE => UNINSTALL_ORPHAN, ProjectConflicts::WORKING_DIR_LABEL => $this->fixture->directory->root], 'orphan-app-1')
        ->stopContainer(str_repeat('7', 64))
        ->withResource(new DockerResource(DockerResource::VOLUME, 'orphan_pg-data', Labels::forInstance(UNINSTALL_ORPHAN)))
        ->withResource(new DockerResource(DockerResource::NETWORK, 'orphan_default', Labels::forInstance(UNINSTALL_ORPHAN)))
        ->withResource(new DockerResource(DockerResource::VOLUME, 'own_pg-data', Labels::forInstance($this->uuid)));
});

afterEach(fn () => TemporaryInstance::cleanup());

it('removes the stopped leftovers of an orphan install named for this directory, and only those', function (): void {
    [$code, $json] = $this->fixture->run('fieldset:uninstall', ['--yes' => true, '--orphans' => UNINSTALL_ORPHAN]);
    [$againCode, $again] = $this->fixture->run('fieldset:uninstall', ['--yes' => true, '--orphans' => UNINSTALL_ORPHAN]);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['changed'])->toBeTrue()
        ->and($json['data']['removed'])->toBe(['containers' => ['orphan-app-1'], 'networks' => ['orphan_default'], 'volumes' => ['orphan_pg-data']])
        ->and(array_map(static fn (DockerResource $r): string => $r->name, $this->fixture->docker->resources))->toBe(['own_pg-data'])
        ->and(count($this->fixture->docker->containers))->toBe(8) // the running stack has the backup service since U16
        ->and($this->fixture->directory->hasState())->toBeTrue()
        ->and($againCode)->toBe(2)
        ->and($again['errors'][0]['code'])->toBe('not_an_orphan');
});

it('refuses with exit 7 while a container of that installation runs, wherever it is', function (): void {
    $this->fixture->docker->withRunningContainer(str_repeat('8', 64), '2026-10-18T09:00:00Z', [Labels::INSTANCE => UNINSTALL_ORPHAN, ProjectConflicts::WORKING_DIR_LABEL => '/srv/moved-here'], 'orphan-queue-1');

    [$code, $json] = $this->fixture->run('fieldset:uninstall', ['--yes' => true, '--orphans' => UNINSTALL_ORPHAN]);

    expect($code)->toBe(7)
        ->and($json['errors'][0]['code'])->toBe('orphans_live')
        ->and($json['data']['running'])->toBe(['orphan-queue-1'])
        ->and($this->fixture->docker->called('removeContainer'))->toBe([])
        ->and($this->fixture->docker->called('removeVolume'))->toBe([]);
});

it('keeps the volumes and networks of an installation that has stopped containers in another directory', function (): void {
    $this->fixture->docker->withRunningContainer(str_repeat('8', 64), '2026-10-18T09:00:00Z', [Labels::INSTANCE => UNINSTALL_ORPHAN, ProjectConflicts::WORKING_DIR_LABEL => '/srv/moved-here'], 'orphan-queue-1')
        ->stopContainer(str_repeat('8', 64));

    [$code, $json] = $this->fixture->run('fieldset:uninstall', ['--yes' => true, '--orphans' => UNINSTALL_ORPHAN]);

    expect($code)->toBe(0)
        ->and($json['data']['removed'])->toBe(['containers' => ['orphan-app-1'], 'networks' => [], 'volumes' => []])
        ->and($json['warnings'][0]['code'])->toBe('orphan_volumes_kept')
        ->and($json['warnings'][0]['message'])->toContain('orphan-queue-1')
        ->and(array_keys($this->fixture->docker->containers))->toContain(str_repeat('8', 64))
        ->and(array_map(static fn (DockerResource $r): string => $r->name, $this->fixture->docker->resources))->toBe(['orphan_pg-data', 'orphan_default', 'own_pg-data']);
});

it('refuses an installation that never ran from this directory, a malformed UUID and this instance', function (): void {
    $this->fixture->docker->withRunningContainer(str_repeat('9', 64), '2026-10-18T10:00:00Z', [Labels::INSTANCE => '22222222-2222-4222-8222-222222222222'], 'other-app-1')
        ->stopContainer(str_repeat('9', 64));

    [$foreignCode, $foreign] = $this->fixture->run('fieldset:uninstall', ['--yes' => true, '--orphans' => '22222222-2222-4222-8222-222222222222']);
    [$badCode] = $this->fixture->run('fieldset:uninstall', ['--yes' => true, '--orphans' => 'nope']);
    [$ownCode, $own] = $this->fixture->run('fieldset:uninstall', ['--yes' => true, '--orphans' => $this->uuid]);

    expect($foreignCode)->toBe(2)
        ->and($foreign['errors'][0]['code'])->toBe('not_an_orphan')
        ->and($foreign['errors'][0]['hint'])->toContain('docker ps -a --filter label=app.fieldset.instance=22222222-2222-4222-8222-222222222222')
        ->and($badCode)->toBe(2)
        ->and($ownCode)->toBe(2)
        ->and($own['errors'][0]['message'])->toContain('this instance')
        ->and($this->fixture->docker->called('removeContainer'))->toBe([]);
});
