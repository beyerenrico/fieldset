<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Backup\BackupName;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Restore\RestoreOperation;
use App\Domain\Instance\Restore\RestoreRequest;
use App\Domain\Instance\Upgrade\RollbackGuard;
use App\Domain\Instance\Upgrade\RollbackOperation;
use Carbon\CarbonImmutable;
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
        $request = new RestoreRequest('', false, $identity, $identity === null && ! $passphrase->given() ? null : $passphrase->read($context), null, $context->facts->callerUid());

        return $this->laravel->make(RollbackOperation::class)->rollback($request, $context->progress);
    }

    /**
     * The question names the version, the safety backup and what is lost (R37); a refused rollback exits 7 first. An
     * interrupted rollback continues without a question and without the guard: its journal holds the plan, and after its
     * swap the state names no previous release any more.
     */
    protected function confirmationPrompt(FsaContext $context): ?string
    {
        if ($this->laravel->make(OperationJournal::class)->current()?->operation() === RollbackOperation::OPERATION) {
            return null;
        }
        $state = RestoreOperation::installed($context->directory());
        $guard = $this->laravel->make(RollbackGuard::class);
        $plan = $guard->plan($state);
        $at = BackupName::parse($plan['backup'])?->at;
        $loss = $at instanceof CarbonImmutable ? $guard->loss($state, $at->toIso8601ZuluString()) : null;

        return "Return from {$state->version()} to {$plan['version']} with the safety backup {$plan['backup']}".($at instanceof CarbonImmutable ? ' of '.$at->toIso8601ZuluString() : '').'? '.RollbackGuard::describe($loss);
    }
}
