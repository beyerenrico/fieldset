<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use SensitiveParameter;

/**
 * A backup set being written (M12 gate S1, ADR 0084): the directory, as it was when the run created it (device, inode,
 * owner, 0700), and every file the writer put into it, with the hash it produced (ProducedFile). `SHA256SUMS` is written
 * from these hashes alone; `assertIntact()` refuses the run when the directory was swapped, holds an entry the writer did
 * not create, misses one, or a path no longer names the file the writer produced or its content changed. Someone who can
 * write into `backups/` (a two-way sync, say) can therefore break a run, never get a file of theirs authenticated.
 */
final class PartialSet
{
    /** @var array<string, ProducedFile> */
    private array $files = [];

    /** @var array<string, string> name => target, for the secrets.env link into the tmpfs */
    private array $links = [];

    /** @var array{dev: int, ino: int} */
    private readonly array $identity;

    public function __construct(public readonly string $path)
    {
        $this->identity = $this->identity($path) ?? throw new BackupFailed('set_changed', 'The partial backup directory is not a private directory of this run.');
    }

    public function record(string $name, ProducedFile $file): void
    {
        $this->files[$name] = $file;
    }

    public function write(string $name, #[SensitiveParameter] string $content): ProducedFile
    {
        return $this->files[$name] = ProducedFile::written($this->path.'/'.$name, $content);
    }

    /** Links `$name` to `$target` (secrets.env on the tmpfs), whose content the writer holds. */
    public function link(string $name, string $target, #[SensitiveParameter] string $content): void
    {
        if (! @symlink($target, $this->path.'/'.$name)) {
            throw new BackupFailed('write_failed', "Cannot link {$name} into the set.");
        }
        $this->links[$name] = $target;
        $this->files[$name] = new ProducedFile(hash('sha256', $content), strlen($content));
    }

    public function has(string $name): bool
    {
        return isset($this->files[$name]);
    }

    /** @return array<string, string> name => SHA-256 of every recorded file */
    public function hashes(): array
    {
        return array_map(static fn (ProducedFile $file): string => $file->sha256, $this->files);
    }

    /**
     * @param  list<string>  $except
     * @return array<string, array{bytes: int, sha256: string}>
     */
    public function described(array $except = []): array
    {
        $described = array_map(static fn (ProducedFile $file): array => $file->describe(), array_diff_key($this->files, array_flip($except)));
        ksort($described);

        return $described;
    }

    /** The directory is still the one the run created: same device and inode, not a link, this process's, 0700. */
    public function assertDirectory(): void
    {
        if ($this->identity($this->path) !== $this->identity) {
            throw new BackupFailed('set_changed', 'The partial backup directory was replaced while the backup ran.');
        }
    }

    /**
     * Exactly the recorded files are there, each still the file the writer produced (device, inode, size and SHA-256 read
     * through a fresh descriptor; a link still points at its target with the recorded content). `$deep` false skips the
     * hashing (a check right after a step that only added files).
     */
    public function assertIntact(bool $deep = true): void
    {
        $this->assertDirectory();
        $present = array_values(array_diff(scandir($this->path) ?: [], ['.', '..']));
        $expected = array_keys($this->files);
        sort($present);
        sort($expected);
        if ($present !== $expected) {
            $extra = array_diff($present, $expected);
            $missing = array_diff($expected, $present);
            throw new BackupFailed('set_changed', 'The partial backup changed while it was written: '.implode('; ', array_filter([
                $extra === [] ? '' : 'not written by the backup: '.$this->names($extra), $missing === [] ? '' : 'missing: '.$this->names($missing),
            ])).'.');
        }
        foreach ($this->files as $name => $file) {
            if (! (isset($this->links[$name]) ? $this->linkIntact($name, $file) : $this->fileIntact($name, $file, $deep))) {
                throw new BackupFailed('set_changed', "{$name} is no longer the file the backup wrote.");
            }
        }
    }

    private function fileIntact(string $name, ProducedFile $file, bool $deep): bool
    {
        $path = $this->path.'/'.$name;
        $handle = is_link($path) || ! is_file($path) ? false : @fopen($path, 'r');
        if ($handle === false) {
            return false;
        }

        try {
            $stat = fstat($handle);
            if ($stat === false || [(int) $stat['dev'], (int) $stat['ino'], (int) $stat['size']] !== [$file->device, $file->inode, $file->bytes]) {
                return false;
            }
            if (! $deep) {
                return true;
            }
            $context = hash_init('sha256');
            hash_update_stream($context, $handle);

            return hash_equals($file->sha256, hash_final($context));
        } finally {
            fclose($handle);
        }
    }

    private function linkIntact(string $name, ProducedFile $file): bool
    {
        $path = $this->path.'/'.$name;
        $target = $this->links[$name];

        return is_link($path) && readlink($path) === $target && is_file($target) && hash_equals($file->sha256, (string) hash_file('sha256', $target));
    }

    /** @return array{dev: int, ino: int}|null */
    private function identity(string $path): ?array
    {
        $stat = is_link($path) || ! is_dir($path) ? false : @stat($path);
        if ($stat === false || ($stat['mode'] & 0777) !== 0700) {
            return null;
        }
        $euid = function_exists('posix_geteuid') ? posix_geteuid() : null;

        return $euid === null || (int) $stat['uid'] === $euid ? ['dev' => (int) $stat['dev'], 'ino' => (int) $stat['ino']] : null;
    }

    /** @param  array<int|string, string>  $names */
    private function names(array $names): string
    {
        return implode(', ', array_map(static fn (string $name): string => (string) json_encode($name, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), array_values($names)));
    }
}
