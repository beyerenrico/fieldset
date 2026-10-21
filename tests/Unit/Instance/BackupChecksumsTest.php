<?php

declare(strict_types=1);

use App\Domain\Instance\Backup\BackupChecksums;

/*
 * `SHA256SUMS` and its HMAC as a restore reads them (R67, plan U17, ADR 0084/0085): a listed name with a `/`, `..` or a
 * control character is refused before anything is hashed (a restore copies foreign archives), and stray dotfiles that
 * a user or a killed seal leaves (`.DS_Store`, `.backup.tar.age.tmp`) are ignored and reported instead of failing the
 * set; any other unlisted file still fails it.
 */

const CHECKSUM_KEY = 'a-32-byte-hmac-key-for-the-tests';

beforeEach(function (): void {
    $this->set = sys_get_temp_dir().'/fieldset-test-sums-'.bin2hex(random_bytes(4));
    mkdir($this->set, 0700);
    file_put_contents($this->set.'/database.dump', 'PGDMP dump');
    file_put_contents($this->set.'/manifest.json', '{}');
    BackupChecksums::write($this->set, ['database.dump', 'manifest.json'], CHECKSUM_KEY);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->set));
});

/** Rewrites SHA256SUMS with one more line and a matching HMAC (an attacker who knows the key cannot escape either). */
function sumsWithLine(string $set, string $line): void
{
    $sums = file_get_contents($set.'/SHA256SUMS').$line."\n";
    file_put_contents($set.'/SHA256SUMS', $sums);
    file_put_contents($set.'/SHA256SUMS.hmac', BackupChecksums::hmac($sums, CHECKSUM_KEY)."\n");
}

it('verifies an intact set', function (): void {
    expect(BackupChecksums::verify($this->set, CHECKSUM_KEY))->toBe([])
        ->and(BackupChecksums::strays($this->set))->toBe([]);
});

it('refuses listed names with a slash, .. or control characters before hashing them', function (string $name): void {
    sumsWithLine($this->set, hash('sha256', 'x').'  '.$name);

    expect(BackupChecksums::verify($this->set, CHECKSUM_KEY))->toContain('SHA256SUMS lists an unsafe name: '.json_encode($name, JSON_UNESCAPED_SLASHES));
})->with(['../../etc/passwd', 'sub/file', '/etc/shadow', '..', '.', "evil\tname", '']);

it('refuses a sums file that is not in sha256sum format', function (): void {
    file_put_contents($this->set.'/SHA256SUMS', "not a checksum line\n");
    file_put_contents($this->set.'/SHA256SUMS.hmac', BackupChecksums::hmac("not a checksum line\n", CHECKSUM_KEY));

    expect(BackupChecksums::verify($this->set, CHECKSUM_KEY))->toContain('SHA256SUMS has a line that is not in sha256sum format');
});

it('ignores stray dotfiles and reports them, but still fails on other unlisted files', function (): void {
    file_put_contents($this->set.'/.DS_Store', 'finder');
    file_put_contents($this->set.'/.backup.tar.age.tmp', 'half a seal');

    expect(BackupChecksums::verify($this->set, CHECKSUM_KEY))->toBe([])
        ->and(BackupChecksums::strays($this->set))->toBe(['.DS_Store', '.backup.tar.age.tmp']);

    file_put_contents($this->set.'/extra.txt', 'planted');
    expect(BackupChecksums::verify($this->set, CHECKSUM_KEY))->toBe(['extra.txt is not listed in SHA256SUMS']);
});

it('reports a changed file and a wrong key', function (): void {
    file_put_contents($this->set.'/database.dump', 'PGDMP other');

    expect(BackupChecksums::verify($this->set, CHECKSUM_KEY))->toBe(['database.dump does not match its checksum'])
        ->and(BackupChecksums::verify($this->set, str_repeat('k', 32)))->toContain('the HMAC does not match (another APP_KEY, or the sums were changed)');
});

it('checks the sums without a key (an archive without an HMAC: the 1.0 format)', function (): void {
    unlink($this->set.'/SHA256SUMS.hmac');

    expect(BackupChecksums::verifySums($this->set))->toBe([])
        ->and(BackupChecksums::verify($this->set, CHECKSUM_KEY))->toBe(['SHA256SUMS or its HMAC is missing']);
});
