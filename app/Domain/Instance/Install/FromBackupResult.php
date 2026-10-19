<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\Lifecycle\MailSummary;
use App\Domain\Instance\Lifecycle\SignInDelivery;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Restore\PreparedBackup;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * The envelope of `fsa install --from-backup` (plan U17, ADR 0085): the usual install summary plus where the data came
 * from, the mail and webhook state a move starts in and the commands that confirm it (`fsa config set webhooks on`,
 * `fsa config set mail smtp …`), and the warnings: certificate pending, mail captured, webhooks paused, an
 * unauthenticated archive accepted.
 */
final readonly class FromBackupResult
{
    /** The command that confirms the move (with `fsa`; the instance's own path is prefixed in next_commands). */
    public const string CONFIRM_WEBHOOKS = 'fsa '.self::CONFIRM_WEBHOOKS_ARGS;

    public const string CONFIRM_WEBHOOKS_ARGS = 'config set webhooks on';

    /** @param  array<string, mixed>  $preflight  the doctor phase's data */
    public static function certificatePending(array $preflight): bool
    {
        return array_any(is_array($preflight['warnings'] ?? null) ? $preflight['warnings'] : [], static fn (mixed $warning): bool => is_array($warning) && ($warning['code'] ?? null) === 'certificate_pending');
    }

    /**
     * @param  array<string, mixed>  $intake  the backup phase's data
     * @param  array<string, mixed>  $preflight
     * @param  array<string, mixed>  $finish  instance:restore-finish's data
     * @param  array<string, mixed>  $done  the done phase's data
     */
    public static function build(InstanceState $state, InstanceDirectory $directory, InstallRequest $request, PreparedBackup $backup, array $intake, array $preflight, array $finish, array $done): CommandResult
    {
        $settings = InstanceSettings::of($state);
        $fsa = $directory->root.'/fsa';
        $email = $request->ownerEmailIfKnown();
        $pendingMail = FromBackupSettings::pendingMail($backup);
        $result = CommandResult::ok(Installer::OPERATION, [
            'url' => $settings->instanceUrl(), 'version' => $state->version(), 'mode' => $state->mode(), 'dir' => $directory->root, 'project' => $state->project(),
            'port' => $settings->publishesAppPort() ? $settings->port() : null, 'mail' => MailSummary::of($state), 'owner_email' => $email,
            'from_backup' => ['backup' => $backup->name, 'format' => $backup->format, 'authenticated' => $backup->authenticated(), 'encrypted' => $backup->encrypted,
                'created_at' => $backup->manifest['created_at'] ?? null, 'version' => $backup->version(), 'epoch' => $finish['epoch'] ?? null],
            'pending_mail' => $pendingMail, 'webhooks' => 'paused',
            'confirm_commands' => array_values(array_filter([self::CONFIRM_WEBHOOKS, $pendingMail['command'] ?? null])),
            SignInDelivery::SECRET => null,
            'next_commands' => ["{$fsa} status", "{$fsa} login-link ".($email ?? '<admin-email>'), "{$fsa} ".self::CONFIRM_WEBHOOKS_ARGS],
        ], changed: true);
        foreach (self::warnings($preflight, $intake, $email, $done) as [$code, $message, $hint]) {
            $result = $result->withWarning($code, $message, $hint);
        }
        $dropped = count(FromBackupSettings::recipients($backup)['dropped']);
        if ($dropped > 0) {
            return $result->withWarning('backup_recipients_dropped', "{$dropped} backup recipient(s) from the backup were not taken over".($backup->authenticated() ? ' (not an age public key)' : ' (the backup is not authenticated)').'; new backups are encrypted only to the recipients you set.', 'Turn encryption on for this instance with `fsa config set backup-encryption on --identity-out <path> --passphrase-file <file>`.');
        }

        return $result;
    }

    /** A finished instance: the same backup changes nothing (R13); anything else is no install (exit 2). */
    public static function unchanged(InstanceState $state, FromBackupRequest $backup, InstanceDirectory $directory): CommandResult
    {
        $recorded = InstallRecord::recorded($state)['from_backup'] ?? null;
        if ($recorded !== $backup->backup) {
            throw new InvalidInput(
                'This instance is installed'.(is_string($recorded) ? ' from another backup' : '').'; fsa install changes nothing on an installed instance.',
                'use_restore',
                'Install from the backup into a new, empty directory (--dir <directory>); `fsa restore` here takes only backups this instance made.',
            );
        }
        $settings = InstanceSettings::of($state);

        return CommandResult::ok(Installer::OPERATION, ['url' => $settings->instanceUrl(), 'version' => $state->version(), 'mode' => $state->mode(), 'dir' => $directory->root,
            'project' => $state->project(), 'from_backup' => ['source' => $recorded], SignInDelivery::SECRET => null], changed: false)
            ->withWarning('no_sign_in_link', 'The instance is already installed, so no sign-in link was issued.', 'Run '.SignInDelivery::loginLinkCommand(null).' for a one-time sign-in link.');
    }

    /**
     * @param  array<string, mixed>  $preflight
     * @param  array<string, mixed>  $intake
     * @param  array<string, mixed>  $done
     * @return list<array{0: string, 1: string, 2: string|null}>
     */
    private static function warnings(array $preflight, array $intake, ?string $email, array $done): array
    {
        $warnings = [];
        foreach (is_array($preflight['warnings'] ?? null) ? $preflight['warnings'] : [] as $warning) {
            if (is_array($warning) && is_string($warning['code'] ?? null)) {
                $warnings[] = [$warning['code'], (string) ($warning['message'] ?? ''), is_string($warning['hint'] ?? null) ? $warning['hint'] : null];
            }
        }
        foreach (is_array($intake['warnings'] ?? null) ? $intake['warnings'] : [] as $warning) {
            if (is_array($warning) && is_string($warning[0] ?? null)) {
                $warnings[] = [$warning[0], (string) ($warning[1] ?? ''), is_string($warning[2] ?? null) ? $warning[2] : null];
            }
        }
        $warnings[] = ['mail_captured_after_move', 'Mail is captured in the bundled catcher until you switch it on: the old server may still be sending.', 'Once the move is complete: `fsa config set mail smtp …` (the backup\'s server is under data.pending_mail).'];
        $warnings[] = ['webhooks_paused', 'Workflow webhooks wait until you confirm the move, so the old and the new server never both deliver.', 'Once DNS points here and the old server is stopped: `'.self::CONFIRM_WEBHOOKS.'`.'];
        $failure = $done['link_failure'] ?? null;
        if (is_string($failure)) {
            $warnings[] = ['sign_in_link_unavailable', 'No sign-in link could be issued: '.$failure, 'Check that the address belongs to an admin of the restored data, then run '.SignInDelivery::loginLinkCommand($email).'.'];
        } elseif ($email === null) {
            $warnings[] = ['no_sign_in_link', 'No --owner-email was given, so no sign-in link was issued; the restored users sign in as before.', 'Run '.SignInDelivery::loginLinkCommand(null).' for an admin of the restored data.'];
        }

        return $warnings;
    }
}
