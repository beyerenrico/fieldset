<?php

declare(strict_types=1);

use App\Domain\Instance\Console\FieldsetCommand;
use App\Domain\Instance\Docker\DockerRuntime;
use App\Domain\Instance\Docker\OwnerSource;
use App\Domain\Instance\Operations\InstanceLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Instance\OwnerFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * 2.1.1 U4 (R4, KTD6): the 2.1.0 upgrade path runs old code, so an existing instance records the owner the 2.1.1 shim
 * measured on first use: the first state-changing command or `doctor`, under the instance lock, only when the measured
 * owner is the saved one (container_uid changes only through `fieldset repair-ownership`). Read-only commands never write
 * state. Which commands record is the `recordsOwner()` property of each command class.
 */

afterEach(function (): void {
    TemporaryInstance::cleanup();
    CarbonImmutable::setTestNow();
});

/** Calls a command's protected `recordsOwner()`. */
function recordsOwner(FieldsetCommand $command): bool
{
    return (fn (): bool => $this->recordsOwner())->call($command);
}

it('decides per command class whether it records the owner: state-changing commands and doctor do, read-only ones never', function (): void {
    $records = collect(Artisan::all())
        ->filter(fn ($command, string $name): bool => str_starts_with($name, 'fieldset:'))
        ->map(fn ($command): ?bool => $command instanceof FieldsetCommand ? recordsOwner($command) : null) // null: an image role without the base
        ->sortKeys()->all();

    expect($records)->toBe([
        'fieldset:adopt' => true, 'fieldset:backup' => true, 'fieldset:backup-daemon' => null, 'fieldset:config' => false, // `config set` only, see below
        'fieldset:demo' => true, 'fieldset:doctor' => true, 'fieldset:install' => true, 'fieldset:login-link' => true, 'fieldset:logs' => false,
        'fieldset:repair-ownership' => true, 'fieldset:reset' => true, 'fieldset:restore' => true, 'fieldset:restore-prepare' => null,
        'fieldset:rollback' => true, 'fieldset:status' => false, 'fieldset:tunnel' => true, 'fieldset:uninstall' => false,
        'fieldset:upgrade' => true, 'fieldset:upgrade-child' => null, 'fieldset:version' => false,
    ]);
});

it('records the owner on first use of an instance from 2.1.0 on macOS, changing no file owner or mode (AE1)', function (string $command, array $parameters): void {
    CarbonImmutable::setTestNow('2026-10-26 20:00:00');
    $owner = new OwnerFixture(0, DockerRuntime::Desktop);
    $before = $owner->entries();
    $owner->measure(0, DockerRuntime::Desktop);

    [$code, $json] = $owner->instance->run($command, $parameters);
    [$doctorCode, $doctor] = $owner->instance->run('fieldset:doctor');

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($owner->instance->state()->toArray()['owner'])->toBe(['host_uid' => posix_getuid(), 'host_gid' => posix_getgid(), 'container_uid' => 0,
            'runtime' => 'desktop', 'measured_at' => '2026-10-26T20:00:00Z'])
        ->and(array_intersect_key($owner->entries(), $before))->toBe($before)
        ->and($doctorCode)->toBe(0, (string) json_encode($doctor))
        ->and(collect($doctor['data']['checks'])->firstWhere('key', 'permissions')['status'])->toBe('ok');
})->with([
    'doctor' => ['fieldset:doctor', []],
    'a backup' => ['fieldset:backup', []],
    'config set' => ['fieldset:config', ['action' => 'set', 'key' => 'backup-retention', 'value' => '9']],
]);

it('leaves a rootful Linux instance as it was, except for the recorded owner (R4)', function (): void {
    CarbonImmutable::setTestNow('2026-10-26 20:00:00');
    $owner = new OwnerFixture(posix_getuid(), DockerRuntime::Rootful);
    $before = $owner->instance->state()->toArray();
    $entries = $owner->entries();
    $owner->measure(posix_getuid(), DockerRuntime::Rootful);

    [$code, $json] = $owner->instance->run('fieldset:backup');
    $after = $owner->instance->state()->toArray();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($after['owner'])->toBe([...$before['owner'], 'runtime' => 'rootful', 'measured_at' => '2026-10-26T20:00:00Z'])
        ->and(array_diff_key($after, ['owner' => true]))->toBe(array_diff_key($before, ['owner' => true]))
        ->and(array_intersect_key($owner->entries(), $entries))->toBe($entries);
});

it('never writes state from a read-only command, even without a saved owner (KTD6)', function (string $command, array $parameters): void {
    $owner = new OwnerFixture(posix_getuid(), DockerRuntime::Rootful);
    $owner->measure(posix_getuid(), DockerRuntime::Rootful);
    $state = $owner->stateJson();
    clearstatcache();
    $modified = filemtime($owner->root().'/state.json');

    [$code, $json] = $owner->instance->run($command, $parameters);

    clearstatcache();
    expect($code)->toBe(0, (string) json_encode($json))
        ->and($owner->stateJson())->toBe($state)
        ->and(filemtime($owner->root().'/state.json'))->toBe($modified)
        ->and($owner->instance->state()->measuredRuntime())->toBeNull();
})->with([
    'status' => ['fieldset:status', []],
    'config show' => ['fieldset:config', ['action' => 'show']],
    'logs' => ['fieldset:logs', []],
]);

it('records nothing that is not this run\'s measurement of the saved owner', function (OwnerSource $source, int $measured): void {
    $owner = new OwnerFixture(posix_getuid(), DockerRuntime::Rootful);
    $owner->measure($measured, DockerRuntime::Rootful, $source);
    $state = $owner->stateJson();

    $owner->instance->run('fieldset:backup');

    expect($owner->stateJson())->toBe($state);
})->with([
    'the shim used the saved owner' => [OwnerSource::Saved, posix_getuid()],
    'the measurement could not run' => [OwnerSource::Rules, posix_getuid()],
    'another owner (only the repair changes it)' => [OwnerSource::Measured, 0],
]);

it('records nothing while another operation holds the lock or is unfinished, and never waits', function (): void {
    $owner = new OwnerFixture(posix_getuid(), DockerRuntime::Rootful);
    $owner->measure(posix_getuid(), DockerRuntime::Rootful);
    $owner->instance->docker->withRunningContainer(str_repeat('f', 64), '2026-10-26T19:00:00.000000001Z');
    $lock = new InstanceLock($owner->instance->directory, $owner->instance->docker);
    $handle = $lock->acquire(TemporaryInstance::holder('f', '2026-10-26T19:00:00.000000001Z'), 'backup');
    $state = $owner->stateJson();

    [$code] = $owner->instance->run('fieldset:doctor');

    expect($code)->toBe(0)
        ->and($owner->stateJson())->toBe($state);

    $lock->release($handle);
    $owner->instance->run('fieldset:doctor');

    expect($owner->instance->state()->measuredRuntime())->toBe(DockerRuntime::Rootful);
});

it('records nothing after a command that did not start: a refused confirmation changes nothing', function (): void {
    $owner = new OwnerFixture(posix_getuid(), DockerRuntime::Rootful);
    $owner->measure(posix_getuid(), DockerRuntime::Rootful);
    $state = $owner->stateJson();

    [$code] = $owner->instance->run('fieldset:reset'); // exit 6 without --yes and a terminal

    expect($code)->toBe(6)
        ->and($owner->stateJson())->toBe($state);
});
