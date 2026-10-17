<?php

declare(strict_types=1);

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\State\RuntimeWriter;
use Carbon\CarbonImmutable;
use Tests\Support\Instance\ComposeFixtures;
use Tests\Support\Instance\TemporaryInstance;

/*
 * Plan U3 / KTD11: runtime/runtime.json, the only channel from ops to app, mounted read-only.
 */

afterEach(function (): void {
    TemporaryInstance::cleanup();
    CarbonImmutable::setTestNow();
});

it('writes runtime.json with directory 0755 and file 0644 even under a strict umask', function (): void {
    $directory = TemporaryInstance::directory();
    $previous = umask(0077);

    try {
        new RuntimeWriter($directory)->write(ComposeFixtures::state(path: $directory->root));
    } finally {
        umask($previous);
    }

    expect(fileperms($directory->path('runtime')) & 0777)->toBe(0755)
        ->and(fileperms($directory->path('runtime/runtime.json')) & 0777)->toBe(0644);
});

it('renders the instance URL, extra hosts, an empty tunnel and a lowered gate from the state', function (): void {
    $directory = TemporaryInstance::directory();
    $state = ComposeFixtures::state(['port' => 8081, 'extra_hosts' => 'forms.lan,192.168.1.20'], $directory->root);

    new RuntimeWriter($directory)->write($state);

    expect(json_decode((string) $directory->read('runtime/runtime.json'), true))->toBe([
        'schema_version' => '1.0',
        'instance_url' => 'http://localhost:8081',
        'local_url' => 'http://localhost:8081',
        'extra_hosts' => ['forms.lan', '192.168.1.20'],
        'tunnel' => ['hostname' => null, 'expires_at' => null],
        'gate' => ['raised' => false, 'operation' => null, 'since' => null],
    ]);
});

it('uses the URL from the state when one is set', function (): void {
    $directory = TemporaryInstance::directory();
    $state = ComposeFixtures::state(path: $directory->root)->withUrl('http://forms.lan:8080');

    expect(new RuntimeWriter($directory)->document($state))->toMatchArray(['instance_url' => 'http://forms.lan:8080', 'local_url' => 'http://localhost:8080']);
});

it('replaces the file atomically and leaves no temporary file behind', function (): void {
    $directory = TemporaryInstance::directory();
    $writer = new RuntimeWriter($directory);
    $state = ComposeFixtures::state(path: $directory->root);

    $writer->write($state);
    $before = fileinode($directory->path('runtime/runtime.json'));
    $writer->write($state->withSetting('port', 9090));

    expect(fileinode($directory->path('runtime/runtime.json')))->not->toBe($before)
        ->and(json_decode((string) $directory->read('runtime/runtime.json'), true)['instance_url'])->toBe('http://localhost:9090')
        ->and($directory->files()->list('runtime'))->toBe(['runtime.json']);
});

it('keeps a raised gate when the runtime is re-rendered, until the operation lowers it', function (): void {
    CarbonImmutable::setTestNow('2026-10-18 03:30:00');
    $directory = TemporaryInstance::directory();
    $writer = new RuntimeWriter($directory);
    $state = ComposeFixtures::state(path: $directory->root);
    $read = fn (): array => json_decode((string) $directory->read('runtime/runtime.json'), true)['gate'];

    $writer->raiseGate($state, 'upgrade');
    $writer->write($state->withSetting('port', 9090));
    $raised = $read();
    $writer->lowerGate($state);

    expect($raised)->toBe(['raised' => true, 'operation' => 'upgrade', 'since' => '2026-10-18T03:30:00Z'])
        ->and($read())->toBe(['raised' => false, 'operation' => null, 'since' => null]);
});

it('starts with a lowered gate when the existing file is unreadable JSON', function (): void {
    $directory = TemporaryInstance::directory();
    $directory->ensureDirectory('runtime', 0755);
    $directory->write('runtime/runtime.json', '{"gate":', 0644);

    new RuntimeWriter($directory)->write(ComposeFixtures::state(path: $directory->root));

    expect(json_decode((string) $directory->read('runtime/runtime.json'), true)['gate']['raised'])->toBeFalse();
});

it('refuses an operation name that is not a plain identifier', function (): void {
    $directory = TemporaryInstance::directory();

    expect(fn () => new RuntimeWriter($directory)->raiseGate(ComposeFixtures::state(path: $directory->root), "up\ngrade"))
        ->toThrow(InstanceException::class, 'operation');
});
