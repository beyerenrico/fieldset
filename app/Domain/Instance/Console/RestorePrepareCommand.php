<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Console\Support\JsonResult;
use App\Domain\Instance\Backup\BackupChecksums;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Restore\BackupPreparer;
use App\Domain\Instance\Restore\MigrationSet;
use App\Domain\Instance\Restore\UnsafeArchive;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Hidden;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

/**
 * `fieldset restore-prepare` (an image role, hidden from users; plan U17, ADR 0085): BackupPreparer in a one-off of the app
 * image that fieldset starts without the Docker socket and with `--network none`, the backup mounted read-only and only the
 * scratch directory writable. Stdin carries one JSON document: the identity's passphrase (encrypted backups) and the
 * HMAC keys fieldset derived from the APP keys outside the archive, with their fingerprints. Stdout carries one JSON envelope
 * (PreparedBackup), never a secret.
 */
#[Description('Decrypt, check and inspect a backup for a restore (a fieldset restore role; use fieldset restore)')]
#[Signature('fieldset:restore-prepare
        {--source= : The backup: a backup directory, its backup.tar.age, or a 1.0 backup directory}
        {--scratch= : An empty scratch directory for the decrypted set and the listings}
        {--identity-file= : The passphrase-protected backup identity (encrypted backups)}
        {--json : Print one JSON document on stdout}')]
#[Hidden]
final class RestorePrepareCommand extends Command
{
    private const string COMMAND = 'restore-prepare';

    /** Container key of a closure that returns stdin (tests). */
    public const string STDIN = 'fieldset.restore-prepare.stdin';

    public function handle(BackupPreparer $preparer): int
    {
        umask(0077);

        try {
            $input = $this->stdinDocument();
            $prepared = $preparer->prepare(
                $this->path('source'), $this->path('scratch'), $input['keys'], MigrationSet::image(database_path('migrations')),
                is_string($this->option('identity-file')) ? $this->option('identity-file') : null, $input['passphrase'],
            );
        } catch (UnsafeArchive $e) {
            return JsonResult::failed(self::COMMAND, 'backup_unsafe', $e->getMessage())->emit($this);
        } catch (InstanceException $e) {
            return JsonResult::failed(self::COMMAND, $e->errorCode, $e->getMessage(), $e->hint)->emit($this);
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        return JsonResult::ok(self::COMMAND, $prepared->toArray())->emit($this);
    }

    private function failure(RuntimeException $e): int
    {
        return match (true) {
            $e->getMessage() === 'identity_required' => JsonResult::failed(self::COMMAND, 'identity_required', 'This backup is encrypted: the identity file and its passphrase are needed.')->emit($this),
            str_starts_with($e->getMessage(), 'age could not decrypt') => JsonResult::failed(self::COMMAND, 'decrypt_failed', $e->getMessage())->emit($this),
            default => JsonResult::failed(self::COMMAND, 'prepare_failed', $e->getMessage())->emit($this),
        };
    }

    private function path(string $option): string
    {
        $value = $this->option($option);
        if (! is_string($value) || ! str_starts_with($value, '/')) {
            throw new InstanceException("--{$option} needs an absolute path.", errorCode: 'invalid_flag');
        }

        return rtrim($value, '/');
    }

    /** @return array{passphrase: string|null, keys: list<array{fingerprint: string, key: string}>} */
    private function stdinDocument(): array
    {
        $raw = $this->laravel->bound(self::STDIN) ? ($this->laravel->make(self::STDIN))() : (string) stream_get_contents(STDIN);

        try {
            $decoded = trim($raw) === '' ? [] : json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InstanceException('stdin is not the JSON document fieldset sends.', errorCode: 'invalid_input');
        }
        $keys = [];
        foreach (is_array($decoded['keys'] ?? null) ? $decoded['keys'] : [] as $key) {
            if (is_array($key) && is_string($key['fingerprint'] ?? null) && is_string($key['hmac_key'] ?? null)) {
                $keys[] = ['fingerprint' => $key['fingerprint'], 'key' => BackupChecksums::key($key['hmac_key'])];
            }
        }

        return ['passphrase' => is_string($decoded['passphrase'] ?? null) ? $decoded['passphrase'] : null, 'keys' => $keys];
    }
}
