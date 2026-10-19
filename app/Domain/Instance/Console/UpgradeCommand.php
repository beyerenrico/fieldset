<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Upgrade\DetachedSupervisor;
use App\Domain\Instance\Upgrade\UpgradeOperation;
use App\Domain\Instance\Upgrade\UpgradeRequest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * `fsa upgrade` (§7, R33–R36, KTD5, KTD19, plan U18, ADR 0086): moves the instance to the newest release within its major,
 * or `--to` a version (`--major` to cross a major). The command starts the upgrade's supervisor as a detached container
 * of the installed image and follows it (DetachedSupervisor); `--supervise` is that supervisor's own run
 * (UpgradeOperation). Health-gated: a failure after the safety backup rolls back automatically (exit 5); a failed
 * rollback stops the instance (exit 8). `--resume` continues an interrupted upgrade; `--drop-jobs` saves and drops the
 * jobs a bounded drain leaves behind instead of stopping (exit 9).
 */
#[Description('Upgrade the instance to a newer release, health-gated with automatic rollback')]
#[Signature('fsa:upgrade
        {--to= : The release to upgrade to (default: the newest release)}
        {--major : Allow crossing a major version (read the release notes first)}
        {--drop-jobs : When the queue does not drain in time, save its jobs to a file and drop them instead of stopping}
        {--resume : Continue an upgrade that was interrupted}
        {--supervise : Run the upgrade in this process (the detached supervisor; used by fsa itself)}')]
final class UpgradeCommand extends FsaCommand
{
    protected function perform(FsaContext $context): CommandResult
    {
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
