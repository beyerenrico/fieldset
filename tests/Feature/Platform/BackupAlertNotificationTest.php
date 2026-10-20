<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Platform\Actions\GrantPlatformAdmin;
use App\Domain\Platform\Instance\BackupAlertNotification;
use App\Domain\Platform\Instance\BackupAlertNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/Support.php';

/*
 * R39 (plan U20, ADR 0089): failed or stale backups notify the platform operators, once per incident, by mail in their
 * locale with the `fsa` command and a link to the instance page, which keeps the warning while mail is captured. The
 * alerts are the ones `fsa status` reports as `warn` (BackupAlerts over `backup_runs`).
 */

/** @param  array<string, mixed>  $row */
function alertRun(array $row): void
{
    DB::table('backup_runs')->insert([
        'kind' => 'scheduled', 'status' => 'succeeded', 'started_at' => now(), 'finished_at' => now(), 'backup' => 'scheduled-20261019T033000Z',
        'bytes' => 2048, 'encrypted' => false, ...$row,
    ]);
}

function alertFailure(string $at, string $reason = 'pg_dump_failed'): void
{
    alertRun(['kind' => 'manual', 'status' => 'failed', 'reason' => $reason, 'backup' => null, 'bytes' => null, 'started_at' => CarbonImmutable::parse($at)]);
}

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00:00Z'));
});

it('mails every active operator once per failed backup, and again for a new failure after a success', function (): void {
    $olga = platformOperator();
    $otto = platformOperator('Otto Operator');
    $inactive = platformOperator('Ida Inactive');
    $inactive->forceFill(['status' => 'deactivated'])->save();
    $owner = ownerOf(makeOrganization());
    alertFailure('2026-10-19T09:00:00Z');

    $first = app(BackupAlertNotifier::class)->notify();
    $again = app(BackupAlertNotifier::class)->notify();
    alertRun(['started_at' => CarbonImmutable::parse('2026-10-19T10:00:00Z')]);
    $recovered = app(BackupAlertNotifier::class)->notify();
    alertFailure('2026-10-19T11:00:00Z', 'storage_failed');
    $second = app(BackupAlertNotifier::class)->notify();

    expect([$first, $again, $recovered, $second])->toBe([['backup_failed'], [], [], ['backup_failed']]);
    Notification::assertSentToTimes($olga, BackupAlertNotification::class, 2);
    Notification::assertSentToTimes($otto, BackupAlertNotification::class, 2);
    Notification::assertNotSentTo([$inactive, $owner], BackupAlertNotification::class);
    Notification::assertSentTo($olga, BackupAlertNotification::class, static fn (BackupAlertNotification $n): bool => $n->code === 'backup_failed' && $n->reason === 'storage_failed' && $n->command === 'fsa backup');
});

it('notifies a stale backup (none within two hours of the slot) once while it lasts', function (): void {
    $olga = platformOperator();
    alertRun(['kind' => 'service', 'status' => 'started', 'schedule' => '03:30', 'time_zone' => 'UTC', 'backup' => null, 'bytes' => null, 'started_at' => CarbonImmutable::parse('2026-10-17T09:00:00Z')]);
    alertRun(['started_at' => CarbonImmutable::parse('2026-10-18T03:30:00Z')]);

    $before = app(BackupAlertNotifier::class)->notify(); // the 2026-10-19 03:30 slot is overdue since 05:30
    $this->travelTo(CarbonImmutable::parse('2026-10-19T13:00:00Z'));
    $later = app(BackupAlertNotifier::class)->notify();

    expect([$before, $later])->toBe([['backup_overdue'], []]);
    Notification::assertSentTo($olga, BackupAlertNotification::class, static fn (BackupAlertNotification $n): bool => $n->code === 'backup_overdue' && $n->command === 'fsa doctor --post');
});

it('sends nothing without an alert, and remembers nothing without an operator', function (): void {
    alertRun(['started_at' => CarbonImmutable::parse('2026-10-19T03:30:00Z')]);
    expect(app(BackupAlertNotifier::class)->notify())->toBe([]);

    alertFailure('2026-10-19T09:00:00Z');
    $nobody = app(BackupAlertNotifier::class)->notify();
    $user = memberOf(makeOrganization(), SystemRole::Viewer);
    app(GrantPlatformAdmin::class)->handle($user);
    $afterGrant = app(BackupAlertNotifier::class)->notify();

    expect($nobody)->toBe([])->and($afterGrant)->toBe(['backup_failed']);
    Notification::assertSentToTimes($user, BackupAlertNotification::class, 1);
});

it('remembers no incident when no operator mail could be queued, so the next run tries again', function (): void {
    $olga = platformOperator();
    alertFailure('2026-10-19T09:00:00Z');
    $fake = Notification::getFacadeRoot();
    Notification::swap(new class implements Dispatcher
    {
        public function send($notifiables, $notification): void
        {
            throw new RuntimeException('queue unavailable');
        }

        public function sendNow($notifiables, $notification, ?array $channels = null): void
        {
            throw new RuntimeException('queue unavailable');
        }
    });

    $failed = app(BackupAlertNotifier::class)->notify();
    Notification::swap($fake);
    $retried = app(BackupAlertNotifier::class)->notify();

    expect([$failed, $retried])->toBe([[], ['backup_failed']]);
    Notification::assertSentToTimes($olga, BackupAlertNotification::class, 1);
});

it('sends nothing on an instance without backup runs', function (): void {
    platformOperator();
    config(['instance.runtime_path' => '/run/fsa']);

    expect(app(BackupAlertNotifier::class)->notify())->toBe([]);
    Notification::assertNothingSent();
});

it('writes the mail in the operator\'s locale with the command and the instance page link', function (string $locale, string $subject, string $text): void {
    $operator = platformOperator();
    $operator->forceFill(['locale' => $locale])->save();
    alertFailure('2026-10-19T09:00:00Z', 'pg_dump_failed');

    app(BackupAlertNotifier::class)->notify();

    Notification::assertSentTo($operator, BackupAlertNotification::class, function (BackupAlertNotification $notification) use ($operator, $locale, $subject, $text): bool {
        expect($notification->locale)->toBe($locale);
        app()->setLocale($locale);
        $mail = $notification->toMail($operator);
        $rendered = implode("\n", [...$mail->introLines, ...$mail->outroLines]);

        return $mail->subject === $subject && str_contains($rendered, $text) && str_contains($rendered, 'fsa backup') && str_contains($rendered, 'pg_dump_failed')
            && $mail->actionUrl === route('filament.platform.pages.instance') && $mail->level === 'error';
    });
})->with([
    'English' => ['en', 'Backup problem on your form-schema-app instance', 'until the next backup succeeds'],
    'German' => ['de', 'Backup-Problem auf Ihrer form-schema-app-Instanz', 'bis das nächste Backup gelingt'],
]);

it('runs hourly from the scheduler', function (): void {
    $event = collect(app(Schedule::class)->events())->first(static fn (Event $event): bool => str_contains((string) $event->command, 'instance:backup-alerts'));

    expect($event)->toBeInstanceOf(Event::class)->and($event?->expression)->toBe('0 * * * *');
    $this->artisan('instance:backup-alerts')->expectsOutputToContain('No new backup alert.')->assertSuccessful();
});
