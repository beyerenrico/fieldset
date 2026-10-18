<?php

declare(strict_types=1);

namespace App\Domain\Instance\State;

/**
 * File access confined to one directory (KTD6, KTD25). Writes go to a temporary file that is renamed into place,
 * so a reader sees the old or the new content, never a partial file. Paths are plain relative paths; a symlink
 * as the target, or a parent that resolves outside the root, is refused.
 */
final readonly class SafeFiles
{
    private function __construct(public string $root) {}

    public static function at(string $directory): self
    {
        $real = realpath($directory);
        if ($real === false || ! is_dir($real)) {
            throw new UnsafePath($directory, 'it is not a directory');
        }

        return new self($real);
    }

    /** Absolute path of a plain relative path whose existing ancestors all resolve inside the root. */
    public function resolve(string $relative): string
    {
        if ($relative === '') {
            return $this->root;
        }
        foreach (explode('/', $relative) as $segment) {
            if (in_array($segment, ['', '.', '..'], true) || str_contains($segment, "\0")) {
                throw new UnsafePath($relative, 'it is not a plain relative path');
            }
        }
        $absolute = $this->root.'/'.$relative;
        $this->assertConfined(dirname($absolute), $absolute);

        return $absolute;
    }

    /** Missing parents are created 0700; an existing parent keeps its mode (runtime/ is 0755, KTD11). */
    public function write(string $relative, string $contents, int $mode = 0600): void
    {
        $absolute = $this->resolve($relative);
        if (str_contains($relative, '/')) {
            $this->ensureParents(dirname($relative));
        }
        self::writeAtomically($absolute, $contents, $mode);
    }

    /** Contents of a regular file, or null when it does not exist. */
    public function read(string $relative): ?string
    {
        $absolute = $this->resolve($relative);
        if (is_link($absolute)) {
            throw new UnsafePath($absolute, 'it is a symlink');
        }
        if (! file_exists($absolute)) {
            return null;
        }
        $contents = is_file($absolute) ? @file_get_contents($absolute) : false;

        return $contents === false ? throw new UnsafePath($absolute, 'it is not a readable regular file') : $contents;
    }

    /** Creates the directory (and missing parents, 0700) and sets its mode explicitly, ignoring the umask. */
    public function ensureDirectory(string $relative, int $mode = 0700): string
    {
        $absolute = $this->resolve($relative);
        $segments = explode('/', $relative);
        $path = $this->root;
        foreach ($segments as $index => $segment) {
            $path .= '/'.$segment;
            $this->createDirectory($path, $index === count($segments) - 1 ? $mode : 0700);
        }
        if ((fileperms($absolute) & 0777) !== $mode && ! @chmod($absolute, $mode)) {
            throw new UnsafePath($absolute, 'its mode cannot be set');
        }

        return $absolute;
    }

    /** Atomic `mkdir` that fails when the directory exists (the instance lock's primitive, KTD4). */
    public function createExclusive(string $relative, int $mode = 0700): bool
    {
        $absolute = $this->resolve($relative);
        if (str_contains($relative, '/')) {
            $this->ensureDirectory(dirname($relative));
        }
        if (! @mkdir($absolute, $mode)) {
            return false;
        }
        @chmod($absolute, $mode);

        return true;
    }

    public function exists(string $relative): bool
    {
        $absolute = $this->resolve($relative);

        return file_exists($absolute) || is_link($absolute);
    }

    public function modifiedAt(string $relative): ?int
    {
        $absolute = $this->resolve($relative);
        $time = file_exists($absolute) ? @filemtime($absolute) : false;

        return $time === false ? null : $time;
    }

    /** Atomic rename inside the root (lock tombstones, journal archive), synced like writeAtomically(). */
    public function rename(string $from, string $to): bool
    {
        $target = $this->resolve($to);
        if (str_contains($to, '/')) {
            $this->ensureDirectory(dirname($to));
        }
        $source = $this->resolve($from);
        if (! @rename($source, $target)) {
            return false;
        }
        self::syncDirectory(dirname($target));
        if (dirname($source) !== dirname($target)) {
            self::syncDirectory(dirname($source));
        }

        return true;
    }

    /** Removes a file or a directory tree without following symlinks. */
    public function delete(string $relative): void
    {
        self::deletePath($this->resolve($relative));
    }

    /** @return list<string> entry names, sorted */
    public function list(string $relative): array
    {
        $absolute = $this->resolve($relative);
        if (is_link($absolute) || ! is_dir($absolute)) {
            return [];
        }
        $entries = array_values(array_diff(scandir($absolute) ?: [], ['.', '..']));
        sort($entries);

        return $entries;
    }

    /**
     * Temp file in the target's directory, mode set before any byte is written, fsync, rename, then fsync of the
     * directory, so the rename itself survives a crash (journal, state, lock records).
     */
    public static function writeAtomically(string $absolute, string $contents, int $mode = 0600): void
    {
        if (is_link($absolute) || is_dir($absolute)) {
            throw new UnsafePath($absolute, is_link($absolute) ? 'it is a symlink' : 'it is a directory');
        }
        $temporary = dirname($absolute).'/.'.basename($absolute).'.tmp-'.bin2hex(random_bytes(6));
        $handle = @fopen($temporary, 'x');
        if ($handle === false) {
            throw new UnsafePath($absolute, 'a temporary file cannot be created next to it');
        }

        try {
            $written = @chmod($temporary, $mode) && fwrite($handle, $contents) === strlen($contents) && fflush($handle) && fsync($handle);
            fclose($handle);
            if (! $written || ! @rename($temporary, $absolute)) {
                throw new UnsafePath($absolute, 'it cannot be written');
            }
            self::syncDirectory(dirname($absolute));
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * fsync of a directory, which makes renames and new entries in it durable. Best effort: where a directory
     * cannot be opened as a stream or synced (some platforms and file systems), false and nothing else.
     */
    public static function syncDirectory(string $directory): bool
    {
        $handle = is_dir($directory) ? @fopen($directory, 'r') : false;
        if ($handle === false) {
            return false;
        }
        $synced = @fsync($handle);
        fclose($handle);

        return $synced;
    }

    /** Like ensureDirectory(), without touching the mode of directories that exist (symlinks still refused). */
    private function ensureParents(string $relative): void
    {
        $path = $this->root;
        foreach (explode('/', $relative) as $segment) {
            $path .= '/'.$segment;
            $this->createDirectory($path, 0700);
        }
    }

    private function createDirectory(string $path, int $mode): void
    {
        if (is_link($path)) {
            throw new UnsafePath($path, 'it is a symlink');
        }
        if (! is_dir($path)) {
            if (! @mkdir($path, $mode) && ! is_dir($path)) {
                throw new UnsafePath($path, 'it cannot be created');
            }
            @chmod($path, $mode);
        }
    }

    private function assertConfined(string $parent, string $display): void
    {
        $probe = $parent;
        while (! file_exists($probe) && ! is_link($probe) && $probe !== dirname($probe)) {
            $probe = dirname($probe);
        }
        $real = realpath($probe);
        if ($real === false || ($real !== $this->root && ! str_starts_with($real, $this->root.'/'))) {
            throw new UnsafePath($display, 'it resolves outside the instance directory');
        }
    }

    private static function deletePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            self::deletePath($path.'/'.$entry);
        }
        @rmdir($path);
    }
}
