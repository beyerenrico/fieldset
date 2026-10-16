<?php

declare(strict_types=1);

namespace App\Domain\Reports\Scheduling;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Actions\QueueMail;
use App\Domain\Notifications\Delivery\NotificationRenderer;
use App\Domain\Reports\Enums\ReportMetric;
use App\Domain\Reports\Enums\ReportSchedule;
use App\Domain\Reports\Models\SavedReport;
use App\Domain\Reports\Saved\ReportCsv;
use App\Domain\Reports\Saved\ReportResult;
use App\Domain\Reports\SavedReportQuery;
use App\Domain\Submissions\Admin\ValueFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends due scheduled reports (`reports:send-scheduled`, hourly). Per organization (time zone) and
 * recipient: each recipient gets the numbers THEY may see (SavedReportQuery as that user) for the past
 * period, in their locale, as a CSV attachment. The mail period replaces a relative period of the report
 * (an absolute from/until range still applies). A report is due once per period; missed runs catch up
 * on the next run, and a report created (or switched on, SaveReport sets last_sent_at) after the due
 * moment waits for the next period.
 */
final readonly class ScheduledReports
{
    public function __construct(
        private TenantContext $tenant,
        private SavedReportQuery $query,
        private QueueMail $mail,
        private NotificationRenderer $renderer,
    ) {}

    /** @return int emails queued */
    public function sendDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $due = SavedReport::query()->withoutTenant()->where('schedule', '!=', ReportSchedule::None->value)
            ->orderBy('id')->get(['id', 'organization_id']);

        $sent = 0;
        foreach ($due->groupBy('organization_id') as $organizationId => $reports) {
            $sent += $this->tenant->run((int) $organizationId, function () use ($reports, $now): int {
                $count = 0;
                foreach (SavedReport::query()->with('form')->whereIn('id', $reports->pluck('id'))->get() as $report) {
                    try {
                        $count += $this->sendIfDue($report, $now);
                    } catch (Throwable $e) {
                        report($e); // one broken report must not stop the others
                    }
                }

                return $count;
            });
        }

        return $sent;
    }

    /** Inside the report's tenant. @return int emails queued */
    public function sendIfDue(SavedReport $report, CarbonImmutable $now): int
    {
        $period = SchedulePeriod::latest($report->schedule, $now, SavedReportQuery::timezone($report));
        $baseline = $report->last_sent_at ?? $report->created_at;
        if (! $period instanceof SchedulePeriod || $baseline->greaterThanOrEqualTo($period->dueAt)) {
            return 0;
        }

        $organization = Organization::query()->findOrFail($report->organization_id);
        $sent = 0;
        // Marked sent first: a failing recipient must not make the next run mail the others again.
        $report->forceFill(['last_sent_at' => $now])->save();
        foreach (User::query()->whereIn('id', $report->recipientIds())->get() as $recipient) {
            if (! $recipient->isActive() || ! $recipient->isMemberOf($organization) || Gate::forUser($recipient)->denies('view', $report)) {
                continue;
            }
            try {
                $this->send($report, $recipient, $organization, $period);
                $sent++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    private function send(SavedReport $report, User $recipient, Organization $organization, SchedulePeriod $period): void
    {
        $locale = $this->renderer->locale($recipient, $organization);
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            // The mail covers its own period: a relative period of the report (this week, last 7 days…) would
            // be evaluated at send time and miss the past period, so it is replaced (ADR 0033 amendment).
            $result = $this->query->run($report, $period->range, $recipient, periodFromRange: true);
            $tz = SavedReportQuery::timezone($report);
            $label = $period->range->from->setTimezone($tz)->settings(['locale' => $locale])->isoFormat('L').' – '
                .$period->range->until->setTimezone($tz)->subDay()->settings(['locale' => $locale])->isoFormat('L');
            $mail = new ScheduledReportMail($organization->id, [
                'report.name' => $report->name, 'report.form' => $report->form->name, 'report.period' => $label,
                'report.summary' => $this->summary($result, new ValueFormatter($locale, $tz)),
            ], ReportCsv::make($result), Str::slug($report->name ?: 'report').'-'.$period->range->from->setTimezone($tz)->format('Y-m-d').'.csv', $locale);
        } finally {
            app()->setLocale($previous);
        }

        $this->mail->handle($mail, $recipient->email, $recipient->name);
    }

    /** Total line plus the result's notes (groups cut off, mixed currencies). */
    private function summary(ReportResult $result, ValueFormatter $formatter): string
    {
        $summary = $result->metric === ReportMetric::Count
            ? trans_choice('reports.mail.summary_count', $result->total->submissions, ['submissions' => $result->total->submissions])
            : trans_choice('reports.mail.summary', $result->total->submissions, [
                'submissions' => $result->total->submissions, 'metric' => $result->metric->label().' '.$result->metricLabel,
                'value' => $result->displayValue($result->total, $formatter),
            ]);

        return implode("\n\n", [$summary, ...ReportCsv::notes($result)]);
    }
}
