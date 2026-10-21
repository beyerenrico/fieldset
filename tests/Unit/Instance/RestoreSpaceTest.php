<?php

declare(strict_types=1);

use App\Domain\Instance\Backup\BackupStore;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Restore\RestoreSpace;
use Tests\Support\Instance\BackupSetFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * The space check before the prepare step writes into the scratch directory (plan U17, ADR 0085, M12 gate): an encrypted
 * backup needs room for its decrypted tar and the extracted set, a plain one for the copy of its set (dump, storage
 * archive and the small files), which every later step reads instead of the backup directory.
 */

beforeEach(function (): void {
    $this->directory = TemporaryInstance::directory();
    $this->space = new RestoreSpace(new BackupStore($this->directory));
    $this->source = BackupSetFixture::fieldset($this->directory->root.'/backups/manual-20261019T120000Z');
    $dump = fopen($this->source.'/database.dump', 'r+');
    ftruncate($dump, 64 * 1024 ** 2); // sparse: a large dump without the disk space
    fclose($dump);
});

afterEach(fn () => TemporaryInstance::cleanup());

/** The bytes of the files in a plain backup directory. */
function setBytes(string $source): int
{
    $bytes = 0;
    foreach (scandir($source) ?: [] as $file) {
        $bytes += is_file($source.'/'.$file) ? (int) filesize($source.'/'.$file) : 0;
    }

    return $bytes;
}

it('counts the copy of a plain set, dump included, before the prepare step writes it (M12 gate)', function (): void {
    $set = setBytes($this->source);
    app()->instance(RestoreSpace::FREE_BYTES, static fn (): int => RestoreSpace::HEADROOM + $set - 1);
    $refused = null;

    try {
        $this->space->assertScratch($this->source);
    } catch (InstanceException $e) {
        $refused = $e;
    }
    app()->instance(RestoreSpace::FREE_BYTES, static fn (): int => RestoreSpace::HEADROOM + $set);

    expect($refused?->errorCode)->toBe('low_disk_space')
        ->and($refused?->data['required_bytes'] ?? null)->toBe(RestoreSpace::HEADROOM + $set)
        ->and($this->space->assertScratch($this->source))->toBe(RestoreSpace::HEADROOM + $set);
});

it('leaves out what the copy leaves behind (dotfiles, other files), and counts an encrypted backup twice: its tar and its set', function (): void {
    $set = setBytes($this->source);
    file_put_contents($this->source.'/.DS_Store', str_repeat('x', 4096));
    file_put_contents($this->source.'/notes.txt', str_repeat('x', 4096));
    $encrypted = BackupSetFixture::encrypt(BackupSetFixture::fieldset($this->directory->root.'/backups/manual-20261019T130000Z'));
    app()->instance(RestoreSpace::FREE_BYTES, static fn (): int => RestoreSpace::HEADROOM + $set);

    expect($this->space->assertScratch($this->source))->toBe(RestoreSpace::HEADROOM + $set);
    app()->instance(RestoreSpace::FREE_BYTES, static fn (): int => RestoreSpace::HEADROOM + 2 * (int) filesize($encrypted) - 1);
    expect(fn () => $this->space->assertScratch($encrypted))->toThrow(InstanceException::class, 'Not enough free disk space');
});
