<?php

declare(strict_types=1);

namespace App\Domain\Instance\Operations;

use App\Domain\Instance\Config\ConfigRequest;

/**
 * The command that resumes an unfinished restore, rollback, adopt or install from a backup, rebuilt from its journal's
 * context (R54, M12 gate O1, O2): the same subcommand with every flag the resumed run needs, because a resumed restore
 * decrypts and checks the backup again before it changes more. Only what the journal recorded goes in, never a secret:
 * the backup, the identity file, where the passphrase came from (`--passphrase-file <path>` or `--passphrase-stdin`), the
 * APP key file, `--accept-unauthenticated`, `--without-files` and `--keep-recipients` where they were given, and `--yes`
 * for the commands that ask first (restore, adopt; a resumed rollback asks nothing). A journal of an earlier build that
 * recorded no path names a placeholder (`<file>`). An install journal that came from `--from-backup` resumes only as
 * `fieldset install --from-backup <backup>`, never as a plain install.
 */
final class ResumeCommand
{
    public static function applies(JournalRecord $record): bool
    {
        return match ($record->operation()) {
            'restore' => is_string($record->context()['backup'] ?? null),
            'rollback' => true,
            'adopt' => is_string($record->context()['checkout'] ?? null),
            'install' => self::fromBackup($record) !== null,
            default => false,
        };
    }

    /** The backup an install journal came from (`--from-backup`), null for a plain install. */
    public static function fromBackup(JournalRecord $record): ?string
    {
        $backup = $record->operation() === 'install' ? ($record->context()['from_backup'] ?? null) : null;

        return is_string($backup) && $backup !== '' ? $backup : null;
    }

    /**
     * The open journal a plain `fieldset install` may continue. One that `--from-backup` began resumes only by that command
     * (O1): taken over, the restore phases would be skipped and an empty instance marked installed.
     *
     * @throws OperationUnfinished exit 9, naming `fieldset install --from-backup <backup>` with its flags
     */
    public static function forPlainInstall(?JournalRecord $open): ?JournalRecord
    {
        return $open instanceof JournalRecord && self::fromBackup($open) !== null ? throw new OperationUnfinished($open) : $open;
    }

    public static function line(JournalRecord $record): string
    {
        $flags = array_map(static fn (string $flag, string|bool $value): string => $value === true ? $flag : $flag.' '.ConfigRequest::shellWord((string) $value), array_keys(self::options($record)), self::options($record));

        return trim(self::command($record).' '.implode(' ', $flags));
    }

    /** @return array<string, string|true> the flags of that command, in the order the line names them */
    public static function options(JournalRecord $record): array
    {
        $context = $record->context();

        return match ($record->operation()) {
            'restore' => [...self::inputs($context), ...(($context['without_files'] ?? false) === true ? ['--without-files' => true] : []), '--yes' => true],
            'rollback' => self::inputs($context),
            'adopt' => array_filter([
                '--release' => is_string($context['release'] ?? null) && $context['release'] !== '' ? $context['release'] : false,
                '--drop-jobs' => in_array($context['drop_jobs'] ?? 0, [1, true], true), '--yes' => true,
            ], static fn (string|bool $value): bool => $value !== false),
            'install' => self::fromBackup($record) === null ? [] : [...self::inputs($context), ...(($context['keep_recipients'] ?? false) === true ? ['--keep-recipients' => true] : [])],
            default => [],
        };
    }

    /** The subcommand with its argument: `fieldset restore <backup>`, `fieldset adopt <checkout>`, `fieldset install --from-backup <backup>`. */
    public static function command(JournalRecord $record): string
    {
        $context = $record->context();
        $backup = self::fromBackup($record);

        return match ($record->operation()) {
            'restore' => 'fieldset restore '.ConfigRequest::shellWord((string) ($context['backup'] ?? '<backup>')),
            'adopt' => 'fieldset adopt '.ConfigRequest::shellWord((string) ($context['checkout'] ?? '<checkout>')),
            'install' => $backup === null ? 'fieldset install' : 'fieldset install --from-backup '.ConfigRequest::shellWord($backup),
            default => 'fieldset '.$record->operation(),
        };
    }

    /**
     * The backup inputs a journal recorded (RestoreRequest, FromBackupRequest, rollback): the unauthenticated switch, the
     * identity file, the passphrase's source and the APP key file.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, string|true>
     */
    private static function inputs(array $context): array
    {
        $identity = self::path($context['identity_file'] ?? null);
        $passphrase = self::path($context['passphrase_file'] ?? null);
        $stdin = ($context['passphrase_stdin'] ?? false) === true;
        $keyFile = ($context['key_file'] ?? false) === true || self::path($context['app_key_file'] ?? null) !== null;

        return array_filter([
            '--accept-unauthenticated' => ($context['accept_unauthenticated'] ?? false) === true,
            '--identity-file' => $identity ?? false,
            '--passphrase-file' => $passphrase ?? ($identity !== null && ! $stdin ? '<file>' : false),
            '--passphrase-stdin' => $passphrase === null && $stdin,
            '--app-key-file' => $keyFile ? (self::path($context['app_key_file'] ?? null) ?? '<file>') : false,
        ], static fn (string|bool $value): bool => $value !== false);
    }

    private static function path(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
