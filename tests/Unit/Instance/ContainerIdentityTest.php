<?php

declare(strict_types=1);

use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Operations\ContainerIdentity;
use Tests\Support\Instance\RecordingDockerGateway;
use Tests\Support\Instance\TemporaryInstance;

afterEach(fn () => TemporaryInstance::cleanup());

function mountTable(string $id): string
{
    $path = TemporaryInstance::directory()->root.'/mountinfo';
    file_put_contents($path, "612 590 0:52 / / rw,relatime - overlay overlay rw\n"
        ."630 612 254:1 /docker/containers/{$id}/hostname /etc/hostname rw,relatime - ext4 /dev/vda1 rw\n");

    return $path;
}

it('identifies its container from the mount table and asks Docker for its start time', function (): void {
    $id = str_repeat('e', 64);
    $docker = (new RecordingDockerGateway)->withRunningContainer($id, '2026-10-17T10:00:00.1Z');

    $holder = new ContainerIdentity($docker, mountTable($id), 'ops')->holder('fsa', 1000);

    expect($holder->containerId)->toBe($id)
        ->and($holder->containerStartedAt)->toBe('2026-10-17T10:00:00.1Z')
        ->and($holder->owner)->toBe('fsa')
        ->and($holder->hostUid)->toBe(1000)
        ->and($holder->hostname)->toBe('ops')
        ->and($holder->pid)->toBe(getmypid());
});

it('falls back to the hostname, which Docker sets to the short container ID', function (): void {
    $docker = (new RecordingDockerGateway)->withRunningContainer('0123456789ab', '2026-10-17T10:00:00.1Z');

    expect(new ContainerIdentity($docker, '/nonexistent', '0123456789ab')->current()?->id)->toBe('0123456789ab');
});

it('reports no container outside Docker, so the lock is never judged stale', function (): void {
    $docker = new RecordingDockerGateway;

    $holder = new ContainerIdentity($docker, '/nonexistent', 'dev-laptop')->holder('fsa', null);

    expect($holder->containerId)->toBeNull()
        ->and($holder->containerStartedAt)->toBeNull()
        ->and($docker->calls)->toBe([]);
});

it('reads host facts from the shim environment', function (): void {
    $facts = HostFacts::fromEnvironment(['FSA_TTY' => '0', 'FSA_HOST_UID' => '1000', 'FSA_HOST_GID' => '1001', 'FSA_HOST_OS' => 'linux', 'FSA_INSTANCE_DIR' => '/srv/fsa']);
    $empty = HostFacts::fromEnvironment(['FSA_HOST_UID' => 'abc', 'FSA_TTY' => 'maybe']);

    expect($facts->tty)->toBeFalse()
        ->and($facts->uid)->toBe(1000)
        ->and($facts->gid)->toBe(1001)
        ->and($facts->os)->toBe('linux')
        ->and($facts->instanceDir)->toBe('/srv/fsa')
        ->and($facts->callerUid())->toBe(1000)
        ->and($empty->tty)->toBeNull()
        ->and($empty->uid)->toBeNull()
        ->and($empty->callerUid())->toBe(posix_getuid());
});
