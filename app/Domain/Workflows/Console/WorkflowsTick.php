<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Console;

use App\Domain\Workflows\Engine\SweepStalledRuns;
use App\Domain\Workflows\Tasks\SendOverdueReminders;
use App\Domain\Workflows\Triggers\RunScheduledWorkflows;
use App\Domain\Workflows\Triggers\SweepUnstartedEvents;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Every minute (scheduler): schedule triggers, overdue review reminders, the stalled-run and the lost-start sweeps. */
#[Description('Start due scheduled workflows, remind overdue reviews, re-queue stalled runs and lost workflow starts')]
#[Signature('workflows:tick')]
final class WorkflowsTick extends Command
{
    public function handle(RunScheduledWorkflows $schedules, SendOverdueReminders $reminders, SweepStalledRuns $sweep, SweepUnstartedEvents $starts): int
    {
        $now = CarbonImmutable::now();
        $this->info(sprintf(
            'Started %d scheduled run(s); sent %d reminder(s); re-queued %d stalled run(s) and %d lost start(s).',
            $schedules->handle($now), $reminders->handle($now), $sweep->handle($now), $starts->handle($now),
        ));

        return self::SUCCESS;
    }
}
