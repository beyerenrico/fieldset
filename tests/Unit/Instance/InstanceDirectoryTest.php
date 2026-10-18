<?php

declare(strict_types=1);

use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\IncompatibleSchema;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InvalidState;
use App\Domain\Instance\State\NotAnInstanceDirectory;
use App\Domain\Instance\State\SafeFiles;
use App\Domain\Instance\State\SchemaVersion;
use App\Domain\Instance\State\UnsafePath;
use Tests\Support\Instance\TemporaryInstance;

afterEach(fn () => TemporaryInstance::cleanup());

it('initializes a 0700 directory with its marker, idempotently', function (): void {
    $path = TemporaryInstance::path();

    $directory = InstanceDirectory::initialize($path);
    InstanceDirectory::initialize($path);

    expect(fileperms($path) & 0777)->toBe(0700)
        ->and(InstanceDirectory::isMarked($path))->toBeTrue()
        ->and(json_decode((string) file_get_contents($path.'/.fsa-instance'), true)['schema_version'])->toBe('1.0')
        ->and(InstanceDirectory::open($path)->root)->toBe($directory->root);
});

it('refuses to open a directory without the marker', function (): void {
    $path = TemporaryInstance::path();
    mkdir($path);

    try {
        InstanceDirectory::open($path);
        $this->fail('open should fail');
    } catch (NotAnInstanceDirectory $e) {
        expect($e->exitCode)->toBe(ExitCode::InvalidInput)->and($e->hint)->toContain('--dir');
    }
    expect(fn () => InstanceDirectory::open('relative/dir'))->toThrow(NotAnInstanceDirectory::class);
});

it('refuses a marker that is a symlink', function (): void {
    $path = TemporaryInstance::path();
    mkdir($path);
    $elsewhere = TemporaryInstance::directory();
    symlink($elsewhere->path('.fsa-instance'), $path.'/.fsa-instance');

    expect(InstanceDirectory::isMarked($path))->toBeFalse();
});

it('accepts a hand-written marker but refuses one from a newer major version', function (): void {
    $path = TemporaryInstance::path();
    mkdir($path);
    touch($path.'/.fsa-instance');
    expect(InstanceDirectory::open($path)->root)->toBe($path);

    file_put_contents($path.'/.fsa-instance', '{"schema_version":"2.0"}');
    expect(fn () => InstanceDirectory::open($path))->toThrow(IncompatibleSchema::class);
});

it('writes through a temporary file with the requested mode and leaves no temporary file behind', function (): void {
    $directory = TemporaryInstance::directory();

    $directory->write('env/app.env', "A='1'\n", 0600);
    $directory->write('env/app.env', "A='2'\n", 0600);

    expect($directory->read('env/app.env'))->toBe("A='2'\n")
        ->and(fileperms($directory->path('env/app.env')) & 0777)->toBe(0600)
        ->and(fileperms($directory->path('env')) & 0777)->toBe(0700)
        ->and($directory->files()->list('env'))->toBe(['app.env']);
});

it('refuses to follow a symlinked directory out of the instance directory', function (): void {
    $directory = TemporaryInstance::directory();
    $outside = TemporaryInstance::directory();
    symlink($outside->root, $directory->root.'/backups');

    expect(fn () => $directory->write('backups/escape.txt', 'x'))->toThrow(UnsafePath::class, 'outside the instance directory')
        ->and(file_exists($outside->root.'/escape.txt'))->toBeFalse()
        ->and(fn () => $directory->read('backups/.fsa-instance'))->toThrow(UnsafePath::class)
        ->and(fn () => $directory->ensureDirectory('backups/sub'))->toThrow(UnsafePath::class);
});

it('refuses to write over or read through a symlinked file', function (): void {
    $directory = TemporaryInstance::directory();
    $outside = TemporaryInstance::directory();
    symlink($outside->path('.fsa-instance'), $directory->root.'/state.json');

    expect(fn () => $directory->write('state.json', '{}'))->toThrow(UnsafePath::class, 'symlink')
        ->and(fn () => $directory->read('state.json'))->toThrow(UnsafePath::class, 'symlink')
        ->and(file_get_contents($outside->path('.fsa-instance')))->toContain('schema_version');
});

it('rejects paths that are not plain relative paths', function (string $relative): void {
    expect(fn () => TemporaryInstance::directory()->path($relative))->toThrow(UnsafePath::class, 'not a plain relative path');
})->with(['../etc/passwd', 'env/../../x', '/etc/passwd', 'env//app.env', "a\0b", './state.json']);

it('creates directories with explicit modes, ignoring the umask', function (): void {
    $directory = TemporaryInstance::directory();
    $old = umask(0077);

    try {
        $directory->ensureDirectory('runtime', 0755);
    } finally {
        umask($old);
    }

    expect(fileperms($directory->path('runtime')) & 0777)->toBe(0755);
});

it('refuses a directory component that is a symlink, even inside the instance', function (): void {
    $directory = TemporaryInstance::directory();
    $directory->ensureDirectory('real');
    symlink($directory->root.'/real', $directory->root.'/alias');

    expect(fn () => $directory->ensureDirectory('alias/sub'))->toThrow(UnsafePath::class, 'symlink');
});

it('reports a missing state as invalid and an unreadable entry as unsafe', function (): void {
    $directory = TemporaryInstance::directory();
    $directory->ensureDirectory('state.json');

    expect(fn () => (TemporaryInstance::directory())->state())->toThrow(InvalidState::class, 'no state file')
        ->and(fn () => $directory->read('state.json'))->toThrow(UnsafePath::class, 'not a readable regular file')
        ->and(fn () => $directory->write('state.json', '{}'))->toThrow(UnsafePath::class, 'directory');
});

it('renames, lists and deletes trees inside the root without following links', function (): void {
    $directory = TemporaryInstance::directory();
    $outside = TemporaryInstance::directory();
    $directory->write('a/b/c.txt', 'x');
    symlink($outside->root, $directory->root.'/a/link');
    $files = $directory->files();

    expect($files->rename('a', 'z'))->toBeTrue()
        ->and($files->list('z'))->toBe(['b', 'link'])
        ->and($files->list('missing'))->toBe([])
        ->and($files->modifiedAt('missing'))->toBeNull();

    $files->delete('z');

    expect($files->exists('z'))->toBeFalse()
        ->and(InstanceDirectory::isMarked($outside->root))->toBeTrue();
});

it('syncs the directory after a rename where the platform allows it', function (): void {
    $directory = TemporaryInstance::directory();

    expect(SafeFiles::syncDirectory($directory->root))->toBeTrue()
        ->and(SafeFiles::syncDirectory($directory->root.'/missing'))->toBeFalse()
        ->and($directory->files()->rename('.fsa-instance', 'moved/marker'))->toBeTrue()
        ->and($directory->files()->rename('missing', 'moved/other'))->toBeFalse();
});

it('reads a schema version only as major.minor, without a trailing newline', function (string $raw): void {
    expect(fn () => SchemaVersion::parse($raw, 'instance state'))->toThrow(InvalidState::class);
})->with(["1.0\n", '1.0.0', 'v1', '']);

it('refuses a root that is not a directory', function (): void {
    expect(fn () => SafeFiles::at('/nonexistent/'.bin2hex(random_bytes(4))))->toThrow(UnsafePath::class)
        ->and(fn () => InstanceDirectory::initialize('relative'))->toThrow(UnsafePath::class, 'absolute');
});

it('builds paths on the directory as given, because Docker resolves bind mounts on the host', function (): void {
    $directory = TemporaryInstance::directory();

    expect($directory->path('compose.yaml'))->toBe($directory->root.'/compose.yaml')
        ->and($directory->path())->toBe($directory->root);
});
