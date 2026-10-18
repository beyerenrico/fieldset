<?php

declare(strict_types=1);

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Runtime\RuntimeDocument;
use App\Domain\Instance\State\RuntimeWriter;
use App\Domain\Instance\State\UnsafePath;
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
        'tunnel' => ['hostname' => null, 'expires_at' => null, 'peer' => null, 'admin' => false],
        'gate' => ['raised' => false, 'operation' => null, 'since' => null, 'smoke_token' => null],
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

    $token = $writer->raiseGate($state, 'upgrade');
    $writer->write($state->withSetting('port', 9090));
    $raised = $read();
    $writer->lowerGate($state);

    expect($token)->toMatch('/^[0-9a-f]{64}$/D')
        ->and($raised)->toBe(['raised' => true, 'operation' => 'upgrade', 'since' => '2026-10-18T03:30:00Z', 'smoke_token' => $token])
        ->and($read())->toBe(['raised' => false, 'operation' => null, 'since' => null, 'smoke_token' => null]);
});

it('generates a new smoke token per operation, or stores the one the operation passes', function (): void {
    $directory = TemporaryInstance::directory();
    $writer = new RuntimeWriter($directory);
    $state = ComposeFixtures::state(path: $directory->root);

    $first = $writer->raiseGate($state, 'upgrade');
    $second = $writer->raiseGate($state, 'restore');
    $given = $writer->raiseGate($state, 'reset', str_repeat('Ab3_', 10));

    expect($second)->not->toBe($first)
        ->and($given)->toBe(str_repeat('Ab3_', 10))
        ->and(json_decode((string) $directory->read('runtime/runtime.json'), true)['gate']['smoke_token'])->toBe($given);
});

it('refuses a smoke token outside the app\'s grammar', function (string $token): void {
    $directory = TemporaryInstance::directory();

    expect(fn () => new RuntimeWriter($directory)->raiseGate(ComposeFixtures::state(path: $directory->root), 'upgrade', $token))
        ->toThrow(InstanceException::class, 'smoke token');
})->with(['short' => 'abc', 'newline' => str_repeat('a', 40)."\n", 'quote' => str_repeat('a', 40).'"']);

it('writes a document the app reads, with the raised gate and its token (U10 contract)', function (): void {
    $directory = TemporaryInstance::directory();
    $state = ComposeFixtures::state(['extra_hosts' => 'forms.lan,192.168.1.20'], $directory->root);
    $token = new RuntimeWriter($directory)->raiseGate($state, 'upgrade');

    $parsed = RuntimeDocument::parse(json_decode((string) $directory->read('runtime/runtime.json'), true));

    expect($parsed->instanceUrl)->toBe('http://localhost:8080')
        ->and($parsed->extraHosts)->toBe(['forms.lan', '192.168.1.20'])
        ->and($parsed->tunnel->hostname)->toBeNull()
        ->and($parsed->gate->raised)->toBeTrue()
        ->and($parsed->gate->smokeToken)->toBe($token);
});

it('keeps the gate raised when the existing file cannot be parsed: it may be the record of a running upgrade', function (string $content): void {
    CarbonImmutable::setTestNow('2026-10-18 03:30:00');
    $directory = TemporaryInstance::directory();
    $directory->ensureDirectory('runtime', 0755);
    $directory->write('runtime/runtime.json', $content, 0644);

    new RuntimeWriter($directory)->write(ComposeFixtures::state(path: $directory->root));

    expect(json_decode((string) $directory->read('runtime/runtime.json'), true)['gate'])
        ->toBe(['raised' => true, 'operation' => RuntimeWriter::UNKNOWN_OPERATION, 'since' => '2026-10-18T03:30:00Z', 'smoke_token' => null]);
})->with(['truncated JSON' => '{"gate":', 'no gate' => '{"schema_version":"1.0"}', 'gate without raised' => '{"gate":{"operation":"upgrade"}}', 'not an object' => '"x"']);

it('starts with a lowered gate and no tunnel when there is no runtime file yet', function (): void {
    $directory = TemporaryInstance::directory();

    new RuntimeWriter($directory)->write(ComposeFixtures::state(path: $directory->root));

    $document = json_decode((string) $directory->read('runtime/runtime.json'), true);
    expect($document['gate']['raised'])->toBeFalse()
        ->and($document['tunnel'])->toBe(['hostname' => null, 'expires_at' => null, 'peer' => null, 'admin' => false]);
});

it('refuses to overwrite a runtime file it cannot read', function (): void {
    if (posix_getuid() === 0) {
        $this->markTestSkipped('root reads any file.');
    }
    $directory = TemporaryInstance::directory();
    $directory->ensureDirectory('runtime', 0755);
    $directory->write('runtime/runtime.json', '{"gate":{"raised":true}}', 0644);
    chmod($directory->path('runtime/runtime.json'), 0000);

    try {
        expect(fn () => new RuntimeWriter($directory)->write(ComposeFixtures::state(path: $directory->root)))->toThrow(UnsafePath::class);
    } finally {
        chmod($directory->path('runtime/runtime.json'), 0644);
    }
});

it('keeps the tunnel of the current file on a re-render, a raised and a lowered gate', function (): void {
    $directory = TemporaryInstance::directory();
    $writer = new RuntimeWriter($directory);
    $state = ComposeFixtures::state(path: $directory->root);
    $tunnel = ['hostname' => 'quiet-river-1234.trycloudflare.com', 'expires_at' => '2026-10-18T20:00:00Z', 'peer' => '10.213.0.20', 'admin' => true];
    $directory->ensureDirectory('runtime', 0755);
    $directory->write('runtime/runtime.json', json_encode([...$writer->document($state), 'tunnel' => $tunnel]), 0644);
    $read = fn (): array => json_decode((string) $directory->read('runtime/runtime.json'), true)['tunnel'];

    $writer->write($state->withSetting('port', 9090));
    $rendered = $read();
    $writer->raiseGate($state, 'upgrade');
    $raised = $read();
    $writer->lowerGate($state);

    expect($rendered)->toBe($tunnel)->and($raised)->toBe($tunnel)->and($read())->toBe($tunnel);
});

it('refuses an operation name that is not a plain identifier', function (): void {
    $directory = TemporaryInstance::directory();

    expect(fn () => new RuntimeWriter($directory)->raiseGate(ComposeFixtures::state(path: $directory->root), "up\ngrade"))
        ->toThrow(InstanceException::class, 'operation');
});
