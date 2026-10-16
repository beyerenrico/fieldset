<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Reports\Models\SavedReport;
use App\Domain\Reports\Saved\SaveReport;
use App\Domain\Reports\Scheduling\ScheduledReportMail;
use App\Domain\Reports\Scheduling\ScheduledReports;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Reports\ReportsScenario;

/* M5-T03 scheduled report emails: weekly/monthly in the organization time zone, CSV per recipient. */

beforeEach(function (): void {
    Mail::fake();
    config(['mail.from.address' => 'noreply@forms.example']);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 08:00', 'UTC'));   // a Tuesday
    $this->s = ReportsScenario::make();   // organization time zone: Europe/Berlin
    $this->reviewer = memberOf($this->s->org, SystemRole::Reviewer);
    $this->s->group('Crew', $this->reviewer, $this->s->worker);
    $this->s->submit($this->s->worker, ['amount' => '10.10', 'kind' => 'repair'], submittedAt: '2026-10-06 10:00');   // week 41
    $this->s->submit($this->s->owner, ['amount' => '0.20', 'kind' => 'repair'], submittedAt: '2026-10-07 10:00');
    $this->s->submit($this->s->owner, ['amount' => '99', 'kind' => 'repair'], submittedAt: '2026-10-13 10:00');      // week 42
});

/** @param  array<string, mixed>  $data */
function scheduledReport(ReportsScenario $s, User $actor, array $data): SavedReport
{
    actingInAdmin($actor, $s->org);

    return app(SaveReport::class)->handle($actor, null, ['form_id' => $s->form->id, 'name' => 'Weekly revenue', 'metric' => 'sum',
        'metric_key' => 'amount', 'visibility' => 'organization', 'schedule' => 'weekly', ...$data]);
}

function sendDue(string $localTime): int
{
    return app(ScheduledReports::class)->sendDue(CarbonImmutable::parse($localTime, 'Europe/Berlin'));
}

it('mails the previous week on Monday 07:00 local time, each recipient with the numbers they may see', function (): void {
    $s = $this->s;
    $report = scheduledReport($s, $s->owner, ['recipients' => [$s->owner->id, $this->reviewer->id, $s->worker->id]]);

    expect($report->recipients)->toBe([$s->owner->id, $this->reviewer->id])   // field worker: no reports.view
        ->and(sendDue('2026-10-12 06:59'))->toBe(0);
    app(TenantContext::class)->clear();
    expect(sendDue('2026-10-12 07:05'))->toBe(2)
        ->and(sendDue('2026-10-12 08:05'))->toBe(0);   // once per period

    Mail::assertSent(ScheduledReportMail::class, 2);
    Mail::assertSent(ScheduledReportMail::class, fn (ScheduledReportMail $m): bool => $m->hasTo($s->owner->email)
        && str_contains($m->csv, 'Total,2,10.3') && $m->filename === 'weekly-revenue-2026-10-05.csv'
        && str_contains($m->localizedSubject(), 'Weekly revenue'));
    Mail::assertSent(ScheduledReportMail::class, fn (ScheduledReportMail $m): bool => $m->hasTo($this->reviewer->email)
        && str_contains($m->csv, 'Total,1,10.1'));
    expect(MailMessage::query()->withoutTenant()->count())->toBe(2)
        ->and($report->refresh()->last_sent_at)->not->toBeNull();
});

it('mails monthly reports on the 1st and waits a period for reports created after the due time', function (): void {
    $s = $this->s;
    $report = scheduledReport($s, $s->owner, ['schedule' => 'monthly', 'recipients' => [$s->owner->id]]);
    app(TenantContext::class)->clear();

    expect(sendDue('2026-10-20 12:00'))->toBe(0)       // created after the 1 October due time
        ->and(sendDue('2026-11-01 07:30'))->toBe(1);

    Mail::assertSent(ScheduledReportMail::class, fn (ScheduledReportMail $m): bool => str_contains($m->csv, 'Total,3,109.3')
        && $m->filename === 'weekly-revenue-2026-10-01.csv');
    expect($report->refresh()->schedule->value)->toBe('monthly');
});

it('covers the mail period even when the report has a relative period (review H3)', function (): void {
    $s = $this->s;
    $lastSeven = scheduledReport($s, $s->owner, ['name' => 'Last seven', 'filters' => ['period' => 'last_7_days'], 'recipients' => [$s->owner->id]]);
    $thisWeek = scheduledReport($s, $s->owner, ['name' => 'This week', 'filters' => ['period' => 'this_week'], 'recipients' => [$s->owner->id]]);
    $thisMonth = scheduledReport($s, $s->owner, ['name' => 'This month', 'schedule' => 'monthly', 'filters' => ['period' => 'this_month'], 'recipients' => [$s->owner->id]]);
    app(TenantContext::class)->clear();

    expect(sendDue('2026-10-12 07:05'))->toBe(2)       // weekly: the previous Monday–Sunday (week 41)
        ->and(sendDue('2026-11-01 07:30'))->toBe(3);   // monthly: October (+ the weekly ones for the week of 26 October)

    foreach (['last-seven', 'this-week'] as $slug) {
        Mail::assertSent(ScheduledReportMail::class, fn (ScheduledReportMail $m): bool => str_starts_with($m->filename, $slug.'-2026-10-05')
            && str_contains($m->csv, 'Total,2,10.3'));
    }
    Mail::assertSent(ScheduledReportMail::class, fn (ScheduledReportMail $m): bool => str_starts_with($m->filename, 'this-month-2026-10-01')
        && str_contains($m->csv, 'Total,3,109.3'));
    expect([$lastSeven->refresh()->filters['period'], $thisWeek->refresh()->filters['period'], $thisMonth->refresh()->filters['period']])
        ->toBe(['last_7_days', 'this_week', 'this_month']);   // the report itself keeps its period
});

it('does not mail an old period right after a schedule is switched on (review L2)', function (): void {
    $s = $this->s;
    $this->travelTo(CarbonImmutable::parse('2026-09-01 08:00', 'UTC'));
    $report = scheduledReport($s, $s->owner, ['schedule' => 'none', 'recipients' => [$s->owner->id]]);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'UTC'));   // Tuesday; the latest weekly due time was Monday 07:00
    $report = app(SaveReport::class)->handle($s->owner, $report, [...$report->only(['form_id', 'name', 'metric', 'metric_key', 'visibility']),
        'metric' => 'sum', 'schedule' => 'weekly', 'recipients' => [$s->owner->id]]);
    app(TenantContext::class)->clear();

    expect($report->last_sent_at?->toIso8601String())->toBe('2026-10-06T10:00:00+00:00')
        ->and(sendDue('2026-10-06 12:30'))->toBe(0)
        ->and(sendDue('2026-10-12 07:05'))->toBe(1);
});

it('sends private reports only to their creator', function (): void {
    $report = scheduledReport($this->s, $this->s->owner, ['visibility' => 'private', 'recipients' => [$this->s->owner->id, $this->reviewer->id]]);

    expect($report->recipients)->toBe([$this->s->owner->id]);
});

it('registers the hourly command', function (): void {
    $this->artisan('reports:send-scheduled')->assertSuccessful();
});
