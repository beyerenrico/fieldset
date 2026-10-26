<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\Confirmation;
use App\Domain\Instance\Ownership\OwnershipRepair;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * `fieldset repair-ownership` (2.1.1 U4, R5, R28, KTD12, ADR 0077): gives the instance files to the owner the shim measured
 * in this run (it always measures for this command) and saves that owner with the instance, after Docker began to map user
 * IDs differently than when the instance was set up; doctor's permissions check names this command for such a mismatch.
 * Owners only (no modes, no symlinks, one file system), secrets stay 0600, the backup service is recreated with the new
 * owner, Docker volumes are untouched. It asks first (exit 6 without a terminal and without --yes); nothing to do when the
 * saved and the measured owner already match. Exit 3 `owner_not_measured` when the shim could not measure.
 */
#[Description('Give the instance files to the owner Docker shows them as now, after a Docker change (asks first)')]
#[Signature('fieldset:repair-ownership')]
final class RepairOwnershipCommand extends FieldsetCommand
{
    protected function recordsOwner(): bool
    {
        return true; // the no-op path still saves the measurement once (KTD6)
    }

    protected function perform(FieldsetContext $context): CommandResult
    {
        return $this->laravel->make(OwnershipRepair::class)->repair($context);
    }

    /** An unfinished operation exits 9 before the question (M12 gate C4); `data`: the owners (O6); no question when they match. */
    protected function confirmationPrompt(FieldsetContext $context): ?Confirmation
    {
        $this->refuseUnfinished(static fn (): bool => false);
        $change = $this->laravel->make(OwnershipRepair::class)->plan($context->facts);

        return $change->needed() ? new Confirmation($change->question(), $change->toArray()) : null;
    }
}
