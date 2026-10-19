<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Install\ProjectConflicts;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * U5 / M9-T08: `fsa uninstall` (R44, R63): only resources labelled with the instance UUID go, exit 6 without
 * --yes, a partial removal keeps the files (exit 4), and without an installation ID nothing is deleted while
 * labelled resources of the directory remain (exit 7). Final backup: UninstallFinalBackupTest; --orphans:
 * UninstallOrphansTest.
 */

beforeEach(function (): void {
    $this->fixture = new InstanceCommandFixture;
    $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags());
    $this->uuid = $this->fixture->state()->uuid();
    $this->fixture->withRunningStack();
    $this->fixture->docker->withResource(new DockerResource(DockerResource::VOLUME, 'proj_pg-data', Labels::forInstance($this->uuid)))
        ->withResource(new DockerResource(DockerResource::NETWORK, 'proj_default', Labels::forInstance($this->uuid)))
        ->withResource(new DockerResource(DockerResource::VOLUME, 'other_pg-data', Labels::forInstance('22222222-2222-4222-8222-222222222222')))
        ->withResource(new DockerResource(DockerResource::VOLUME, 'unlabelled', []));
    $this->fixture->docker->withRunningContainer(str_repeat('9', 64), '2026-10-18T10:00:00Z', [Labels::INSTANCE => '22222222-2222-4222-8222-222222222222'], 'other-app-1');
});

afterEach(fn () => TemporaryInstance::cleanup());

it('exits 6 without --yes when there is no terminal (or with --json), saying that data and backups go', function (): void {
    [$code, $json] = $this->fixture->run('fsa:uninstall');
    [, $keeping] = $this->fixture->run('fsa:uninstall', ['--keep-backups' => true]);

    expect($code)->toBe(6)
        ->and($json['errors'][0]['code'])->toBe('confirmation_required')
        ->and($json['errors'][0]['message'])->toContain('all its data')->toContain('including backups/')->toContain('No final backup is made')
        ->and($keeping['errors'][0]['message'])->toContain('Write a final backup')->toContain('except backups/')
        ->and($this->fixture->docker->called('removeContainer'))->toBe([])
        ->and($this->fixture->directory->hasState())->toBeTrue();
});

it('removes only the labelled containers, networks and volumes of this instance, then its files', function (): void {
    @mkdir($this->fixture->directory->root.'/backups'); // the render creates it since U16 (the backup service mounts it)

    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true]);
    $left = array_map(static fn (DockerResource $r): string => $r->name, $this->fixture->docker->resources);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['changed'])->toBeTrue()
        ->and($json['data']['uuid'])->toBe($this->uuid)
        ->and($json['data']['removed']['containers'])->toHaveCount(8) // with the backup service (U16)
        ->and($json['data']['removed']['networks'])->toBe(['proj_default'])
        ->and($json['data']['removed']['volumes'])->toBe(['proj_pg-data'])
        ->and($json['data'])->toMatchArray(['data_deleted' => true, 'backups_deleted' => true, 'final_backup' => null, 'kept' => []])
        ->and($this->fixture->docker->called('composeRunToFile'))->toBe([])
        ->and($left)->toBe(['other_pg-data', 'unlabelled'])
        ->and(array_keys($this->fixture->docker->containers))->toBe([str_repeat('9', 64)])
        ->and(scandir($this->fixture->directory->root))->toBe(['.', '..'])
        ->and(array_search('removeNetwork', $this->fixture->docker->methods(), true))->toBeGreaterThan(array_search('removeContainer', $this->fixture->docker->methods(), true));
});

it('leaves the running launcher its own nonce file', function (): void {
    $nonce = '.fsa-nonce-0123456789abcdef0123456789abcdef';
    touch($this->fixture->directory->root.'/'.$nonce);
    $this->fixture->facts(['nonce' => $nonce]);

    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true]);

    expect($code)->toBe(0)
        ->and($json['data']['deleted_files'])->not->toContain($nonce)
        ->and(array_values(array_diff(scandir($this->fixture->directory->root), ['.', '..'])))->toBe([$nonce]);
});

it('keeps backups/ with --keep-backups and leaves no marker, so a later install accepts the directory', function (): void {
    @mkdir($this->fixture->directory->root.'/backups'); // the render creates it since U16 (the backup service mounts it)
    file_put_contents($this->fixture->directory->root.'/backups/2026-10-18.tar', 'x');

    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true, '--keep-backups' => true]);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data']['kept'])->toBe(['backups'])
        ->and($json['data']['backups_deleted'])->toBeFalse()
        ->and(array_values(array_diff(scandir($this->fixture->directory->root), ['.', '..'])))->toBe(['backups'])
        ->and(file_exists($this->fixture->directory->root.'/backups/2026-10-18.tar'))->toBeTrue()
        ->and(file_exists($this->fixture->directory->root.'/'.InstanceDirectory::MARKER))->toBeFalse();
});

it('keeps the files when a resource cannot be removed, reporting the partial change as exit 4 to run again', function (): void {
    $this->fixture->docker->failOn('removeVolume', new DockerCommandFailed('volume rm', new ProcessOutcome(1, '', 'volume is in use')));

    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true]);
    unset($this->fixture->docker->failures['removeVolume']);
    [$againCode, $again] = $this->fixture->run('fsa:uninstall', ['--yes' => true]);

    expect($code)->toBe(4)
        ->and($json['status'])->toBe('failed')
        ->and($json['changed'])->toBeTrue()
        ->and($json['errors'][0]['code'])->toBe('uninstall_incomplete')
        ->and($json['errors'][0]['retryable'])->toBeTrue()
        ->and($json['errors'][0]['hint'])->toContain('again to finish')
        ->and($json['data']['removed']['containers'])->toHaveCount(8) // with the backup service (U16)
        ->and($againCode)->toBe(0)
        ->and($again['data']['removed']['volumes'])->toBe(['proj_pg-data']);
});

it('reports a removal that failed before anything was removed as unchanged', function (): void {
    $this->fixture->docker->failOn('removeContainer', new DockerCommandFailed('container rm', new ProcessOutcome(1, '', 'denied')));

    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true]);

    expect($code)->toBe(4)->and($json['changed'])->toBeFalse()->and($this->fixture->directory->hasState())->toBeTrue();
});

it('exits 9 while another operation holds the lock', function (): void {
    $this->fixture->docker->withRunningContainer(str_repeat('b', 64), '2026-10-18T10:00:00Z');
    new InstanceLock($this->fixture->directory, $this->fixture->docker)->acquire(TemporaryInstance::holder('b', '2026-10-18T10:00:00Z'), 'upgrade');

    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true]);

    expect($code)->toBe(9)->and($json['errors'][0]['code'])->toBe('instance_locked');
});

it('takes the UUID from the compose file when the state is unreadable, and warns when none is found', function (): void {
    $this->fixture->directory->write('state.json', '{"broken": true}');

    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true]);

    $empty = new InstanceCommandFixture;
    [$emptyCode, $nothing] = $empty->run('fsa:uninstall', ['--yes' => true]);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data']['uuid'])->toBe($this->uuid)
        ->and($json['data']['removed']['containers'])->toHaveCount(8) // with the backup service (U16)
        ->and($emptyCode)->toBe(0)
        ->and($nothing['data']['uuid'])->toBeNull()
        ->and($nothing['warnings'][0]['code'])->toBe('instance_id_unknown');
});

it('finds the UUID of an install that stopped before writing the state', function (): void {
    $fresh = new InstanceCommandFixture;
    $fresh->docker->failOn('composeConfig', new DockerCommandFailed('compose config', new ProcessOutcome(1)));
    $fresh->run('fsa:install', InstanceCommandFixture::installFlags());
    $uuid = $fresh->state()->uuid();
    $fresh->directory->files()->delete('state.json');
    $fresh->docker->withRunningContainer(str_repeat('d', 64), '2026-10-18T10:00:00Z', [Labels::INSTANCE => $uuid], 'probe');

    [$code, $json] = $fresh->run('fsa:uninstall', ['--yes' => true]);

    expect($code)->toBe(0)->and($json['data']['uuid'])->toBe($uuid)->and($json['data']['removed']['containers'])->toBe(['probe']);
});

it('deletes nothing without an installation ID while containers of this directory carry labels (exit 7)', function (): void {
    $empty = new InstanceCommandFixture;
    $empty->directory->write('env/app.env', "APP_KEY='base64:keep-me'\n");
    foreach (['33333333-3333-4333-8333-333333333333' => 'e', '44444444-4444-4444-8444-444444444444' => 'f'] as $uuid => $id) {
        $empty->docker->withRunningContainer(str_repeat($id, 64), '2026-10-18T10:00:00Z', [Labels::INSTANCE => $uuid, ProjectConflicts::WORKING_DIR_LABEL => $empty->directory->root], "c-{$id}");
    }

    [$code, $json] = $empty->run('fsa:uninstall', ['--yes' => true]);

    expect($code)->toBe(7)
        ->and($json['errors'][0]['code'])->toBe('instance_id_unknown')
        ->and($json['errors'][0]['hint'])->toContain('Nothing was deleted')
        ->and($json['data']['candidates'])->toBe(['33333333-3333-4333-8333-333333333333', '44444444-4444-4444-8444-444444444444'])
        ->and($json['data']['containers'])->toBe(['c-e', 'c-f'])
        ->and($empty->directory->exists('env/app.env'))->toBeTrue()
        ->and($empty->docker->called('removeContainer'))->toBe([]);
});

it('deletes nothing without an installation ID while a volume of a project named after this directory remains', function (): void {
    $empty = new InstanceCommandFixture;
    $uuid = '55555555-5555-4555-8555-555555555555';
    $project = InstanceState::projectFor(InstanceState::slugFor($empty->directory->root), $uuid);
    $empty->docker->withResource(new DockerResource(DockerResource::VOLUME, $project.'_pg-data', [Labels::INSTANCE => $uuid, Labels::COMPOSE_PROJECT => $project]))
        ->withResource(new DockerResource(DockerResource::VOLUME, 'fsa-elsewhere-1234_pg-data', [Labels::INSTANCE => '66666666-6666-4666-8666-666666666666', Labels::COMPOSE_PROJECT => 'fsa-elsewhere-1234']));

    [$code, $json] = $empty->run('fsa:uninstall', ['--yes' => true]);

    expect($code)->toBe(7)
        ->and($json['data']['candidates'])->toBe([$uuid])
        ->and($json['data']['volumes'])->toBe([$project.'_pg-data'])
        ->and($empty->directory->exists(InstanceDirectory::MARKER))->toBeTrue();
});
