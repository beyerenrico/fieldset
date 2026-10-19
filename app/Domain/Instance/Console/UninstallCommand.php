<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Adopt\AdoptedInstance;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\Uninstaller;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\State\InstanceSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * `fsa uninstall` (§7, R44, R63, §13.3, ADR 0072, ADR 0073): removes every container, volume and network labelled
 * with this instance's UUID, and the files of the instance directory. With --keep-backups it first writes a final
 * backup (database dump, storage archive, APP_KEY) into `backups/`, which stays and which a later install
 * accepts; without it the data and the backups are deleted. An adopted instance (plan U19, ADR 0087) gets that final
 * backup and keeps backups/ by default (`--no-backup` skips it), and the result reports the writes made since adopt and
 * how 1.0 comes back. `--orphans <uuid>` removes only the leftovers of another installation that install named for this
 * directory. Destructive: exit 6 without a terminal (or with --json) unless --yes.
 */
#[Description('Remove the instance: its labelled containers, volumes and networks, and its directory')]
#[Signature('fsa:uninstall
        {--keep-backups : Write a final backup into backups/ first and keep that directory}
        {--no-backup : Write no final backup (an adopted instance gets one by default); with --keep-backups, backups/ stays without one}
        {--orphans= : Remove only the leftovers of this other installation UUID, as fsa install names them}')]
final class UninstallCommand extends FsaCommand
{
    protected function confirmationPrompt(FsaContext $context): string
    {
        $orphans = $this->option('orphans');
        if (is_string($orphans) && $orphans !== '') {
            return "Remove the stopped containers, volumes and networks labelled with installation {$orphans}?";
        }
        $notice = $this->certificateNotice($context);
        $prompt = $this->prompt($context->directory()->root, $this->adoptedFrom($context), $this->option('keep-backups') === true, $this->option('no-backup') === true);

        return $notice === null ? $prompt : $notice.' '.$prompt;
    }

    /** The question for each combination of --keep-backups and --no-backup, adopted or not (review A8). */
    private function prompt(string $root, ?string $adopted, bool $keep, bool $noBackup): string
    {
        $legacy = $adopted === null ? '' : " The 1.0 install in {$adopted} is not touched; start it again with `docker compose up -d` there.";
        if ($noBackup) {
            return $keep
                ? "Remove this instance with all its data (containers, volumes, networks) and every file in {$root} except backups/? No final backup is made.{$legacy}"
                : "Remove this instance with all its data (containers, volumes, networks) and every file in {$root}, including backups/".($adopted === null ? '' : ' (the adopt\'s 1.0 backup too)')."? No final backup is made.{$legacy}".($adopted === null ? '' : ' Data written since the adopt is lost.');
        }
        if ($keep || $adopted !== null) {
            return "Write a final backup into {$root}/backups/, then remove this instance with all its data (containers, volumes, networks) and every file in {$root} except backups/?{$legacy}".($adopted === null ? '' : ' Data written since the adopt is only in the final backup.');
        }

        return "Remove this instance with all its data (containers, volumes, networks) and every file in {$root}, including backups/? No final backup is made (use --keep-backups for one).";
    }

    /**
     * When the instance holds certificates (its edge volume exists, ADR 0078), the prompt warns about the CA's
     * duplicate-certificate limit first (plan Risks, ADR 0075). If Docker cannot be asked, domain mode decides.
     */
    private function certificateNotice(FsaContext $context): ?string
    {
        try {
            return $this->laravel->make(Uninstaller::class)->certificateNotice();
        } catch (InstanceException) {
            try {
                $directory = $context->directory();
                $domain = $directory->hasState() ? InstanceSettings::of($directory->state())->domain() : null;

                return $domain === null ? null : Uninstaller::certificateWarning($domain);
            } catch (InstanceException) {
                return null;
            }
        }
    }

    protected function perform(FsaContext $context): CommandResult
    {
        $uninstaller = $this->laravel->make(Uninstaller::class);
        $orphans = $this->option('orphans');

        return is_string($orphans) && $orphans !== ''
            ? $uninstaller->orphans($orphans)
            : $uninstaller->uninstall((bool) $this->option('keep-backups'), $context->facts, $this->option('no-backup') === true);
    }

    /**
     * The 1.0 checkout an adopted instance (or an unfinished adopt that stopped 1.0) came from (plan U19, ADR 0087), null
     * otherwise or when the state is unreadable.
     */
    private function adoptedFrom(FsaContext $context): ?string
    {
        try {
            $directory = $context->directory();
            $record = $this->laravel->make(AdoptedInstance::class)->current($directory->hasState() ? $directory->state() : null);
        } catch (InstanceException) {
            return null;
        }

        return is_string($record['checkout'] ?? null) ? $record['checkout'] : null;
    }
}
