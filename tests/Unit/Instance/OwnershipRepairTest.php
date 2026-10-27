<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerRuntime;
use App\Domain\Instance\Docker\OwnerSource;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Docker\WslBackend;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\State\EnvFiles;
use Carbon\CarbonImmutable;
use Tests\Support\Instance\OwnerFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * 2.1.1 U4 (R5, R28, KTD12, ADR 0077): `fieldset repair-ownership` gives the instance files to the owner the shim measured
 * just now: a root container of the instance's image changes owners only (never modes, never through or of a symlink,
 * never a hard-linked file, one file system), secrets keep 0600, state.json saves the new owner last, and the backup
 * service is recreated with it. It asks first (exit 6 without a terminal and without --yes) and is a no-op when the
 * owners already match. Docker volumes are never touched.
 */

beforeEach(function (): void {
    $this->uid = posix_getuid();
    $this->gid = posix_getgid();
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
    CarbonImmutable::setTestNow();
});

/** A 2.1.0 instance saved with owner 0, now on a host whose IDs pass through (the broken WSL case). */
function brokenWsl(): OwnerFixture
{
    $owner = new OwnerFixture(0, DockerRuntime::Desktop);
    $owner->measure(posix_getuid(), DockerRuntime::Desktop, overrides: ['socketGroup' => 0, 'wslVersion' => 2, 'wslBackend' => WslBackend::DockerDesktop]);

    return $owner;
}

it('gives the files to the measured owner, keeps every mode, saves the owner last and recreates the backup service', function (): void {
    CarbonImmutable::setTestNow('2026-10-26 20:00:00');
    $owner = brokenWsl();
    $before = $owner->entries();
    $ups = count($owner->instance->docker->called('composeUp'));

    [$code, $json] = $owner->instance->run('fieldset:repair-ownership', ['--yes' => true]);
    $spec = $owner->chownSpecs()[0] ?? null;
    $backupEnv = new EnvFiles($owner->instance->directory)->read('backup');

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['changed'])->toBeTrue()
        ->and($json['data']['owner'])->toBe(['from' => 0, 'to' => $this->uid, 'group' => $this->gid, 'runtime' => 'desktop', 'source' => 'measured'])
        ->and($json['data']['files_changed'])->toBe(count($owner->chowned))->toBeGreaterThan(5)
        ->and($json['data']['backup_service'])->toBe('recreated')
        ->and($spec)->toBeInstanceOf(ContainerSpec::class)
        ->and($spec->user)->toBe('0:0')
        ->and($spec->network)->toBe('none')
        ->and($spec->volumes)->toBe([$owner->root().':'.$owner->root()])
        ->and($spec->image)->toBe($owner->instance->state()->digests()['app'])
        ->and($spec->capabilities)->toBe(ContainerSpec::OWNER_COPY)
        ->and($spec->command)->toBe([$owner->root(), '-xdev', '!', '-type', 'l', '(', '-type', 'd', '-o', '-links', '1', ')', '!', '-uid', (string) $this->uid,
            '-print', '-exec', 'chown', '-h', $this->uid.':'.$this->gid, '{}', '+'])
        ->and(array_intersect_key($owner->entries(), $before))->toBe($before) // no mode (and here no owner) changed by fieldset itself
        ->and($owner->instance->state()->toArray()['owner'])->toBe(['host_uid' => $this->uid, 'host_gid' => $this->gid, 'container_uid' => $this->uid,
            'runtime' => 'desktop', 'socket_group' => 0, 'measured_at' => '2026-10-26T20:00:00Z'])
        ->and([$backupEnv['FIELDSET_OWNER_UID'], $backupEnv['FIELDSET_OWNER_GID']])->toBe([(string) $this->uid, (string) $this->gid])
        ->and(array_slice($owner->instance->docker->called('composeUp'), $ups))->toHaveCount(1)
        ->and(array_slice($owner->instance->docker->called('composeUp'), $ups)[0]['services'])->toBe(['backup']);

    foreach (glob($owner->root().'/env/*.env') ?: [] as $file) {
        expect(fileperms($file) & 0777)->toBe(0600);
    }

    [$doctorCode, $doctor] = $owner->instance->run('fieldset:doctor');
    [$backupCode, $backup] = $owner->instance->run('fieldset:backup');

    expect($doctorCode)->toBe(0, (string) json_encode($doctor))
        ->and(collect($doctor['data']['checks'])->firstWhere('key', 'permissions')['status'])->toBe('ok')
        ->and($backupCode)->toBe(0, (string) json_encode($backup));
});

it('asks first: without --yes and without a terminal it exits 6 and changes nothing (ADR 0077)', function (): void {
    $owner = brokenWsl();
    $state = $owner->stateJson();
    $env = (string) file_get_contents($owner->root().'/env/backup.env');
    $ups = count($owner->instance->docker->called('composeUp'));

    [$code, $json] = $owner->instance->run('fieldset:repair-ownership');

    expect($code)->toBe(6)
        ->and($json['errors'][0]['code'])->toBe('confirmation_required')
        ->and($json['data']['question'])->toContain('UID 0')->toContain('UID '.$this->uid)
        ->and($json['data']['owner'])->toMatchArray(['from' => 0, 'to' => $this->uid])
        ->and($owner->chownSpecs())->toBe([])
        ->and($owner->stateJson())->toBe($state)
        ->and((string) file_get_contents($owner->root().'/env/backup.env'))->toBe($env)
        ->and($owner->instance->docker->called('composeUp'))->toHaveCount($ups);
});

it('is a no-op with a clear message when the saved and the measured owner match', function (): void {
    $owner = new OwnerFixture($this->uid, DockerRuntime::Rootful);
    $owner->measure($this->uid, DockerRuntime::Rootful);
    $ups = count($owner->instance->docker->called('composeUp'));

    [$code, $json] = $owner->instance->run('fieldset:repair-ownership');

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['changed'])->toBeFalse()
        ->and($json['data']['message'])->toContain('already belong to UID '.$this->uid)
        ->and($json['data']['owner'])->toMatchArray(['from' => $this->uid, 'to' => $this->uid])
        ->and($json['data']['files_changed'])->toBe(0)
        ->and($json['data']['backup_service'])->toBe('unchanged')
        ->and($owner->chownSpecs())->toBe([])
        ->and($owner->instance->docker->called('composeUp'))->toHaveCount($ups)
        ->and($owner->instance->state()->containerUid())->toBe($this->uid);
});

it('refuses without a measurement of this run and changes nothing', function (?OwnerSource $source): void {
    $owner = new OwnerFixture(0, DockerRuntime::Desktop);
    $owner->measure($this->uid, DockerRuntime::Desktop, $source ?? OwnerSource::Measured, $source instanceof OwnerSource ? [] : ['ownerSource' => null]);
    $state = $owner->stateJson();

    [$code, $json] = $owner->instance->run('fieldset:repair-ownership', ['--yes' => true]);

    expect($code)->toBe(3)
        ->and($json['errors'][0]['code'])->toBe('owner_not_measured')
        ->and($owner->chownSpecs())->toBe([])
        ->and($owner->stateJson())->toBe($state);
})->with(['the measurement could not run' => [OwnerSource::Rules], 'a launcher before 2.1.1' => [null], 'the saved owner' => [OwnerSource::Saved]]);

it('never follows a symlink out of the instance and leaves hard-linked files alone (KTD12)', function (): void {
    $owner = brokenWsl();
    $outside = TemporaryInstance::path();
    mkdir($outside, 0700, true);
    file_put_contents($outside.'/secret.txt', "not fieldset's\n");
    symlink($outside.'/secret.txt', $owner->root().'/backups/outside-link');
    symlink($outside, $owner->root().'/backups/outside-dir');
    link($outside.'/secret.txt', $owner->root().'/backups/hard-link');

    [$code, $json] = $owner->instance->run('fieldset:repair-ownership', ['--yes' => true]);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($owner->chowned)->not->toContain($owner->root().'/backups/outside-link')
        ->and($owner->chowned)->not->toContain($owner->root().'/backups/outside-dir')
        ->and($owner->chowned)->not->toContain($owner->root().'/backups/hard-link')
        ->and($owner->chowned)->toContain($owner->root().'/state.json')
        ->and(array_filter($owner->chowned, fn (string $path): bool => ! str_starts_with($path, $owner->root())))->toBe([])
        ->and($owner->chownSpecs()[0]->command)->toContain('-xdev')->toContain('-h');
});

it('saves nothing when the root container fails, so the repair can run again', function (): void {
    $owner = brokenWsl();
    $owner->chownOutcome = new ProcessOutcome(1, '', 'chown: /x: Operation not permitted');
    $state = $owner->stateJson();

    [$code, $json] = $owner->instance->run('fieldset:repair-ownership', ['--yes' => true]);

    expect($code)->toBe(4)
        ->and($json['errors'][0]['code'])->toBe('owner_repair_failed')
        ->and($json['errors'][0]['message'])->toContain('Operation not permitted')
        ->and($json['errors'][0]['retryable'])->toBeTrue()
        ->and($owner->stateJson())->toBe($state);
});

it('waits for no other operation: a held lock is exit 9 and changes nothing', function (): void {
    $owner = brokenWsl();
    $holder = str_repeat('f', 64);
    $owner->instance->docker->withRunningContainer($holder, '2026-10-26T19:00:00.000000001Z');
    new InstanceLock($owner->instance->directory, $owner->instance->docker)->acquire(TemporaryInstance::holder('f', '2026-10-26T19:00:00.000000001Z'), 'backup');
    $state = $owner->stateJson();

    [$code, $json] = $owner->instance->run('fieldset:repair-ownership', ['--yes' => true]);

    expect($code)->toBe(9)
        ->and($json['errors'][0]['code'])->toBe('instance_locked')
        ->and($owner->chownSpecs())->toBe([])
        ->and($owner->stateJson())->toBe($state);
});
