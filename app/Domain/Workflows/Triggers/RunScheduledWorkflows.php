<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Triggers;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Workflows\Enums\TriggerType;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowRun;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Throwable;

/**
 * The `schedule` trigger (`workflows:tick`, every minute): starts every active schedule workflow whose
 * cron expression is due this minute in its organization's time zone. The event id is the minute, so a
 * second tick in the same minute (overlap, two schedulers) starts nothing twice.
 */
final readonly class RunScheduledWorkflows
{
    public function __construct(private TenantContext $tenant, private StartWorkflows $start) {}

    /** @return int number of runs started */
    public function handle(CarbonImmutable $now): int
    {
        $minute = $now->startOfMinute();
        $started = 0;
        $workflows = Workflow::query()->withoutTenant()->where('active', true)
            ->where('trigger_type', TriggerType::Schedule->value)->orderBy('id')->get();

        foreach ($workflows as $workflow) {
            try {
                $started += $this->runIfDue($workflow, $minute);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $started;
    }

    private function runIfDue(Workflow $workflow, CarbonImmutable $minute): int
    {
        $cron = $workflow->configValue('cron');
        if ($cron === null || ! CronExpression::isValidExpression($cron)) {
            return 0;
        }
        $timezone = Organization::query()->whereKey($workflow->organization_id)->value('timezone');
        if (! new CronExpression($cron)->isDue($minute->setTimezone(is_string($timezone) ? $timezone : 'UTC'))) {
            return 0;
        }

        return $this->tenant->run($workflow->organization_id, fn (): int => count(array_filter(
            $this->start->handle(TriggerEvent::schedule($workflow, $minute)),
            static fn (WorkflowRun $run): bool => $run->wasRecentlyCreated,
        )));
    }
}
