<?php

declare(strict_types=1);

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\IncompatibleSchema;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\InvalidState;
use Carbon\CarbonImmutable;
use Tests\Support\Instance\TemporaryInstance;

const STATE_UUID = '3f1c2b9e-8a4d-4c1e-9b7a-2d5e6f708192';

function digest(string $repo, string $hex = '1'): string
{
    return $repo.'@sha256:'.str_repeat($hex, 64);
}

afterEach(function (): void {
    TemporaryInstance::cleanup();
    CarbonImmutable::setTestNow();
});

it('creates a versioned state with slug, project name, owner and mode', function (): void {
    $state = InstanceState::create(STATE_UUID, '/srv/My Instance.v2', 1000, 1000, 0);

    expect($state->schemaVersion())->toBe('1.0')
        ->and($state->uuid())->toBe(STATE_UUID)
        ->and($state->slug())->toBe('my-instance-v2')
        ->and($state->project())->toMatch('/^fieldset-my-instance-v2-[0-9a-f]{4}$/')
        ->and($state->path())->toBe('/srv/My Instance.v2')
        ->and($state->hostUid())->toBe(1000)
        ->and($state->hostGid())->toBe(1000)
        ->and($state->containerUid())->toBe(0)
        ->and($state->mode())->toBe('local')
        ->and($state->url())->toBeNull()
        ->and($state->version())->toBeNull()
        ->and($state->digests())->toBe([])
        ->and($state->previous())->toBeNull()
        ->and($state->recipients())->toBe([])
        ->and($state->history())->toBe([]);
});

it('derives a valid project name from awkward directory names', function (string $path, string $slug): void {
    $state = InstanceState::create(STATE_UUID, $path, 0, 0, 0);

    expect($state->slug())->toBe($slug)
        ->and($state->project())->toMatch('/^[a-z0-9][a-z0-9_-]*$/');
})->with([
    ['/opt/fieldset', 'fieldset'],
    ['/home/a/...', 'instance'],
    ['/x/'.str_repeat('Ab.', 30), substr(str_repeat('ab-', 14), 0, 40)],
]);

it('gives two instances with the same directory name different project names', function (): void {
    $a = InstanceState::create(STATE_UUID, '/a/app', 0, 0, 0);
    $b = InstanceState::create('9a8b7c6d-1e2f-4a3b-8c4d-5e6f7a8b9c0d', '/b/app', 0, 0, 0);

    expect($a->project())->not->toBe($b->project());
});

it('round-trips through JSON and changes only through immutable withers', function (): void {
    CarbonImmutable::setTestNow('2026-10-17 12:00:00');
    $state = InstanceState::create(STATE_UUID, '/srv/fieldset', 1000, 1000, 1000);

    $next = $state->withMode('domain')
        ->withUrl('https://forms.example.com')
        ->withRelease('2.0.0', ['app' => digest('ghcr.io/x/app')])
        ->withPreviousRelease('2.0.0-rc.1', ['app' => digest('ghcr.io/x/app', '2')], "services: {}\n")
        ->withRecipients(['age1'.str_repeat('q', 58), 'age1'.str_repeat('q', 58)])
        ->withSetting('port', 8080)
        ->withHistoryEntry('installed', ['version' => '2.0.0']);
    $reloaded = InstanceState::fromJson($next->toJson());

    expect($state->mode())->toBe('local')
        ->and($reloaded->mode())->toBe('domain')
        ->and($reloaded->url())->toBe('https://forms.example.com')
        ->and($reloaded->version())->toBe('2.0.0')
        ->and($reloaded->digests())->toBe(['app' => digest('ghcr.io/x/app')])
        ->and($reloaded->previous())->toBe(['version' => '2.0.0-rc.1', 'digests' => ['app' => digest('ghcr.io/x/app', '2')], 'compose' => "services: {}\n"])
        ->and($reloaded->recipients())->toHaveCount(1)
        ->and($reloaded->setting('port'))->toBe(8080)
        ->and($reloaded->setting('missing', 'default'))->toBe('default')
        ->and($reloaded->history())->toBe([['at' => '2026-10-17T12:00:00Z', 'event' => 'installed', 'version' => '2.0.0']])
        ->and($reloaded->withoutPreviousRelease()->previous())->toBeNull();
});

it('preserves keys from a newer minor version and keeps its schema version', function (): void {
    $document = [...InstanceState::create(STATE_UUID, '/srv/fieldset', 0, 0, 0)->toArray(), 'schema_version' => '1.3', 'tunnel' => ['admin' => true]];

    $state = InstanceState::fromArray($document)->withUrl('http://localhost:8080');

    expect($state->toArray()['tunnel'])->toBe(['admin' => true])
        ->and($state->schemaVersion())->toBe('1.3');
});

it('refuses a state from a newer major version (exit 7) and never rewrites it', function (): void {
    $directory = TemporaryInstance::withState();
    $newer = json_encode([...$directory->state()->toArray(), 'schema_version' => '2.0']);
    file_put_contents($directory->path('state.json'), $newer);

    try {
        $directory->state();
        $this->fail('reading should fail');
    } catch (IncompatibleSchema $e) {
        expect($e->exitCode)->toBe(ExitCode::Incompatible)->and($e->data['found'])->toBe('2.0');
    }
    expect(fn () => $directory->saveState(InstanceState::create(STATE_UUID, $directory->root, 0, 0, 0)))->toThrow(IncompatibleSchema::class)
        ->and(file_get_contents($directory->path('state.json')))->toBe($newer);
});

it('accepts an integer schema version as major.0', function (): void {
    $document = [...InstanceState::create(STATE_UUID, '/srv/fieldset', 0, 0, 0)->toArray(), 'schema_version' => 1];

    expect(InstanceState::fromArray($document)->schemaVersion())->toBe('1.0');
});

it('rejects invalid documents', function (array $override, string $problem): void {
    $document = [...InstanceState::create(STATE_UUID, '/srv/fieldset', 0, 0, 0)->toArray(), ...$override];

    expect(fn () => InstanceState::fromArray($document))->toThrow(InvalidState::class, $problem);
})->with([
    'schema' => [['schema_version' => 'one'], 'schema_version'],
    'uuid' => [['uuid' => 'not-a-uuid'], 'uuid'],
    'relative path' => [['path' => 'srv/fieldset'], 'path'],
    'mode' => [['mode' => 'cluster'], 'mode'],
    'owner' => [['owner' => ['host_uid' => -1, 'host_gid' => 0, 'container_uid' => 0]], 'owner.host_uid'],
    'url credentials' => [['url' => 'https://user:pw@example.com'], 'url'],
    'url scheme' => [['url' => 'ftp://example.com'], 'url'],
    'url quote' => [['url' => 'https://example.com/"x'], 'url'],
    'tag instead of digest' => [['digests' => ['app' => 'ghcr.io/x/app:2.0.0']], 'digests.app'],
    'digests list' => [['digests' => ['ghcr.io/x/app@sha256:'.str_repeat('1', 64)]], 'digests'],
    'previous' => [['previous' => ['version' => '1']], 'previous'],
    'recipient' => [['recipients' => ['ssh-ed25519 AAA']], 'recipients'],
    'settings list' => [['settings' => ['a']], 'settings'],
    'history object' => [['history' => ['a' => 1]], 'history'],
    'version type' => [['version' => 2], 'version'],
    'project' => [['project' => 'Bad Name'], 'project'],
]);

it('refuses secret-looking settings: secrets live only in env files', function (string $key): void {
    expect(fn () => InstanceState::create(STATE_UUID, '/srv/fieldset', 0, 0, 0)->withSetting($key, 'x'))
        ->toThrow(InstanceException::class, 'secrets live only in the service env files');
})->with(['smtp_password', 'APP_KEY', 'backup_passphrase', 'api_token']);

it('rejects malformed JSON', function (): void {
    expect(fn () => InstanceState::fromJson('{"schema_version":'))->toThrow(InvalidState::class, 'not valid JSON')
        ->and(fn () => InstanceState::fromJson('[1,2]'))->toThrow(InvalidState::class, 'not a JSON object');
});
