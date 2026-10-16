<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Pins;

use App\Domain\Dashboards\Models\DashboardPin;
use App\Domain\Identity\Models\User;
use App\Domain\Reports\Models\SavedReport;
use Illuminate\Support\Facades\Gate;

/**
 * Saved reports pinned to a user's dashboard (§8.1 v1.0, ADR 0034), always in the current tenant.
 * Pinning needs SavedReportPolicy::view; reading pins re-checks it and removes the pins of reports the
 * user can no longer view (deleted reports lose their pins by foreign-key cascade).
 */
final readonly class DashboardPins
{
    /** Pins the report for the user (idempotent) at the end of their pins. */
    public function pin(User $user, SavedReport $report): DashboardPin
    {
        Gate::forUser($user)->authorize('view', $report);

        $next = (int) DashboardPin::query()->where('user_id', $user->id)->max('position') + 1;

        return DashboardPin::query()->firstOrCreate(
            ['user_id' => $user->id, 'saved_report_id' => $report->id],
            ['position' => $next],
        );
    }

    /** Removes the user's pin of the report; always allowed, also after access to the report was lost. */
    public function unpin(User $user, int $savedReportId): void
    {
        DashboardPin::query()->where('user_id', $user->id)->where('saved_report_id', $savedReportId)->delete();
    }

    public function isPinned(User $user, int $savedReportId): bool
    {
        return DashboardPin::query()->where('user_id', $user->id)->where('saved_report_id', $savedReportId)->exists();
    }

    /**
     * The reports the user pinned and may still view, in pin order.
     *
     * @return list<SavedReport>
     */
    public function visibleReports(User $user): array
    {
        $pins = DashboardPin::query()->where('user_id', $user->id)->with('savedReport')->orderBy('position')->orderBy('id')->get();

        return $this->keepVisible($user, array_values($pins->all()));
    }

    /** One pinned report the user may still view, or null (not pinned, gone, or access lost: pin removed). */
    public function visibleReport(User $user, int $savedReportId): ?SavedReport
    {
        $pins = DashboardPin::query()->where('user_id', $user->id)->where('saved_report_id', $savedReportId)->with('savedReport.form')->get();

        return $this->keepVisible($user, array_values($pins->all()))[0] ?? null;
    }

    /**
     * @param  list<DashboardPin>  $pins
     * @return list<SavedReport>
     */
    private function keepVisible(User $user, array $pins): array
    {
        $reports = [];
        $stale = [];
        foreach ($pins as $pin) {
            $report = $pin->savedReport;
            if ($report instanceof SavedReport && Gate::forUser($user)->allows('view', $report)) {
                $reports[] = $report;
            } else {
                $stale[] = $pin->id;
            }
        }
        if ($stale !== []) {
            DashboardPin::query()->whereKey($stale)->delete();
        }

        return $reports;
    }
}
