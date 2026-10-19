<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Backup\BackupName;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\Confirmation;
use App\Domain\Instance\Restore\RestoreOperation;
use App\Domain\Instance\Restore\RestoreRequest;
use App\Domain\Instance\Upgrade\RollbackGuard;
use App\Domain\Instance\Upgrade\RollbackOperation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * `fsa rollback` (§7, R37, plan U18, ADR 0086): returns to the release before the last upgrade from that upgrade's
 * safety backup (RollbackOperation). It asks first and says what will be lost: everything written since the safety
 * backup, counted (exit 6 without a terminal or --yes). A sealed (encrypted) safety backup needs the backup identity
 * and its passphrase, like `fsa restore`.
 */
#[Description('Return to the release before the last upgrade, from its safety backup (asks first)')]
#[Signature('fsa:rollback
        {--identity-file= : The passphrase-protected backup identity (when the safety backup is encrypted)}
        {--passphrase-file= : Read the identity\'s passphrase from this file}
        {--passphrase-stdin : Read the identity\'s passphrase from stdin (a hidden prompt in a terminal)}')]
final class RollbackCommand extends FsaCommand
{
    protected function perform(FsaContext $context): CommandResult
    {
        $identity = $this->option('identity-file');
        $identity = is_string($identity) && trim($identity) !== '' ? (str_starts_with(trim($identity), '/') ? trim($identity) : getcwd().'/'.trim($identity)) : null;
        $passphrase = PassphraseInput::fromCommand($this, $context, twice: false);
        $request = new RestoreRequest('', false, $identity, $identity === null && ! $passphrase->given() ? null : $passphrase->read($context), null, $context->facts->callerUid(), $passphrase->file(), $passphrase->stdin());

        return $this->laravel->make(RollbackOperation::class)->rollback($request, $context->progress);
    }

    /**
     * The question names the version, the safety backup and what is lost (R37), and `data` carries them for an agent (M12
     * gate O6); a refused rollback exits 7 first. Another unfinished operation exits 9 before the guard and the count (C4).
     * An interrupted rollback continues without a question and without the guard: its journal holds the plan, and after its
     * swap the state names no previous release any more.
     */
    protected function confirmationPrompt(FsaContext $context): ?Confirmation
    {
        if ($this->refuseUnfinished(static fn (JournalRecord $open): bool => $open->operation() === RollbackOperation::OPERATION) instanceof JournalRecord) {
            return null;
        }
        $state = RestoreOperation::installed($context->directory());
        $guard = $this->laravel->make(RollbackGuard::class);
        $plan = $guard->plan($state);
        $at = BackupName::parse($plan['backup'])?->at->toIso8601ZuluString();
        $loss = $at === null ? null : $guard->loss($state, $at);

        return new Confirmation(
            "Return from {$state->version()} to {$plan['version']} with the safety backup {$plan['backup']}".($at === null ? '' : " of {$at}").'? '.RollbackGuard::describe($loss),
            ['from' => $state->version(), 'to' => $plan['version'], 'safety_backup' => $plan['backup'], 'safety_backup_at' => $at, 'loss' => $loss],
        );
    }
}
