<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Lifecycle\Uninstaller;
use App\Domain\Instance\Output\CommandResult;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * `fsa uninstall` (§7, R44, R63, ADR 0072): removes every container, volume and network labelled with this
 * instance's UUID, and the files of the instance directory (with --keep-backups all but `backups/`, which a
 * later install accepts). `--orphans <uuid>` removes only the labelled resources of another installation whose
 * directory was deleted by hand. Destructive: exit 6 without a terminal (or with --json) unless --yes.
 */
#[Description('Remove the instance: its labelled containers, volumes and networks, and its directory')]
#[Signature('fsa:uninstall
        {--keep-backups : Keep the backups directory}
        {--orphans= : Remove only the resources labelled with this other installation UUID}')]
final class UninstallCommand extends FsaCommand
{
    protected function confirmationPrompt(FsaContext $context): string
    {
        $orphans = $this->option('orphans');
        if (is_string($orphans) && $orphans !== '') {
            return "Remove all containers, volumes and networks labelled with installation {$orphans}?";
        }

        return 'Remove this instance with all its data (containers, volumes, networks) and the files in '.$context->directory()->root
            .($this->option('keep-backups') ? ', except backups/' : '').'?';
    }

    protected function perform(FsaContext $context): CommandResult
    {
        $uninstaller = $this->laravel->make(Uninstaller::class);
        $orphans = $this->option('orphans');

        return is_string($orphans) && $orphans !== ''
            ? $uninstaller->orphans($orphans)
            : $uninstaller->uninstall((bool) $this->option('keep-backups'), $context->facts);
    }
}
