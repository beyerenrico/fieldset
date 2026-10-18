<?php

declare(strict_types=1);

use App\Domain\Instance\Runtime\RuntimeSource;
use App\Domain\Instance\Runtime\RuntimeStateReader;
use Illuminate\Support\Facades\Log;
use Tests\Support\Instance\RuntimeFixture;

/*
 * Plan U10 / KTD11: runtime.json is read per request and per job, cached in process on path, inode, size and
 * mtime; an unusable file keeps the last good value, else APP_URL with the gate raised; reading never throws.
 */

beforeEach(function (): void {
    config(['app.url' => 'https://fallback.example']);
});

afterEach(function (): void {
    RuntimeFixture::cleanup();
});

function runtimeReader(): RuntimeStateReader
{
    return app(RuntimeStateReader::class);
}

it('has no state without a configured runtime path', function (): void {
    config(['instance.runtime_path' => null]);

    expect(runtimeReader()->reading()->source)->toBe(RuntimeSource::NotConfigured)
        ->and(runtimeReader()->current())->toBeNull();

    config(['instance.runtime_path' => '  ']);
    expect(runtimeReader()->current())->toBeNull();
});

it('is a process-wide singleton', function (): void {
    expect(app(RuntimeStateReader::class))->toBe(app(RuntimeStateReader::class));
});

it('reads the file and caches it on inode, size and mtime', function (): void {
    $directory = RuntimeFixture::install(RuntimeFixture::domain('one.example'));
    $path = $directory.'/runtime.json';
    expect(runtimeReader()->current()?->instanceUrl)->toBe('https://one.example');

    // Same inode, size and mtime: the cached value stays, without reading the changed bytes.
    $mtime = (int) filemtime($path);
    file_put_contents($path, str_replace('one.example', 'two.example', (string) file_get_contents($path)));
    touch($path, $mtime);
    expect(runtimeReader()->current()?->instanceUrl)->toBe('https://one.example');

    // A different size is read again.
    file_put_contents($path, str_replace('two.example', 'three.example', (string) file_get_contents($path)));
    touch($path, $mtime);
    expect(runtimeReader()->current()?->instanceUrl)->toBe('https://three.example');

    // An atomic rename with the same size and mtime still has a new inode.
    $temporary = $directory.'/next.json';
    file_put_contents($temporary, str_replace('three.example', 'tree0.example', (string) file_get_contents($path)));
    touch($temporary, $mtime);
    rename($temporary, $path);
    expect(runtimeReader()->current()?->instanceUrl)->toBe('https://tree0.example')
        ->and(runtimeReader()->reading()->source)->toBe(RuntimeSource::File);
});

it('falls back to APP_URL with the gate raised when a fresh process cannot use the file', function (callable $break): void {
    $directory = RuntimeFixture::directory();
    $break($directory);

    $reading = runtimeReader()->reading();

    expect($reading->source)->toBe(RuntimeSource::Fallback)
        ->and($reading->problem)->toBeString()->not->toBe('')
        ->and($reading->state?->instanceUrl)->toBe('https://fallback.example')
        ->and($reading->state?->gate->raised)->toBeTrue();
})->with([
    'missing' => fn (): Closure => static function (string $directory): void {},
    'a directory in its place' => fn (): Closure => static function (string $directory): void {
        mkdir($directory.'/runtime.json');
    },
    'invalid JSON' => fn (): Closure => static fn (string $directory) => RuntimeFixture::write($directory, '{"gate":'),
    'larger than 64 KiB' => fn (): Closure => static fn (string $directory) => RuntimeFixture::write($directory, str_repeat(' ', RuntimeStateReader::MAX_BYTES + 1).'{}'),
    'broken grammar' => fn (): Closure => static fn (string $directory) => RuntimeFixture::write($directory, RuntimeFixture::document(['extra_hosts' => ['*.example']])),
]);

it('keeps the last good value when the file breaks, and recovers when it is fixed', function (): void {
    $directory = RuntimeFixture::install(RuntimeFixture::domain('good.example'));
    expect(runtimeReader()->current()?->instanceUrl)->toBe('https://good.example');

    RuntimeFixture::write($directory, 'not json');
    $broken = runtimeReader()->reading();
    unlink($directory.'/runtime.json');
    $missing = runtimeReader()->reading();
    RuntimeFixture::write($directory, RuntimeFixture::document(RuntimeFixture::domain('fixed.example')));

    expect($broken->source)->toBe(RuntimeSource::LastGood)
        ->and($broken->problem)->toBe('runtime.json is not valid JSON')
        ->and($broken->state?->instanceUrl)->toBe('https://good.example')
        ->and($broken->state?->gate->raised)->toBeFalse()
        ->and($missing->source)->toBe(RuntimeSource::LastGood)
        ->and($missing->problem)->toBe('runtime.json is missing or not accessible')
        ->and(runtimeReader()->reading()->source)->toBe(RuntimeSource::File)
        ->and(runtimeReader()->current()?->instanceUrl)->toBe('https://fixed.example');
});

it('logs each new problem once', function (): void {
    Log::spy();
    $directory = RuntimeFixture::directory();

    runtimeReader()->reading();
    runtimeReader()->reading();
    RuntimeFixture::write($directory, '[1]');
    runtimeReader()->reading();
    runtimeReader()->reading();

    Log::shouldHaveReceived('warning')->twice();
    Log::shouldHaveReceived('warning')->with('Instance runtime file unusable; using APP_URL with the operation gate raised.', [
        'path' => $directory.'/runtime.json', 'problem' => 'runtime.json is not a JSON object',
    ])->once();
});

it('never throws when the log channel fails', function (): void {
    Log::shouldReceive('warning')->andThrow(new RuntimeException('log down'));
    RuntimeFixture::directory();

    expect(runtimeReader()->reading()->source)->toBe(RuntimeSource::Fallback);
});
