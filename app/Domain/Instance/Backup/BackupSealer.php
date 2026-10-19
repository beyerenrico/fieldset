<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use RuntimeException;

/**
 * Encrypts a plain backup in place once its operation completed (R57, ADR 0084): a safety backup is written
 * unencrypted, so a rollback can restore it without the owner's identity, and is sealed to the recipients afterwards
 * when encryption is on. The set becomes `backup.tar.age` (written under a temporary name first), then the plain files
 * go. A backup that is already encrypted is left alone.
 */
final readonly class BackupSealer
{
    public function __construct(private BackupStore $store, private AgeCli $age) {}

    /**
     * @param  list<string>  $recipients
     * @return array{backup: string, encrypted: bool, changed: bool, bytes: int}
     */
    public function seal(string $name, array $recipients): array
    {
        $entry = $this->store->find($name) ?? throw new BackupFailed('backup_not_found', "There is no complete backup named {$name}.");
        $path = $this->store->path($name);
        if ($entry->encrypted) {
            return ['backup' => $name, 'encrypted' => true, 'changed' => false, 'bytes' => $entry->bytes];
        }
        if ($recipients === []) {
            throw new BackupFailed('encryption_off', 'Backup encryption is off: there is no recipient to seal the backup to.');
        }
        $files = array_values(array_filter(scandir($path) ?: [], static fn (string $file): bool => ! str_starts_with($file, '.') && $file !== BackupStore::KEEP_MARKER && is_file($path.'/'.$file)));
        $temporary = $path.'/.'.BackupStore::ENCRYPTED_FILE.'.tmp';

        try {
            $this->age->encryptDirectory($path, $files, $recipients, $temporary);
            if (! rename($temporary, $path.'/'.BackupStore::ENCRYPTED_FILE)) {
                throw new RuntimeException('Cannot rename the encrypted set into place.');
            }
        } catch (RuntimeException $e) {
            @unlink($temporary);
            throw new BackupFailed('encrypt_failed', $e->getMessage(), $e);
        }
        foreach ($files as $file) {
            @unlink($path.'/'.$file);
        }
        $this->store->own($path);

        return ['backup' => $name, 'encrypted' => true, 'changed' => true, 'bytes' => BackupStore::bytes($path)];
    }
}
