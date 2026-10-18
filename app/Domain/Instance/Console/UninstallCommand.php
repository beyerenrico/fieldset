<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Lifecycle\Uninstaller;
use App\Domain\Instance\Output\CommandResult;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * `fsa uninstall` (§7, R44, R63, §13.3, ADR 0072, ADR 0073): removes every container, volume and network labelled
 * with this instance's UUID, and the files of the instance directory. With --keep-backups it first writes a final
 * backup (database dump, storage archive, APP_KEY) into `backups/`, which stays and which a later install
 * accepts; without it the data and the backups are deleted. `--orphans <uuid>` removes only the leftovers of
 * another installation that install named for this directory. Destructive: exit 6 without a terminal (or with
 * --json) unless --yes.
 */
#[Description('Remove the instance: its labelled containers, volumes and networks, and its directory')]
#[Signature('fsa:uninstall
        {--keep-backups : Write a final backup into backups/ first and keep that directory}
        {--orphans= : Remove only the leftovers of this other installation UUID, as fsa install names them}')]
final class UninstallCommand extends FsaCommand
{
    protected function confirmationPrompt(FsaContext $context): string
    {
        $orphans = $this->option('orphans');
        if (is_string($orphans) && $orphans !== '') {
            return "Remove the stopped containers, volumes and networks labelled with installation {$orphans}?";
        }
        $root = $context->directory()->root;

        return $this->option('keep-backups')
            ? "Write a final backup into {$root}/backups/, then remove this instance with all its data (containers, volumes, networks) and every file in {$root} except backups/?"
            : "Remove this instance with all its data (containers, volumes, networks) and every file in {$root}, including backups/? No final backup is made (use --keep-backups for one).";
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
