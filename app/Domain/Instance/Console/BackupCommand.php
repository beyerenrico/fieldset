<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Backup\ManualBackup;
use App\Domain\Instance\Config\Appliers\BackupEncryptionApplier;
use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Config\ConfigSetter;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Output\ResultStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * `fieldset backup` (§7, R38, R40, R67, ADR 0084): a manual backup into `backups/manual-<time>/`: the database dump, the
 * storage volume, the portable settings, the audit chain heads and the manifest, authenticated by an HMAC over
 * SHA256SUMS and, when encryption is on, one age file. It runs in a one-off of the backup service (no Docker socket,
 * the read-only backup role) under the instance lock; the instance keeps running.
 *
 * - `--encrypt`: turns encryption on first when it is off (as `fieldset config set backup-encryption on`, with
 *   `--identity-out` and `--passphrase-file` or `--passphrase-stdin`); every later backup is encrypted too.
 * - `--include-secrets`: APP_KEY (and previous keys) and the SMTP account go into the backup, which then must be
 *   encrypted (exit 2 otherwise): what `fieldset install --from-backup` on another host needs (R42).
 */
#[Description('Back up the instance now: database, storage, settings and audit heads (encrypted when encryption is on)')]
#[Signature('fieldset:backup
        {--encrypt : Turn backup encryption on first, if it is off (needs --identity-out and a passphrase)}
        {--include-secrets : Also back up APP_KEY and the SMTP account (only into an encrypted backup)}
        {--identity-out= : With --encrypt: write the passphrase-protected backup identity here (outside the instance directory)}
        {--passphrase-file= : With --encrypt: read the identity\'s passphrase from this file}
        {--passphrase-stdin : With --encrypt: read the identity\'s passphrase from stdin (a hidden prompt, twice, in a terminal)}')]
final class BackupCommand extends FieldsetCommand
{
    protected function perform(FieldsetContext $context): CommandResult
    {
        $encryption = null;
        if ($this->option('encrypt') === true) {
            $encryption = $this->enableEncryption($context);
            if ($encryption->status() !== ResultStatus::Ok) {
                return $encryption;
            }
        } elseif ($this->option('identity-out') !== null || $this->option('passphrase-file') !== null || $this->option('passphrase-stdin') === true) {
            throw new InvalidInput('--identity-out and the passphrase options belong to --encrypt.', 'invalid_flag', 'Run `fieldset backup --encrypt --identity-out <path> --passphrase-file <file>` to turn encryption on.');
        }
        $result = $this->laravel->make(ManualBackup::class)->run($context, $this->option('include-secrets') === true);
        if ($encryption instanceof CommandResult && $encryption->changed) {
            $result = $result->withData(['encryption' => $encryption->data['encryption'] ?? ['enabled' => true]]);
            foreach ($encryption->warnings as $warning) {
                $result = $result->withWarning($warning->code, $warning->message, $warning->hint);
            }
        }

        return $result;
    }

    /** The key set-up of `config set backup-encryption on`, under its own lock and journal; unchanged when already on. */
    private function enableEncryption(FieldsetContext $context): CommandResult
    {
        $request = new ConfigRequest(
            BackupEncryptionApplier::KEY,
            'on',
            identityOut: is_string($this->option('identity-out')) && trim($this->option('identity-out')) !== '' ? trim($this->option('identity-out')) : null,
            passphrase: PassphraseInput::fromCommand($this, $context)->read($context),
        );

        return $this->laravel->make(ConfigSetter::class)->set($request, $context, null);
    }
}
