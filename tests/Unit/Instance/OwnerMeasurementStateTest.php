<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\DockerRuntime;
use App\Domain\Instance\Docker\OwnerSource;
use App\Domain\Instance\Docker\WslBackend;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Restore\RestoreOperation;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\InvalidState;
use App\Domain\Instance\State\MeasuredOwner;
use App\Domain\Instance\State\SchemaVersion;
use App\Domain\Instance\Upgrade\DetachedSupervisor;
use Carbon\CarbonImmutable;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * 2.1.1 U3 in the ops container (R2, R29, KTD5; AE2): the launcher's owner facts, the measured owner in state schema 1.1
 * (which 1.0 readers keep reading), install recording it, and the restore, supervisor and doctor paths following the
 * owner the shim passes. The shim side is tests/Unit/Distribution/OwnerMeasurementTest.php.
 */

const OWNER_UUID = '3f1c2b9e-8a4d-4c1e-9b7a-2d5e6f708192';

afterEach(function (): void {
    TemporaryInstance::cleanup();
    CarbonImmutable::setTestNow();
});

/** The facts the 2.1.1 shim passes on WSL 2 with Docker Desktop after measuring pass-through (AE2). */
function wslDesktopFacts(int $uid = 1000): HostFacts
{
    return HostFacts::fromEnvironment([
        'FIELDSET_HOST_UID' => (string) $uid, 'FIELDSET_HOST_GID' => (string) $uid, 'FIELDSET_DOCKER_RUNTIME' => 'desktop', 'FIELDSET_CONTAINER_UID' => (string) $uid,
        'FIELDSET_OWNER_SOURCE' => 'measured', 'FIELDSET_SOCKET_GROUP' => '0', 'FIELDSET_WSL' => '2', 'FIELDSET_WSL_BACKEND' => 'desktop',
    ]);
}

it('reads the owner source and the in-container socket group of 2.1.1 launchers, and drops anything else', function (): void {
    $facts = wslDesktopFacts();
    $read = fn (array $env): array => [HostFacts::fromEnvironment($env)->ownerSource, HostFacts::fromEnvironment($env)->socketGroup];

    expect([$facts->ownerSource, $facts->socketGroup, $facts->containerUid, $facts->ownerUidInContainers(), $facts->wslBackend])->toBe([OwnerSource::Measured, 0, 1000, 1000, WslBackend::DockerDesktop])
        ->and($read(['FIELDSET_OWNER_SOURCE' => 'saved', 'FIELDSET_SOCKET_GROUP' => '999']))->toBe([OwnerSource::Saved, 999])
        ->and($read(['FIELDSET_OWNER_SOURCE' => 'rules', 'FIELDSET_SOCKET_GROUP' => '']))->toBe([OwnerSource::Rules, null])
        ->and($read([]))->toBe([null, null])
        ->and($read(['FIELDSET_OWNER_SOURCE' => 'guessed', 'FIELDSET_SOCKET_GROUP' => '-1']))->toBe([null, null])
        ->and($read(['FIELDSET_OWNER_SOURCE' => 'Measured', 'FIELDSET_SOCKET_GROUP' => '0 ']))->toBe([null, null]);
});

it('writes schema 1.1 and records the measured owner without touching container_uid (KTD5)', function (): void {
    CarbonImmutable::setTestNow('2026-10-26 19:30:00');
    $state = InstanceState::create(OWNER_UUID, '/home/u/fieldset', 1000, 1000, 1000);

    $measured = InstanceState::fromJson($state->withOwnerMeasurement(DockerRuntime::Desktop, 0, CarbonImmutable::now('Europe/Berlin'))->toJson());
    $again = $measured->withOwnerMeasurement(DockerRuntime::Rootful, null, CarbonImmutable::now());

    expect($state->schemaVersion())->toBe('1.1')
        ->and([$state->measuredRuntime(), $state->socketGroup(), $state->ownerMeasuredAt()])->toBe([null, null, null])
        ->and($measured->toArray()['owner'])->toBe(['host_uid' => 1000, 'host_gid' => 1000, 'container_uid' => 1000, 'runtime' => 'desktop', 'socket_group' => 0, 'measured_at' => '2026-10-26T19:30:00Z'])
        ->and([$measured->measuredRuntime(), $measured->socketGroup(), $measured->ownerMeasuredAt(), $measured->containerUid()])->toBe([DockerRuntime::Desktop, 0, '2026-10-26T19:30:00Z', 1000])
        ->and($again->toArray()['owner'])->not->toHaveKey('socket_group')
        ->and($again->measuredRuntime())->toBe(DockerRuntime::Rootful);
});

it('refuses a measured owner out of grammar: the shim reads it too', function (array $owner, string $problem): void {
    $document = InstanceState::create(OWNER_UUID, '/home/u/fieldset', 1000, 1000, 1000)->toArray();
    $document['owner'] = [...$document['owner'], ...$owner];

    expect(fn () => InstanceState::fromArray($document))->toThrow(InvalidState::class, $problem);
})->with([
    'unknown runtime' => [['runtime' => 'podman'], 'owner.runtime is unknown'],
    'runtime with a payload' => [['runtime' => 'desktop --privileged'], 'owner.runtime is unknown'],
    'runtime as a number' => [['runtime' => 1], 'owner.runtime is unknown'],
    'negative group' => [['socket_group' => -1], 'owner.socket_group'],
    'group as a string' => [['socket_group' => '0'], 'owner.socket_group'],
    'null group' => [['socket_group' => null], 'owner.socket_group'],
    'local time' => [['measured_at' => '2026-10-26T19:30:00+01:00'], 'owner.measured_at'],
]);

it('stays readable by 1.0 readers: a minor version, the 1.0 owner keys unchanged, unknown keys kept on rewrite (KTD5)', function (): void {
    CarbonImmutable::setTestNow('2026-10-26 19:30:00');
    $written = InstanceState::create(OWNER_UUID, '/home/u/fieldset', 1000, 1000, 1000)->withOwnerMeasurement(DockerRuntime::Desktop, 0, CarbonImmutable::now())->toArray();
    $legacy = [...$written, 'schema_version' => '1.0', 'owner' => ['host_uid' => 501, 'host_gid' => 20, 'container_uid' => 0]];

    SchemaVersion::parse($written['schema_version'], 'instance state')->assertReadableBy(SchemaVersion::of('1.0'), 'instance state');
    $rewritten = InstanceState::fromArray([...$written, 'schema_version' => '1.1', 'tunnel' => ['admin' => true]])->withSetting('port', 8081)->toArray();

    expect($written['schema_version'])->toBe('1.1')
        ->and(array_map(fn (string $key): mixed => $written['owner'][$key], ['host_uid', 'host_gid', 'container_uid']))->toBe([1000, 1000, 1000])
        ->and(collect(['host_uid', 'host_gid', 'container_uid'])->every(fn (string $key): bool => is_int($written['owner'][$key]) && $written['owner'][$key] >= 0))->toBeTrue()
        ->and($rewritten['owner'])->toBe($written['owner'])
        ->and($rewritten['tunnel'])->toBe(['admin' => true])
        ->and(InstanceState::fromArray($legacy)->measuredRuntime())->toBeNull()
        ->and(InstanceState::fromArray($legacy)->containerUid())->toBe(0);
});

it('records only a measurement of this run whose owner the state already has (KTD3, KTD6)', function (?OwnerSource $source, ?DockerRuntime $runtime, int $containerUid, bool $records): void {
    CarbonImmutable::setTestNow('2026-10-26 19:30:00');
    $state = InstanceState::create(OWNER_UUID, '/home/u/fieldset', 1000, 1000, 1000);
    $facts = new HostFacts(uid: 1000, runtime: $runtime, containerUid: $containerUid, ownerSource: $source, socketGroup: 0);

    $recorded = MeasuredOwner::record($state, $facts);

    expect($recorded->measuredRuntime())->toBe($records ? $runtime : null)
        ->and($recorded->ownerMeasuredAt())->toBe($records ? '2026-10-26T19:30:00Z' : null)
        ->and($recorded->containerUid())->toBe(1000);
})->with([
    'measured now' => [OwnerSource::Measured, DockerRuntime::Desktop, 1000, true],
    'saved' => [OwnerSource::Saved, DockerRuntime::Desktop, 1000, false],
    'by rules' => [OwnerSource::Rules, DockerRuntime::Rootful, 1000, false],
    'a launcher before 2.1.1' => [null, DockerRuntime::Rootful, 1000, false],
    'measured, another owner (repair-ownership changes it, U4)' => [OwnerSource::Measured, DockerRuntime::Desktop, 0, false],
    'measured, runtime unknown' => [OwnerSource::Measured, null, 1000, false],
]);

it('saves the owner the shim measured for the install, or none when it fell back to rules (R2, KTD5)', function (array $facts, ?array $owner): void {
    CarbonImmutable::setTestNow('2026-10-18 10:00:00');
    $fixture = new InstanceCommandFixture;
    $fixture->facts($facts);

    [$code, $json] = $fixture->run('fieldset:install', InstanceCommandFixture::installFlags());
    $saved = $fixture->state()->toArray()['owner'];

    expect($code)->toBe(0, (string) json_encode($json))
        ->and(array_intersect_key($saved, array_flip(['container_uid', 'runtime', 'socket_group', 'measured_at'])))->toBe($owner ?? ['container_uid' => posix_getuid()])
        ->and($fixture->state()->schemaVersion())->toBe('1.1');
})->with([
    'WSL 2 with Docker Desktop: pass-through' => [['runtime' => DockerRuntime::Desktop, 'ownerSource' => OwnerSource::Measured, 'socketGroup' => 0, 'wslVersion' => 2, 'wslBackend' => WslBackend::DockerDesktop],
        ['container_uid' => posix_getuid(), 'runtime' => 'desktop', 'socket_group' => 0, 'measured_at' => '2026-10-18T10:00:00Z']],
    'rootful Linux' => [['ownerSource' => OwnerSource::Measured], ['container_uid' => posix_getuid(), 'runtime' => 'rootful', 'measured_at' => '2026-10-18T10:00:00Z']],
    'the measurement could not run' => [['ownerSource' => OwnerSource::Rules], null],
]);

it('passes doctor\'s permissions check on WSL 2 with Docker Desktop once the owner is measured, where 2.1.0 expected UID 0 (AE2)', function (bool $measured, string $status): void {
    $fixture = new InstanceCommandFixture;
    $fixture->facts(['uid' => posix_getuid(), 'runtime' => DockerRuntime::Desktop, 'containerUid' => $measured ? posix_getuid() : null,
        'ownerSource' => $measured ? OwnerSource::Measured : null, 'socketGroup' => $measured ? 0 : null, 'wslVersion' => 2, 'wslBackend' => WslBackend::DockerDesktop]);
    $fixture->probes->stats[$fixture->directory->root] = ['uid' => posix_getuid(), 'mode' => 0700];

    [, $json] = $fixture->run('fieldset:doctor');
    $check = collect($json['data']['checks'])->firstWhere('key', 'permissions');

    expect($check['status'])->toBe($status)
        ->and($check['details']['owner_uid'] ?? null)->toBe($measured ? posix_getuid() : 0);
})->with(['2.1.1: measured pass-through' => [true, 'ok'], '2.1.0: the product-name rule' => [false, 'fail']]);

it('restores as the owner saved with the instance, so backups move between macOS, Linux and WSL (R29)', function (int $containerUid, string $user): void {
    $state = InstanceState::create(OWNER_UUID, '/home/u/fieldset', 1000, 1000, $containerUid)->withRelease('2.1.1', ['app' => InstanceCommandFixture::appImage()]);

    expect(RestoreOperation::runAs($state)['user'])->toBe($user);
})->with(['WSL 2 or rootful: the host user' => [1000, '1000:1000'], 'macOS or rootless: container root' => [0, '0:0']]);

it('gives the upgrade supervisor the socket\'s group, also group 0 when it does not run as root (KTD4)', function (int|false $group, int $uid, array $expected): void {
    expect(DetachedSupervisor::socketGroups($group, $uid))->toBe($expected);
})->with([
    'WSL 2 with Docker Desktop: root:root 0660 inside' => [0, 1000, ['0']],
    'macOS: ops as root' => [0, 0, []],
    'rootful Linux: the docker group' => [998, 1000, ['998']],
    'rootful as root' => [998, 0, ['998']],
    'no socket' => [false, 1000, []],
]);
