<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\DockerRuntime;
use App\Domain\Instance\Docker\OwnerSource;
use App\Domain\Instance\Docker\WslBackend;
use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\Checks\PermissionsCheck;
use App\Domain\Instance\Doctor\CheckStatus;
use App\Domain\Instance\Doctor\DoctorContext;
use App\Domain\Instance\Doctor\DoctorReport;
use App\Domain\Instance\HostFacts;
use Tests\Support\Instance\FakeDoctorProbes;
use Tests\Support\Instance\OwnerFixture;
use Tests\Support\Instance\RecordingDockerGateway;
use Tests\Support\Instance\TemporaryInstance;

/*
 * 2.1.1 U4 (R3, R5, KTD3, ADR 0078): `fieldset doctor` compares the owner saved with the instance with the one the shim
 * measured just now, says who measured it, and points a mismatch at `fieldset repair-ownership` (never at a chown that
 * cannot help). A measurement that could not run is "unknown", which counts like a warning, never a mismatch.
 */

afterEach(fn () => TemporaryInstance::cleanup());

/** @return array<string, mixed> the doctor's permissions check */
function ownerCheck(array $json): array
{
    return collect($json['data']['checks'] ?? [])->firstWhere('key', 'permissions') ?? [];
}

/** The WSL 2 facts of a Docker Desktop host whose IDs pass through, as the 2.1.1 shim measures them (AE2). */
function wslPassThrough(): array
{
    return ['socketGroup' => 0, 'wslVersion' => 2, 'wslBackend' => WslBackend::DockerDesktop];
}

it('passes an instance from 2.1.0 on macOS and says the owner was measured just now (AE1, R4)', function (): void {
    $owner = new OwnerFixture(0, DockerRuntime::Desktop);
    $owner->measure(0, DockerRuntime::Desktop);

    [$code, $json] = $owner->instance->run('fieldset:doctor');
    $check = ownerCheck($json);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($check['status'])->toBe('ok')
        ->and($check['summary'])->toContain('UID 0')->toContain('measured just now')
        ->and($check['details'])->toMatchArray(['owner_uid' => 0, 'saved_owner_uid' => 0, 'measured_owner_uid' => 0, 'owner_source' => 'measured', 'owner_mismatch' => false]);
});

it('fails a saved root owner where IDs now pass through, naming both owners and the repair (R5, broken WSL)', function (): void {
    $owner = new OwnerFixture(0, DockerRuntime::Desktop);
    $uid = posix_getuid();
    $owner->measure($uid, DockerRuntime::Desktop, overrides: wslPassThrough());

    [$code, $json] = $owner->instance->run('fieldset:doctor');
    $check = ownerCheck($json);

    expect($code)->toBe(3)
        ->and($json['errors'][0]['code'])->toBe('doctor_failed')
        ->and($check['status'])->toBe('fail')
        ->and($check['summary'])->toContain('UID 0')->toContain("UID {$uid}")->toContain('measured just now')
        ->and($check['hint'])->toContain('fieldset repair-ownership')->toContain('UID 0')->toContain("UID {$uid}")->not->toContain('chown')
        ->and($json['errors'][0]['hint'])->toBe($check['hint'])
        ->and($check['details'])->toMatchArray(['owner_uid' => $uid, 'saved_owner_uid' => 0, 'measured_owner_uid' => $uid, 'owner_source' => 'measured', 'owner_mismatch' => true])
        ->and($owner->instance->state()->measuredRuntime())->toBeNull(); // a differing owner is never recorded (KTD6)
});

it('runs with the fresh owner after rootful became rootless and reports the mismatch, not the file owners (KTD3)', function (): void {
    $owner = new OwnerFixture(posix_getuid(), DockerRuntime::Rootful);
    $owner->measure(0, DockerRuntime::Rootless);
    $owner->seeFilesAs(0); // rootless: container root is the host user who owns the files

    [$code, $json] = $owner->instance->run('fieldset:doctor');
    $check = ownerCheck($json);

    expect($code)->toBe(3)
        ->and($check['status'])->toBe('fail')
        ->and($check['summary'])->not->toContain('belongs to UID')
        ->and($check['summary'])->toContain('UID '.posix_getuid())->toContain('UID 0')
        ->and($check['hint'])->toContain('fieldset repair-ownership')->not->toContain('chown')
        ->and($check['details'])->toMatchArray(['owner_uid' => 0, 'saved_owner_uid' => posix_getuid(), 'measured_owner_uid' => 0, 'owner_mismatch' => true]);
});

it('keeps failing a group-writable file during a mismatch, with the chmod before the repair', function (): void {
    $owner = new OwnerFixture(0, DockerRuntime::Desktop);
    $owner->measure(posix_getuid(), DockerRuntime::Desktop, overrides: wslPassThrough());
    $compose = $owner->root().'/compose.yaml';
    $owner->instance->probes->stats[$compose] = ['uid' => 0, 'mode' => 0660];

    [$code, $json] = $owner->instance->run('fieldset:doctor');
    $check = ownerCheck($json);

    expect($code)->toBe(3)
        ->and($check['summary'])->toContain('compose.yaml is writable by group or others (mode 0660)')
        ->and($check['hint'])->toContain('chmod go-w '.$compose)->toContain('fieldset repair-ownership')->not->toContain('chown');
});

it('reports a measurement that could not run as unknown: a warning with exit 0, never a mismatch', function (): void {
    $owner = new OwnerFixture(posix_getuid(), DockerRuntime::Rootful);
    $owner->measure(0, DockerRuntime::Desktop, OwnerSource::Rules); // the rules' guess differs from the saved owner
    $owner->seeFilesAs(0);

    [$code, $json] = $owner->instance->run('fieldset:doctor');
    $check = ownerCheck($json);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($check['status'])->toBe('unknown')
        ->and($check['summary'])->toContain('Could not measure')->not->toContain('repair-ownership')
        ->and($check['details'])->toMatchArray(['owner_source' => 'rules', 'measured_owner_uid' => null, 'saved_owner_uid' => posix_getuid(), 'owner_mismatch' => false])
        ->and(collect($json['warnings'])->firstWhere('code', 'owner_not_measured'))->toMatchArray(['message' => $check['summary'], 'hint' => $check['hint']])
        ->and($owner->instance->state()->measuredRuntime())->toBeNull();
});

it('still fails group- or other-writable files when the owner cannot be measured', function (): void {
    $directory = TemporaryInstance::withState();
    chmod($directory->root, 0770);
    $facts = new HostFacts(uid: 501, runtime: DockerRuntime::Desktop, containerUid: 0, ownerSource: OwnerSource::Rules);
    $docker = new RecordingDockerGateway;

    $result = new PermissionsCheck()->run(new DoctorContext($facts, $directory, $docker, new FakeDoctorProbes, $directory->state()));

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->summary)->toContain('writable by group or others (mode 0770)')->not->toContain('belongs to UID')
        ->and($result->hint)->toContain('chmod go-w '.$directory->root)->not->toContain('chown');
});

it('names a chown that helps only where the owner is known and files belong to someone else', function (): void {
    $owner = new OwnerFixture(posix_getuid(), DockerRuntime::Rootful);
    $owner->measure(posix_getuid(), DockerRuntime::Rootful);
    $state = $owner->root().'/state.json';
    $owner->instance->probes->stats[$state] = ['uid' => 0, 'mode' => 0600];

    [$code, $json] = $owner->instance->run('fieldset:doctor');
    $check = ownerCheck($json);

    expect($code)->toBe(3)
        ->and($check['summary'])->toContain('state.json belongs to UID 0, not the instance owner (UID '.posix_getuid().' inside containers)')
        ->and($check['hint'])->toContain('sudo chown -h '.posix_getuid().':'.posix_getgid().' '.$state)->not->toContain('repair-ownership');
});

it('carries the status unknown per check in the envelope and counts it like a warning', function (): void {
    $report = new DoctorReport([
        CheckResult::ok('docker', 'fine'),
        CheckResult::unknown('permissions', 'Could not measure.', 'Run doctor again.', ['owner_source' => 'rules'], 'owner_not_measured'),
        CheckResult::warn('memory', 'Close to the floor.', 'Add memory.'),
    ]);

    expect(CheckStatus::Unknown->value)->toBe('unknown')
        ->and($report->passed())->toBeTrue()
        ->and(array_map(fn (CheckResult $result): string => $result->key, $report->warnings()))->toBe(['permissions', 'memory'])
        ->and($report->toArray()[1])->toBe(['key' => 'permissions', 'status' => 'unknown', 'summary' => 'Could not measure.', 'hint' => 'Run doctor again.', 'details' => ['owner_source' => 'rules']])
        ->and($report->warnings()[0]->warningCode())->toBe('owner_not_measured');
});
