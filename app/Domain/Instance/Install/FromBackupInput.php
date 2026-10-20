<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\Console\FsaContext;
use App\Domain\Instance\Console\PassphraseInput;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Restore\AppKeys;
use Illuminate\Console\Command;

/**
 * Reads the `--from-backup` flags of `fsa install` (plan U17, ADR 0085): the backup path, the identity file and its
 * passphrase (`--passphrase-file`, `--passphrase-stdin`; entered once, R40), the APP keys of `--app-key-file` (each one
 * registered with the redactor at once) and `--accept-unauthenticated`. The flags that belong to it are refused without
 * it, and the SMTP account flags with it (a moved instance starts with capture mail).
 */
final class FromBackupInput
{
    /** Flags that only make sense with --from-backup. */
    private const array OWN = ['identity-file', 'passphrase-file', 'app-key-file'];

    private const array OWN_SWITCHES = ['passphrase-stdin', 'accept-unauthenticated', 'keep-recipients'];

    private const array ACCOUNT = ['smtp-username', 'password-file'];

    public static function fromCommand(Command $command, FsaContext $context): ?FromBackupRequest
    {
        $backup = self::string($command, 'from-backup');
        if ($backup === null) {
            return null;
        }
        $given = [...array_filter(self::ACCOUNT, static fn (string $option): bool => self::string($command, $option) !== null), ...($command->option('password-stdin') === true ? ['password-stdin'] : [])];
        if ($given !== []) {
            throw new InvalidInput('--'.implode(', --', $given).' do(es) not apply with --from-backup.', 'flag_not_applicable', 'A moved instance starts with mail captured; switch it with `fsa config set mail smtp …` once the move is complete.');
        }
        $identity = self::string($command, 'identity-file');
        $passphrase = PassphraseInput::fromCommand($command, $context, twice: false);

        $keyFile = self::keyFile($command, $context);

        return new FromBackupRequest(
            self::absolute($backup),
            $command->option('accept-unauthenticated') === true,
            $identity === null ? null : self::absolute($identity),
            $passphrase->given() ? $passphrase->read($context) : null,
            $keyFile,
            $command->option('keep-recipients') === true,
            $passphrase->file(),
            $passphrase->stdin(),
            $keyFile instanceof AppKeys ? self::absolute((string) self::string($command, 'app-key-file')) : null,
        );
    }

    /** @throws InvalidInput exit 2 for a --from-backup flag without --from-backup */
    public static function refuseStray(Command $command): void
    {
        $stray = [
            ...array_filter(self::OWN, static fn (string $option): bool => self::string($command, $option) !== null),
            ...array_filter(self::OWN_SWITCHES, static fn (string $option): bool => $command->option($option) === true),
        ];
        if ($stray !== []) {
            throw new InvalidInput('--'.implode(', --', $stray).' belong(s) to --from-backup.', 'invalid_flag', 'Add --from-backup <backup>, or leave these flags out.');
        }
    }

    private static function keyFile(Command $command, FsaContext $context): ?AppKeys
    {
        $path = self::string($command, 'app-key-file');
        if ($path === null) {
            return null;
        }
        $path = self::absolute($path);
        $contents = is_file($path) && is_readable($path) ? file_get_contents($path, length: 65536) : false;
        if ($contents === false) {
            throw new InvalidInput('The file of --app-key-file cannot be read.', 'invalid_flag', 'Pass a readable file with the APP key (mode 0600).');
        }
        $keys = AppKeys::fromFile($contents);
        foreach ($keys->keys as $key) {
            $context->redactor->register($key);
        }

        return $keys;
    }

    private static function string(Command $command, string $option): ?string
    {
        $value = $command->option($option);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function absolute(string $path): string
    {
        return rtrim(str_starts_with($path, '/') ? $path : getcwd().'/'.$path, '/');
    }
}
