<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\Confirmation;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Restore\AppKeys;
use App\Domain\Instance\Restore\RestoreAbandon;
use App\Domain\Instance\Restore\RestoreDrill;
use App\Domain\Instance\Restore\RestoreOperation;
use App\Domain\Instance\Restore\RestoreRequest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * `fieldset restore <backup>` (§7, R41, R42, R64, R67, AE8, plan U17, ADR 0085): restores a backup into this instance after
 * confirmation (RestoreOperation), or with `--drill` checks it in a scratch database without touching the instance
 * (RestoreDrill). `--abandon` cleans up a restore that stopped half-way (RestoreAbandon).
 *
 * - An encrypted backup needs `--identity-file` and the passphrase (`--passphrase-file`, `--passphrase-stdin`).
 * - `--app-key-file`: APP keys the backup was made with that this instance lacks (one per line, or an env file such as
 *   the old instance's env/app.env); they authenticate the backup and become APP_PREVIOUS_KEYS.
 * - `--accept-unauthenticated`: restore an archive no key from outside it authenticates (a 1.0 backup, a foreign one).
 */
#[Description('Restore a backup into this instance (asks first), or check it with --drill')]
#[Signature('fieldset:restore
        {backup? : A backup name in backups/ or a path inside the instance directory}
        {--drill : Restore into a scratch database, check it and drop it; the instance is not touched}
        {--abandon : Clean up a restore that stopped half-way and bring the instance back}
        {--accept-unauthenticated : Restore an archive whose HMAC no APP key from outside it verifies}
        {--identity-file= : The passphrase-protected backup identity (encrypted backups)}
        {--passphrase-file= : Read the identity\'s passphrase from this file}
        {--passphrase-stdin : Read the identity\'s passphrase from stdin (a hidden prompt in a terminal)}
        {--app-key-file= : APP keys the backup was made with (one per line, or APP_KEY=/APP_PREVIOUS_KEYS= lines)}
        {--without-files : Restore a backup that carries no storage archive: the database only, the current files kept aside}')]
final class RestoreCommand extends FieldsetCommand
{
    protected function perform(FieldsetContext $context): CommandResult
    {
        if ($this->option('abandon') === true) {
            return $this->laravel->make(RestoreAbandon::class)->abandon($context);
        }
        $request = $this->request($context);

        return $this->option('drill') === true
            ? $this->laravel->make(RestoreDrill::class)->drill($request, $context)
            : $this->laravel->make(RestoreOperation::class)->restore($request, $context);
    }

    /**
     * Another unfinished operation (or a restore of another backup) exits 9 before the question (M12 gate C4); `data` names
     * the backup and the safety backup (O6).
     */
    protected function confirmationPrompt(FieldsetContext $context): ?Confirmation
    {
        if ($this->option('drill') === true) {
            return null;
        }
        if ($this->option('abandon') === true) {
            return new Confirmation('Clean up the interrupted restore and bring the instance back as it was before it?', ['abandon' => true]);
        }
        $backup = is_string($this->argument('backup')) ? trim($this->argument('backup')) : '';
        $this->refuseUnfinished(static fn (JournalRecord $open): bool => $open->operation() === RestoreOperation::OPERATION && ($open->context()['backup'] ?? null) === $backup);

        return new Confirmation('Replace this instance\'s database and files with the backup'.($backup === '' ? '' : " {$backup}").'? Everything written since the backup is lost (a safety backup is taken first).',
            ['backup' => $backup === '' ? null : $backup, 'safety_backup' => true]);
    }

    private function request(FieldsetContext $context): RestoreRequest
    {
        $backup = $this->argument('backup');
        if (! is_string($backup) || trim($backup) === '') {
            throw new InvalidInput('Name the backup to restore.', 'backup_required', 'Run `fieldset status` for the newest backups, then `fieldset restore <name>`.');
        }
        $identity = $this->option('identity-file');
        $identity = is_string($identity) && trim($identity) !== '' ? $this->absolute(trim($identity)) : null;
        $passphrase = PassphraseInput::fromCommand($this, $context, twice: false);

        $keyFile = $this->keyFile($context);

        return new RestoreRequest(
            trim($backup),
            $this->option('accept-unauthenticated') === true,
            $identity,
            $identity === null && ! $passphrase->given() ? null : $passphrase->read($context),
            $keyFile,
            $context->facts->callerUid(),
            $this->option('without-files') === true,
            $passphrase->file(),
            $passphrase->stdin(),
            $keyFile instanceof AppKeys ? $this->absolute(trim((string) $this->option('app-key-file'))) : null,
        );
    }

    private function keyFile(FieldsetContext $context): ?AppKeys
    {
        $path = $this->option('app-key-file');
        if (! is_string($path) || trim($path) === '') {
            return null;
        }
        $path = $this->absolute(trim($path));
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

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : getcwd().'/'.$path;
    }
}
