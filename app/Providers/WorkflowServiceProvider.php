<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Forms\Events\FormPublished;
use App\Domain\Submissions\Events\SubmissionStatusChanged;
use App\Domain\Workflows\Console\WorkflowsTick;
use App\Domain\Workflows\Tasks\Listeners\CancelReviewTasksOnStatusChange;
use App\Domain\Workflows\Triggers\Listeners\StartWorkflowsOnFormPublished;
use App\Domain\Workflows\Triggers\Listeners\StartWorkflowsOnStatusChange;
use App\Support\Network\DnsHostResolver;
use App\Support\Network\HostResolver;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Workflow automation (M4, ADR 0032): trigger listeners, the `workflows:tick` command and its schedule. */
final class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(HostResolver::class, DnsHostResolver::class);
    }

    public function boot(): void
    {
        Event::listen(SubmissionStatusChanged::class, CancelReviewTasksOnStatusChange::class);
        Event::listen(SubmissionStatusChanged::class, StartWorkflowsOnStatusChange::class);
        Event::listen(FormPublished::class, StartWorkflowsOnFormPublished::class);

        if ($this->app->runningInConsole()) {
            $this->commands([WorkflowsTick::class]);
        }

        // Schedule triggers, overdue reminders and the stalled-run sweep (scheduler service, one server).
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('workflows:tick')->everyMinute()->withoutOverlapping()->onOneServer();
        });
    }
}
