<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Reset\ResetOperation;
use App\Domain\Instance\Reset\ResetRequest;
use App\Domain\Instance\Tunnel\TunnelSweeper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * `fsa reset` (§7, R43, R57, R68, plan U23, ADR 0088): returns the instance to a fresh state, the first organization and
 * its owner (with `--demo` the demo data too), after confirmation (exit 6 without a terminal and without `--yes`). A
 * safety backup comes first unless `--no-backup`; the final audit chain heads go to `backups/ledger.log` before the
 * database is recreated. Settings and secrets stay. A reset that already recreated the database is finished by running
 * `fsa reset` again with the same flags, without a question (nothing more can be lost).
 */
#[Description('Return the instance to a fresh state (asks first; a safety backup is taken unless --no-backup)')]
#[Signature('fsa:reset
        {--demo : Add the demo organization, forms and users (random passwords unless the instance is loopback-only)}
        {--no-backup : Skip the safety backup (an audit export is still written while real organizations exist)}')]
final class ResetCommand extends FsaCommand
{
    protected function perform(FsaContext $context): CommandResult
    {
        // An expired or stopped quick tunnel goes first (AE6, ADR 0082): this is a mutating command.
        $swept = $this->laravel->make(TunnelSweeper::class)->sweep($context);

        return TunnelSweeper::then($this->resultName(), $swept, fn (): CommandResult => $this->laravel->make(ResetOperation::class)->reset(
            new ResetRequest((bool) $this->option('demo'), (bool) $this->option('no-backup'), $context->facts->callerUid()),
            $context,
            is_string($this->option('secrets-file')) && $this->option('secrets-file') !== '',
        ));
    }

    protected function confirmationPrompt(FsaContext $context): ?string
    {
        if (ResetOperation::pastDrop($this->laravel->make(OperationJournal::class)->current())) {
            return null; // the database was recreated already: finishing the reset loses nothing more
        }

        return $this->option('no-backup') === true
            ? 'Delete every organization, form, submission, user, file and captured mail of this instance WITHOUT a backup? Settings and secrets stay.'
            : 'Delete every organization, form, submission, user, file and captured mail of this instance and start fresh? A safety backup is taken first; settings and secrets stay.';
    }
}
