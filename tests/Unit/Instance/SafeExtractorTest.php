<?php

declare(strict_types=1);

use App\Domain\Instance\Restore\SafeExtractor;
use App\Domain\Instance\Restore\UnsafeArchive;
use Symfony\Component\Process\Process;
use Tests\Support\Instance\TarBuilder;

/*
 * Safe extraction of foreign backup archives (R41, plan U17, ADR 0085): every member is judged before anything is
 * written. The set of an encrypted backup holds only the regular files of a backup set; a storage archive only files
 * and directories below its root. Absolute paths, `..`, symlinks, hard links and special files refuse the whole archive.
 */

const SET_NAMES = ['SHA256SUMS', 'SHA256SUMS.hmac', 'audit-heads.json', 'database.dump', 'manifest.json', 'settings.json', 'storage.tar.gz', 'secrets.env'];

beforeEach(function (): void {
    $this->work = sys_get_temp_dir().'/fsa-test-extract-'.bin2hex(random_bytes(4));
    mkdir($this->work.'/out', 0700, true);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->work));
});

it('extracts the regular files of a backup set into the empty scratch directory, 0600', function (): void {
    $archive = new TarBuilder()->file('database.dump', 'PGDMP'.str_repeat('x', 3000))->file('manifest.json', '{}')->writeTo($this->work.'/set.tar');

    $names = SafeExtractor::extractSet($archive, $this->work.'/out', SET_NAMES);

    expect($names)->toBe(['database.dump', 'manifest.json'])
        ->and(file_get_contents($this->work.'/out/database.dump'))->toBe('PGDMP'.str_repeat('x', 3000))
        ->and(substr(sprintf('%o', fileperms($this->work.'/out/manifest.json')), -3))->toBe('600');
});

it('reads what GNU tar writes, long names and pax headers included', function (): void {
    $long = 'private/'.str_repeat('d', 120).'/file.txt';
    $archive = new TarBuilder()->directory('./')->directory('./private/')->longFile('./'.$long, 'long')
        ->paxFile(['path' => './private/pax-name.txt'], 'short', 'pax')->writeTo($this->work.'/storage.tar.gz', gzip: true);

    $counts = SafeExtractor::checkStorage($archive, $this->work.'/members.txt');

    expect($counts)->toBe(['files' => 2, 'directories' => 2, 'bytes' => 7])
        ->and(file($this->work.'/members.txt', FILE_IGNORE_NEW_LINES))->toBe([$long, 'private/pax-name.txt']);
});

it('reads a storage archive the system tar wrote', function (): void {
    mkdir($this->work.'/src/private/a', 0755, true);
    file_put_contents($this->work.'/src/private/a/file.txt', 'data');
    $tar = new Process(['tar', '-C', $this->work.'/src', '-czf', $this->work.'/storage.tar.gz', '--', '.'], null, ['COPYFILE_DISABLE' => '1']); // macOS: no AppleDouble members
    $tar->mustRun();

    expect(SafeExtractor::checkStorage($this->work.'/storage.tar.gz', $this->work.'/members.txt')['files'])->toBe(1)
        ->and(file($this->work.'/members.txt', FILE_IGNORE_NEW_LINES))->toBe(['private/a/file.txt']);
});

it('refuses a hostile storage member before anything is listed', function (Closure $build, string $message): void {
    $archive = $build(new TarBuilder()->file('./private/ok.txt', 'ok'))->writeTo($this->work.'/storage.tar.gz', gzip: true);

    expect(fn () => SafeExtractor::checkStorage($archive, $this->work.'/members.txt'))->toThrow(UnsafeArchive::class, $message)
        ->and((string) @file_get_contents($this->work.'/members.txt'))->toBe('private/ok.txt'."\n");
})->with([
    'a ../ path' => [fn (TarBuilder $tar) => $tar->file('./../../etc/cron.d/evil', 'x'), 'leaves its root'],
    'an inner ..' => [fn (TarBuilder $tar) => $tar->file('./private/../../x', 'x'), 'leaves its root'],
    'an absolute path' => [fn (TarBuilder $tar) => $tar->file('/etc/passwd', 'x'), 'absolute'],
    'a symlink' => [fn (TarBuilder $tar) => $tar->symlink('./private/link', '/etc/shadow'), 'symlink'],
    'a hard link' => [fn (TarBuilder $tar) => $tar->hardLink('./private/hard', '/etc/shadow'), 'hard link'],
    'a character device' => [fn (TarBuilder $tar) => $tar->special('./private/dev', '3'), 'character device'],
    'a block device' => [fn (TarBuilder $tar) => $tar->special('./private/blk', '4'), 'block device'],
    'a FIFO' => [fn (TarBuilder $tar) => $tar->special('./private/fifo', '6'), 'FIFO'],
    'a pax path with ..' => [fn (TarBuilder $tar) => $tar->paxFile(['path' => '../escape'], 'innocent', 'x'), 'leaves its root'],
    'a newline in a name' => [fn (TarBuilder $tar) => $tar->file("./private/a\nb", 'x'), 'unprintable'],
]);

it('refuses a set member a backup never has, a duplicate, or a non-file, and writes nothing', function (Closure $build): void {
    $archive = $build(new TarBuilder()->file('manifest.json', '{}'))->writeTo($this->work.'/set.tar');

    expect(fn () => SafeExtractor::extractSet($archive, $this->work.'/out', SET_NAMES))->toThrow(UnsafeArchive::class)
        ->and(scandir($this->work.'/out'))->toBe(['.', '..']);
})->with([
    'an unknown name' => [fn (TarBuilder $tar) => $tar->file('evil.sh', 'x')],
    'a path' => [fn (TarBuilder $tar) => $tar->file('../database.dump', 'x')],
    'a duplicate' => [fn (TarBuilder $tar) => $tar->file('manifest.json', 'again')],
    'a symlink' => [fn (TarBuilder $tar) => $tar->symlink('database.dump', '/etc/shadow')],
    'a directory' => [fn (TarBuilder $tar) => $tar->directory('settings.json')],
]);

it('refuses a damaged archive: a wrong header checksum, a truncated member, a missing end, plain tar as gzip', function (): void {
    $good = new TarBuilder()->file('manifest.json', str_repeat('m', 2000))->bytes();
    file_put_contents($this->work.'/bad-sum.tar', substr_replace($good, 'X', 10, 1));
    file_put_contents($this->work.'/short.tar', substr($good, 0, 1000));
    file_put_contents($this->work.'/plain.tar.gz', $good);

    expect(fn () => SafeExtractor::extractSet($this->work.'/bad-sum.tar', $this->work.'/out', SET_NAMES))->toThrow(UnsafeArchive::class, 'checksum')
        ->and(fn () => SafeExtractor::extractSet($this->work.'/short.tar', $this->work.'/out', SET_NAMES))->toThrow(UnsafeArchive::class, 'ends inside')
        ->and(fn () => SafeExtractor::checkStorage($this->work.'/plain.tar.gz'))->toThrow(UnsafeArchive::class, 'not a gzip archive');
});

it('refuses to extract into a scratch directory that is not empty', function (): void {
    touch($this->work.'/out/left-over');
    $archive = new TarBuilder()->file('manifest.json', '{}')->writeTo($this->work.'/set.tar');

    expect(fn () => SafeExtractor::extractSet($archive, $this->work.'/out', SET_NAMES))->toThrow(RuntimeException::class, 'not empty');
});
