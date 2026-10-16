<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Console;

use App\Domain\Workflows\Engine\SweepStalledRuns;
use App\Domain\Workflows\Tasks\SendOverdueReminders;
use App\Domain\Workflows\Triggers\RunScheduledWorkflows;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Every minute (scheduler): schedule triggers, overdue review reminders and the stalled-run sweep. */
#[Description('Start due scheduled workflows, remind overdue reviews and re-queue stalled runs')]
#[Signature('workflows:tick')]
final class WorkflowsTick extends Command
{
    public function handle(RunScheduledWorkflows $schedules, SendOverdueReminders $reminders, SweepStalledRuns $sweep): int
    {
        $now = CarbonImmutable::now();
        $this->info(sprintf(
            'Started %d scheduled run(s); sent %d reminder(s); re-queued %d stalled run(s).',
            $schedules->handle($now), $reminders->handle($now), $sweep->handle($now),
        ));

        return self::SUCCESS;
    }
}
