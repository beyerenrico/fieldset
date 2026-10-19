<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

/**
 * A complete backup in `backups/` as BackupStore lists it. `kept`: the operator marked it to be kept (a file named
 * `keep` inside the directory), so retention never prunes it. `encrypted`: it holds the one age file of its set.
 */
final readonly class BackupEntry
{
    public function __construct(
        public BackupName $name,
        public int $bytes,
        public bool $encrypted,
        public bool $kept = false,
    ) {}

    /** @return array{name: string, kind: string, created_at: string, bytes: int, encrypted: bool, kept: bool} */
    public function toArray(): array
    {
        return [
            'name' => $this->name->name(), 'kind' => $this->name->kind, 'created_at' => $this->name->at->toIso8601ZuluString(),
            'bytes' => $this->bytes, 'encrypted' => $this->encrypted, 'kept' => $this->kept,
        ];
    }
}
