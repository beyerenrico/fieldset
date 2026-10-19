<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceDirectory;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Backup encryption's key set-up (R40, KTD17, ADR 0084), in the ops container: a new age X25519 key pair; the identity,
 * encrypted with the owner's passphrase, written once to a path outside the instance directory (exit 2 inside it, or
 * when the file exists: an identity is never overwritten), mode 0600; then a decrypt test (a random challenge encrypted
 * to the new recipient must come back through the identity file and the passphrase). Only the recipient (the public
 * key) goes into the instance; a failed test removes the identity file (exit 3).
 */
final readonly class KeySetup
{
    public function __construct(private AgeCli $age) {}

    /** @throws InvalidInput exit 2 for a relative path, one inside the instance directory, an existing file or a missing directory */
    public static function checkPath(string $path, InstanceDirectory $directory): void
    {
        if (! str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new InvalidInput('--identity-out needs an absolute path.', 'invalid_identity_path');
        }
        $roots = array_unique(array_filter([rtrim($directory->root, '/'), realpath($directory->root) ?: null]));
        $parent = realpath(dirname($path));
        $candidates = array_unique(array_filter([$path, $parent === false ? null : $parent.'/'.basename($path)]));
        foreach ($candidates as $candidate) {
            foreach ($roots as $root) {
                if ($candidate === $root || str_starts_with($candidate, $root.'/')) {
                    throw new InvalidInput('The backup identity must not be stored in the instance directory: whoever has a backup there would have its key too.', 'identity_inside_instance', 'Pass --identity-out with a path outside '.$directory->root.', e.g. in your home directory.');
                }
            }
        }
        if ($parent === false || ! is_dir($parent) || ! is_writable($parent)) {
            throw new InvalidInput('The directory of --identity-out does not exist or is not writable.', 'invalid_identity_path');
        }
        if (file_exists($path) || is_link($path)) {
            throw new InvalidInput("{$path} exists already; fsa never overwrites an identity.", 'identity_exists', 'Pass a new file name, or move the old identity away if you are sure it is not needed for older backups.');
        }
    }

    /**
     * @return string the new recipient (`age1…`)
     *
     * @throws InstanceException exit 3 when writing or testing the identity fails (the file is removed)
     */
    public function create(string $path, #[SensitiveParameter] string $passphrase): string
    {
        $pair = $this->age->generateIdentity();
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new InvalidInput("{$path} cannot be created.", 'invalid_identity_path');
        }
        fclose($handle);
        chmod($path, 0600);

        try {
            $this->age->encryptWithPassphrase($pair['identity'], $passphrase, $path);
            chmod($path, 0600);
            $this->test($path, $passphrase, $pair['recipient']);
        } catch (Throwable $e) {
            @unlink($path);
            throw new InstanceException('Writing or testing the backup identity failed: '.$e->getMessage(), ExitCode::PreflightFailed, 'identity_test_failed', 'Check that age works in the image (`fsa doctor`) and run the command again; nothing was changed.', true, [], $e);
        }

        return $pair['recipient'];
    }

    /** Encrypts a challenge to the recipient and reads it back through the identity file and its passphrase. */
    private function test(string $path, #[SensitiveParameter] string $passphrase, string $recipient): void
    {
        $challenge = bin2hex(random_bytes(16));
        $file = tempnam(sys_get_temp_dir(), 'fsa-age-test-');
        if ($file === false) {
            throw new RuntimeException('No temporary file for the decrypt test.');
        }

        try {
            $this->age->encryptToRecipients($challenge, [$recipient], $file);
            $identity = $this->age->decryptWithPassphrase($path, $passphrase);
            if (! hash_equals($challenge, $this->age->decryptWithIdentity($identity, $file))) {
                throw new RuntimeException('the decrypt test returned something else');
            }
        } finally {
            @unlink($file);
        }
    }
}
