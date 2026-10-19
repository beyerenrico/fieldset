<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use App\Domain\Instance\State\InstanceDirectory;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * The backups directory of an instance (§6.3, KTD15, ADR 0084): complete backups, the ones being written
 * (`<name>.partial`), and the run ledger (BackupRunLog). The backup service runs as container root and gives everything
 * it writes to the instance owner (in-container owner UID, KTD2): directories 0700, files 0600 (R57), so the owner, and
 * the ops container running as the owner, can read, move and delete them.
 */
final readonly class BackupStore
{
    /** A file of this name inside a backup marks it kept: retention never prunes it. */
    public const string KEEP_MARKER = 'keep';

    /** The one age file of an encrypted backup (KTD16). */
    public const string ENCRYPTED_FILE = 'backup.tar.age';

    /** Prefix of a backup being removed (BackupStore::delete): hidden, never listed. */
    public const string TOMBSTONE = '.removing-';

    /** The instance's identity and portable settings, rendered by fsa (PortableSettings::document). */
    public const string META_FILE = '.instance.json';

    private const int NAME_ATTEMPTS = 10;

    public function __construct(
        private InstanceDirectory $directory,
        private ?int $ownerUid = null,
        private ?int $ownerGid = null,
    ) {}

    public function root(): string
    {
        return $this->directory->path(InstanceDirectory::BACKUPS_DIR);
    }

    public function path(string $name): string
    {
        return $this->directory->path(InstanceDirectory::BACKUPS_DIR.'/'.$name);
    }

    /** @return list<BackupEntry> complete backups, newest first */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->names() as $entry) {
            $name = BackupName::parse($entry);
            $path = $this->path($entry);
            if ($name instanceof BackupName && is_dir($path) && ! is_link($path)) {
                $entries[] = new BackupEntry($name, self::bytes($path), is_file($path.'/'.self::ENCRYPTED_FILE), is_file($path.'/'.self::KEEP_MARKER));
            }
        }
        usort($entries, static fn (BackupEntry $a, BackupEntry $b): int => $b->name->at <=> $a->name->at);

        return $entries;
    }

    /** @return array<string, mixed> `.instance.json` as fsa rendered it last; empty when it is missing or unreadable */
    public function meta(): array
    {
        $json = $this->directory->exists(InstanceDirectory::BACKUPS_DIR.'/'.self::META_FILE) ? $this->directory->read(InstanceDirectory::BACKUPS_DIR.'/'.self::META_FILE) : null;
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    public function find(string $name): ?BackupEntry
    {
        return array_find($this->entries(), static fn (BackupEntry $entry): bool => $entry->name->name() === $name);
    }

    /**
     * Creates `<name>.partial` (0700) for a new backup; a name already taken moves on by a second.
     *
     * @return array{0: BackupName, 1: string} the name and the partial directory's absolute path
     */
    public function begin(BackupName $name): array
    {
        $this->directory->ensureDirectory(InstanceDirectory::BACKUPS_DIR);
        $this->own($this->root(), recursive: false);
        for ($attempt = 0; $attempt < self::NAME_ATTEMPTS; $attempt++) {
            $taken = $this->directory->exists(InstanceDirectory::BACKUPS_DIR.'/'.$name->name());
            if (! $taken && $this->directory->files()->createExclusive(InstanceDirectory::BACKUPS_DIR.'/'.$name->partial())) {
                $this->own($this->path($name->partial()), recursive: false);

                return [$name, $this->path($name->partial())];
            }
            $name = $name->next();
        }

        throw new RuntimeException('No free backup name in backups/.');
    }

    /** Gives the set to the owner (0700/0600) and renames `<name>.partial` to `<name>`. */
    public function complete(BackupName $name): string
    {
        $this->own($this->path($name->partial()));
        if (! $this->directory->files()->rename(InstanceDirectory::BACKUPS_DIR.'/'.$name->partial(), InstanceDirectory::BACKUPS_DIR.'/'.$name->name())) {
            throw new RuntimeException("Cannot rename {$name->partial()} to {$name->name()}.");
        }

        return $this->path($name->name());
    }

    public function discard(BackupName $name): void
    {
        $this->directory->files()->delete(InstanceDirectory::BACKUPS_DIR.'/'.$name->partial());
    }

    /**
     * Removes what an interrupted run left behind (`*.partial`, half-removed tombstones). Called only under the instance
     * lock, so no other run is writing one.
     *
     * @return list<string> the removed names
     */
    public function cleanPartials(): array
    {
        $removed = [];
        foreach ([...$this->partials(), ...$this->tombstones()] as $entry) {
            $this->directory->files()->delete(InstanceDirectory::BACKUPS_DIR.'/'.$entry);
            $removed[] = $entry;
        }

        return $removed;
    }

    /** @return list<string> `<name>.partial` directories: a backup being written, or one an interrupted run left */
    public function partials(): array
    {
        return array_values(array_filter($this->names(), static fn (string $entry): bool => str_ends_with($entry, BackupName::PARTIAL)
            && BackupName::parse(substr($entry, 0, -strlen(BackupName::PARTIAL))) instanceof BackupName));
    }

    /**
     * Removes a backup: first renamed to a hidden tombstone (never listed, removed by the next clean-up), then deleted, so an
     * interrupted removal never leaves a half-deleted backup that still lists as complete.
     *
     * @return bool whether it is gone completely
     */
    public function delete(BackupEntry $entry): bool
    {
        $tombstone = self::TOMBSTONE.$entry->name->name().'-'.bin2hex(random_bytes(4));
        if (! $this->directory->files()->rename(InstanceDirectory::BACKUPS_DIR.'/'.$entry->name->name(), InstanceDirectory::BACKUPS_DIR.'/'.$tombstone)) {
            return false;
        }
        $this->directory->files()->delete(InstanceDirectory::BACKUPS_DIR.'/'.$tombstone);

        return ! $this->directory->exists(InstanceDirectory::BACKUPS_DIR.'/'.$tombstone);
    }

    /** @return list<string> */
    private function tombstones(): array
    {
        return array_values(array_filter($this->names(), static fn (string $entry): bool => str_starts_with($entry, self::TOMBSTONE)));
    }

    public function freeBytes(): ?int
    {
        $free = @disk_free_space(is_dir($this->root()) ? $this->root() : $this->directory->root);

        return $free === false ? null : (int) $free;
    }

    /** Owner UID:GID and modes for a path (and below): 0700 directories, 0600 files. */
    public function own(string $path, bool $recursive = true): void
    {
        $this->ownOne($path);
        if (! $recursive || ! is_dir($path) || is_link($path)) {
            return;
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            if ($item instanceof SplFileInfo && ! $item->isLink()) {
                $this->ownOne($item->getPathname());
            }
        }
    }

    /** Bytes of a file or a directory tree. */
    public static function bytes(string $path): int
    {
        if (is_file($path)) {
            return (int) filesize($path);
        }
        $total = 0;
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            if ($item instanceof SplFileInfo && $item->isFile() && ! $item->isLink()) {
                $total += $item->getSize();
            }
        }

        return $total;
    }

    private function ownOne(string $path): void
    {
        @chmod($path, is_dir($path) ? 0700 : 0600);
        if ($this->ownerUid === null || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return; // only the service's container root changes owners; elsewhere the writer is the owner already
        }
        @lchown($path, $this->ownerUid);
        @lchgrp($path, $this->ownerGid ?? $this->ownerUid);
    }

    /** @return list<string> */
    private function names(): array
    {
        return $this->directory->exists(InstanceDirectory::BACKUPS_DIR) ? $this->directory->files()->list(InstanceDirectory::BACKUPS_DIR) : [];
    }
}
