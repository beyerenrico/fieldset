<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\State\InstanceDirectory;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * U5 / M9-T08: `fsa uninstall` (R44, R63): only resources labelled with the instance UUID go, --keep-backups
 * keeps backups/, --orphans <uuid> cleans up after a hand-deleted directory, exit 6 without --yes.
 */

beforeEach(function (): void {
    $this->fixture = new InstanceCommandFixture;
    $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags());
    $this->uuid = $this->fixture->state()->uuid();
    $this->fixture->withRunningStack();
    $this->fixture->docker->withResource(new DockerResource(DockerResource::VOLUME, 'proj_postgres-data', Labels::forInstance($this->uuid)))
        ->withResource(new DockerResource(DockerResource::NETWORK, 'proj_default', Labels::forInstance($this->uuid)))
        ->withResource(new DockerResource(DockerResource::VOLUME, 'other_postgres-data', Labels::forInstance('22222222-2222-4222-8222-222222222222')))
        ->withResource(new DockerResource(DockerResource::VOLUME, 'unlabelled', []));
    $this->fixture->docker->withRunningContainer(str_repeat('9', 64), '2026-10-18T10:00:00Z', [Labels::INSTANCE => '22222222-2222-4222-8222-222222222222'], 'other-app-1');
});

afterEach(fn () => TemporaryInstance::cleanup());

it('exits 6 without --yes when there is no terminal (or with --json), changing nothing', function (): void {
    [$code, $json] = $this->fixture->run('fsa:uninstall');

    expect($code)->toBe(6)
        ->and($json['errors'][0]['code'])->toBe('confirmation_required')
        ->and($this->fixture->docker->called('removeContainer'))->toBe([])
        ->and($this->fixture->directory->hasState())->toBeTrue();
});

it('removes only the labelled containers, networks and volumes of this instance, then its files', function (): void {
    mkdir($this->fixture->directory->root.'/backups');

    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true]);
    $left = array_map(static fn (DockerResource $r): string => $r->name, $this->fixture->docker->resources);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['changed'])->toBeTrue()
        ->and($json['data']['uuid'])->toBe($this->uuid)
        ->and($json['data']['removed']['containers'])->toHaveCount(7)
        ->and($json['data']['removed']['networks'])->toBe(['proj_default'])
        ->and($json['data']['removed']['volumes'])->toBe(['proj_postgres-data'])
        ->and($left)->toBe(['other_postgres-data', 'unlabelled'])
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
    mkdir($this->fixture->directory->root.'/backups');
    file_put_contents($this->fixture->directory->root.'/backups/2026-10-18.tar', 'x');

    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true, '--keep-backups' => true]);

    expect($code)->toBe(0)
        ->and($json['data']['kept'])->toBe(['backups'])
        ->and(array_values(array_diff(scandir($this->fixture->directory->root), ['.', '..'])))->toBe(['backups'])
        ->and(file_exists($this->fixture->directory->root.'/'.InstanceDirectory::MARKER))->toBeFalse();
});

it('removes the resources of an orphaned installation only, with --orphans', function (): void {
    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true, '--orphans' => '22222222-2222-4222-8222-222222222222']);
    [$againCode, $again] = $this->fixture->run('fsa:uninstall', ['--yes' => true, '--orphans' => '22222222-2222-4222-8222-222222222222']);

    expect($code)->toBe(0)
        ->and($json['data']['removed'])->toBe(['containers' => ['other-app-1'], 'networks' => [], 'volumes' => ['other_postgres-data']])
        ->and($this->fixture->directory->hasState())->toBeTrue()
        ->and(count($this->fixture->docker->containers))->toBe(7)
        ->and($againCode)->toBe(0)
        ->and($again['changed'])->toBeFalse()
        ->and($again['warnings'][0]['code'])->toBe('nothing_found');
});

it('refuses --orphans with a malformed UUID or this instance UUID', function (): void {
    [$badCode] = $this->fixture->run('fsa:uninstall', ['--yes' => true, '--orphans' => 'nope']);
    [$ownCode, $own] = $this->fixture->run('fsa:uninstall', ['--yes' => true, '--orphans' => $this->uuid]);

    expect($badCode)->toBe(2)->and($ownCode)->toBe(2)->and($own['errors'][0]['message'])->toContain('this instance');
});

it('keeps the files when a resource cannot be removed, so it can run again', function (): void {
    $this->fixture->docker->failOn('removeVolume', new DockerCommandFailed('volume rm', new ProcessOutcome(1, '', 'volume is in use')));

    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true]);

    expect($code)->toBe(1)
        ->and($json['errors'][0]['code'])->toBe('uninstall_incomplete')
        ->and($this->fixture->directory->hasState())->toBeTrue();
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
        ->and($json['data']['removed']['containers'])->toHaveCount(7)
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
