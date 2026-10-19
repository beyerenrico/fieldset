<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * File-system chores of the upgrade (plan U18, ADR 0086) for directories fsa itself made inside the instance directory
 * (a stage, a scratch directory): removed depth-first without following symlinks.
 */
final class UpgradeFiles
{
    /** Removes a directory tree; a symlink is removed as a link, never followed. */
    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo) {
                $entry->isDir() && ! $entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
        }
        @rmdir($path);
    }
}
