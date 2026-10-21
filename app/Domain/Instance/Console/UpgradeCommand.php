<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Upgrade\DetachedSupervisor;
use App\Domain\Instance\Upgrade\UpgradeOperation;
use App\Domain\Instance\Upgrade\UpgradeRequest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * `fieldset upgrade` (§7, R33–R36, KTD5, KTD19, plan U18, ADR 0086): moves the instance to the release the release URL names
 * latest (`latest/download/`: on GitHub the newest stable release, ReleaseFeed; a newer major is refused without
 * `--major`), or `--to` a version (`--major` to cross a major). The command starts the upgrade's supervisor as a detached
 * container of the installed image and follows it (DetachedSupervisor); `--supervise` is that supervisor's own run
 * (UpgradeOperation). Health-gated: a failure after the safety backup rolls back automatically (exit 5); a failed rollback
 * stops the instance (exit 8). `--resume` continues an interrupted upgrade with the flags it began with (exit 2
 * `nothing_to_resume` when none is open); `--follow` attaches to a running supervisor, or returns the last upgrade's
 * result (M12 gate O3); `--drop-jobs` saves and drops the jobs a bounded drain leaves behind instead of stopping (exit 9).
 */
#[Description('Upgrade the instance to a newer release, health-gated with automatic rollback')]
#[Signature('fieldset:upgrade
        {--to= : The release to upgrade to (default: the release the release URL calls latest, on GitHub the newest stable one)}
        {--major : Allow crossing a major version (read the release notes first)}
        {--drop-jobs : When the queue does not drain in time, save its jobs to a file and drop them instead of stopping}
        {--resume : Continue an upgrade that was interrupted}
        {--follow : Follow the running upgrade (or show the last one\'s result); starts nothing}
        {--supervise : Run the upgrade in this process (the detached supervisor; used by fieldset itself)}')]
final class UpgradeCommand extends FieldsetCommand
{
    protected function perform(FieldsetContext $context): CommandResult
    {
        if ($this->option('follow') === true) {
            $given = array_keys(array_filter(['--to' => $this->option('to') !== null, '--major' => $this->option('major') === true, '--drop-jobs' => $this->option('drop-jobs') === true,
                '--resume' => $this->option('resume') === true, '--supervise' => $this->option('supervise') === true]));
            if ($given !== []) {
                throw new InvalidInput('--follow starts nothing and takes no '.implode(', ', $given).'.', 'invalid_flag', 'Run `fieldset upgrade --follow` alone.');
            }

            return $this->laravel->make(DetachedSupervisor::class)->attach($context->progress);
        }
        $request = UpgradeRequest::fromOptions($this->option('to'), $this->option('major') === true, $this->option('drop-jobs') === true, $this->option('resume') === true, $context->facts->callerUid());
        $supervisor = $this->laravel->make(DetachedSupervisor::class);
        if ($this->option('supervise') !== true) {
            return $supervisor->launchAndFollow($request, $context->facts, $context->progress);
        }
        $result = $this->laravel->make(UpgradeOperation::class)->run($request, $context->facts, $context->progress);
        $supervisor->report($result, $context->redactor);

        return $result;
    }
}
