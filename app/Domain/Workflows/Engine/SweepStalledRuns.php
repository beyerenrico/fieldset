<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Engine;

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Workflows\Enums\RunStatus;
use App\Domain\Workflows\Models\WorkflowRun;
use Carbon\CarbonImmutable;

/**
 * Safety net (like documents:sweep, ADR 0031): a run that stayed pending/running without any change for
 * `workflows.sweep_after_minutes` lost its step job (dispatch error, lost queue) and gets its current step
 * queued again. The step job is idempotent (claim), so a late original job does nothing.
 */
final readonly class SweepStalledRuns
{
    public const int BATCH = 100;

    public function __construct(private TenantContext $tenant) {}

    public function handle(CarbonImmutable $now): int
    {
        $stalled = WorkflowRun::query()->withoutTenant()
            ->whereIn('status', [RunStatus::Pending->value, RunStatus::Running->value])
            ->where('updated_at', '<', $now->subMinutes(max(1, (int) config('workflows.sweep_after_minutes', 30))))
            ->orderBy('id')->limit(self::BATCH)->get();

        foreach ($stalled as $run) {
            $this->tenant->run($run->organization_id, function () use ($run): void {
                $run->touch();
                StepDispatcher::dispatch($run, $run->current_step);
            });
        }

        return $stalled->count();
    }
}
