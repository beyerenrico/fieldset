<?php

declare(strict_types=1);

use App\Domain\Instance\Backup\BackupEntry;
use App\Domain\Instance\Backup\BackupName;
use App\Domain\Instance\Backup\BackupRetention;
use App\Domain\Instance\Backup\BackupSchedule;
use App\Domain\Instance\InstanceException;
use App\Domain\Platform\Instance\BackupAlerts;
use Carbon\CarbonImmutable;

/*
 * Retention, schedule and alerts of backups (E13, R39, R57, plan U16, ADR 0084): pure decisions, no Docker, no database.
 */

function backupEntry(string $name, int $bytes = 100, bool $kept = false): BackupEntry
{
    return new BackupEntry(BackupName::parse($name) ?? throw new RuntimeException("bad name {$name}"), $bytes, false, $kept);
}

/** @return list<string> */
function prunedNames(array $entries, int $keep): array
{
    return array_map(static fn (BackupEntry $entry): string => $entry->name->name(), BackupRetention::prune($entries, $keep));
}

it('keeps the newest scheduled backups and never prunes safety, manual or kept backups', function (): void {
    $entries = [];
    foreach (range(1, 9) as $day) {
        $entries[] = backupEntry(sprintf('scheduled-202610%02dT033000Z', $day));
    }
    $entries[] = backupEntry('manual-20261001T120000Z');
    $entries[] = backupEntry('safety-upgrade-20261002T120000Z');
    $entries[] = backupEntry('final-20261001T130000Z');
    $entries[] = backupEntry('scheduled-20260930T033000Z', kept: true);

    expect(prunedNames($entries, 7))->toBe(['scheduled-20261001T033000Z', 'scheduled-20261002T033000Z'])
        ->and(prunedNames($entries, 9))->toBe([])
        ->and(prunedNames($entries, 1))->toHaveCount(8)->not->toContain('scheduled-20261009T033000Z');
});

it('never prunes the newest backup, whatever its kind, and keeps at least one scheduled backup', function (): void {
    $entries = [backupEntry('scheduled-20261001T033000Z'), backupEntry('scheduled-20261002T033000Z'), backupEntry('manual-20261003T080000Z')];

    expect(prunedNames($entries, 0))->toBe(['scheduled-20261001T033000Z'])
        ->and(prunedNames([backupEntry('scheduled-20261001T033000Z')], 1))->toBe([]);
});

it('holds every scheduled backup that missed fewer referenced files than the newest one, or an unknown number, for as long as that lasts (M12 gate D5)', function (): void {
    $entries = [backupEntry('scheduled-20261005T033000Z'), backupEntry('scheduled-20261004T033000Z'), backupEntry('scheduled-20261003T033000Z'),
        backupEntry('scheduled-20261002T033000Z'), backupEntry('scheduled-20261001T033000Z'), backupEntry('manual-20261001T120000Z')];
    $missing = ['scheduled-20261004T033000Z' => 2, 'scheduled-20261003T033000Z' => 1, 'scheduled-20261002T033000Z' => 0, 'manual-20261001T120000Z' => 0];

    $held = BackupRetention::held($entries, 'scheduled-20261005T033000Z', 2, $missing);
    $pruned = array_map(static fn (BackupEntry $entry): string => $entry->name->name(), BackupRetention::prune($entries, 1, $held));

    expect($held)->toBe(['scheduled-20261003T033000Z', 'scheduled-20261002T033000Z', 'scheduled-20261001T033000Z']) // 1, 0, unknown
        ->and($pruned)->toBe(['scheduled-20261004T033000Z']) // as many missing as the newest: retention goes on there
        ->and(BackupRetention::held($entries, 'scheduled-20261005T033000Z', 0, $missing))->toBe([]) // the files are back: nothing held
        ->and(prunedNames($entries, 1))->toHaveCount(4);
});

it('projects the next backup from the newest one and keeps the free-space threshold', function (): void {
    $entries = [backupEntry('scheduled-20261001T033000Z', 1000), backupEntry('manual-20261002T080000Z', 4000)];

    expect(BackupRetention::projectedBytes($entries))->toBe(5000)
        ->and(BackupRetention::projectedBytes($entries, encrypted: true))->toBe(10000) // the plain set and its age file side by side
        ->and(BackupRetention::projectedBytes([]))->toBe(0)
        // the first backup: projected from the database and the storage volume, not from nothing
        ->and(BackupRetention::projectedBytes([], false, 8000))->toBe(10000)
        ->and(BackupRetention::projectedBytes([], true, 8000))->toBe(20000)
        ->and(BackupRetention::projectedBytes($entries, false, 10 ** 9))->toBe(5000)
        ->and(BackupRetention::fits(2 * 1024 ** 3, 5000))->toBeTrue()
        ->and(BackupRetention::fits(1024 ** 3, 5000))->toBeFalse()
        ->and(BackupRetention::fits(1024 ** 3 + 5000, 5000))->toBeTrue()
        ->and(BackupRetention::fits(10 * 1024 ** 2, 0, 5))->toBeTrue();
});

it('names backups by kind and UTC time and parses only valid names', function (): void {
    $name = BackupName::for(BackupName::SCHEDULED, CarbonImmutable::parse('2026-10-19T05:30:12.5+02:00'));
    $safety = BackupName::safety('upgrade', CarbonImmutable::parse('2026-10-19T03:30:00Z'));

    expect($name->name())->toBe('scheduled-20261019T033012Z')
        ->and($name->partial())->toBe('scheduled-20261019T033012Z.partial')
        ->and($name->next()->name())->toBe('scheduled-20261019T033013Z')
        ->and($name->isScheduled())->toBeTrue()->and($name->isSafety())->toBeFalse()
        ->and($safety->name())->toBe('safety-upgrade-20261019T033000Z')->and($safety->isSafety())->toBeTrue()
        ->and(BackupName::parse('final-20261019T033000Z')?->kind)->toBe('final') // uninstall's final backup is a 2.0 backup (M12 gate)
        ->and(BackupName::parse('scheduled-20261332T033000Z'))->toBeNull()
        ->and(BackupName::parse('scheduled-20261019T033000Z.partial'))->toBeNull()
        ->and(BackupName::parse('safety-install-20261019T033000Z'))->toBeNull()
        ->and(fn () => BackupName::for('nightly', CarbonImmutable::now()))->toThrow(InstanceException::class, 'Unknown backup kind');
});

it('runs daily at the slot in the instance time zone and catches up a missed slot after a restart', function (): void {
    $schedule = BackupSchedule::parse('03:30', 'Europe/Berlin');
    $now = CarbonImmutable::parse('2026-10-19T10:00:00Z');

    expect($schedule->slotBefore($now)->toIso8601ZuluString())->toBe('2026-10-19T01:30:00Z')
        ->and($schedule->slotAfter($now)->toIso8601ZuluString())->toBe('2026-10-20T01:30:00Z')
        // a fresh daemon without a success waits for the next slot
        ->and($schedule->nextRun($now, null, $now)?->toIso8601ZuluString())->toBe('2026-10-20T01:30:00Z')
        // the last success lies before today's slot: the slot was missed while the service was down
        ->and($schedule->nextRun($now, CarbonImmutable::parse('2026-10-18T01:30:05Z'), $now))->toEqual($now)
        // today's slot ran: tomorrow
        ->and($schedule->nextRun($now, CarbonImmutable::parse('2026-10-19T01:30:05Z'), $now)?->toIso8601ZuluString())->toBe('2026-10-20T01:30:00Z');
});

it('retries a minute after a skip and an hour after a failure, not before', function (): void {
    $schedule = BackupSchedule::parse('03:30', 'UTC');
    $now = CarbonImmutable::parse('2026-10-19T03:30:20Z');
    $success = CarbonImmutable::parse('2026-10-18T03:30:04Z');

    expect($schedule->nextRun($now, $success, $now, ['status' => 'skipped', 'at' => CarbonImmutable::parse('2026-10-19T03:30:00Z')])?->toIso8601ZuluString())->toBe('2026-10-19T03:31:00Z')
        ->and($schedule->nextRun($now, $success, $now, ['status' => 'failed', 'at' => CarbonImmutable::parse('2026-10-19T03:30:00Z')])?->toIso8601ZuluString())->toBe('2026-10-19T04:30:00Z')
        ->and($schedule->nextRun($now, $success, $now, ['status' => 'skipped', 'reason' => 'low_disk_space', 'at' => CarbonImmutable::parse('2026-10-19T03:30:00Z')])?->toIso8601ZuluString())->toBe('2026-10-19T04:30:00Z')
        ->and($schedule->nextRun($now->addHours(2), $success, $now, ['status' => 'failed', 'at' => CarbonImmutable::parse('2026-10-19T03:30:00Z')]))->toEqual($now->addHours(2))
        // an attempt older than the last success does not hold anything back
        ->and($schedule->nextRun($now, CarbonImmutable::parse('2026-10-19T03:30:10Z'), $now, ['status' => 'failed', 'at' => $success])?->toIso8601ZuluString())->toBe('2026-10-20T03:30:00Z');
});

it('knows `off` and refuses a malformed time or time zone', function (): void {
    $off = BackupSchedule::parse('OFF', 'UTC');

    expect($off->enabled())->toBeFalse()
        ->and($off->nextRun(CarbonImmutable::now(), null, CarbonImmutable::now()))->toBeNull()
        ->and(BackupSchedule::isTime('23:59'))->toBeTrue()->and(BackupSchedule::isTime('24:00'))->toBeFalse()
        ->and(BackupSchedule::isZone('Europe/Berlin'))->toBeTrue()->and(BackupSchedule::isZone('Mars/Olympus'))->toBeFalse()
        ->and(fn () => BackupSchedule::parse('3:30'))->toThrow(InvalidArgumentException::class, 'HH:MM')
        ->and(fn () => BackupSchedule::parse('03:30', 'Mars/Olympus'))->toThrow(InvalidArgumentException::class, 'time zone');
});

it('alerts on a failed run until a success, on lock skips older than a day, on a disk-space skip at once, and on a missed slot', function (): void {
    $now = CarbonImmutable::parse('2026-10-19T12:00:00Z');
    $daily = ['time' => '03:30', 'zone' => 'UTC'];
    $run = static fn (string $status, string $at, string $kind = 'scheduled', ?string $reason = null): array => ['kind' => $kind, 'status' => $status, 'started_at' => $at, 'reason' => $reason];
    $codes = static fn (array $runs, ?array $schedule = null, ?string $since = null, ?string $at = null): array => array_column(BackupAlerts::evaluate($runs, $at === null ? $now : CarbonImmutable::parse($at), $schedule, $since === null ? null : CarbonImmutable::parse($since)), 'code');

    expect($codes([$run('failed', '2026-10-19T03:30:00Z', reason: 'pg_dump_failed'), $run('succeeded', '2026-10-18T03:30:00Z')]))->toBe(['backup_failed'])
        ->and($codes([$run('skipped', '2026-10-19T04:00:00Z', reason: 'lock_held'), $run('failed', '2026-10-19T03:30:00Z')]))->toBe(['backup_failed'])
        ->and($codes([$run('succeeded', '2026-10-19T11:00:00Z', 'manual'), $run('failed', '2026-10-19T03:30:00Z')]))->toBe([])
        // skips because an operation holds the lock: only once they last a day (older ledger lines carry no reason)
        ->and($codes([$run('skipped', '2026-10-19T03:30:00Z', reason: 'lock_held'), $run('skipped', '2026-10-18T03:30:00Z', reason: 'lock_held'), $run('succeeded', '2026-10-17T03:30:00Z')]))->toBe(['backup_skipped'])
        ->and($codes([$run('skipped', '2026-10-19T03:30:00Z'), $run('skipped', '2026-10-18T03:30:00Z'), $run('succeeded', '2026-10-17T03:30:00Z')]))->toBe(['backup_skipped'])
        ->and($codes([$run('skipped', '2026-10-19T03:30:00Z', reason: 'lock_held'), $run('succeeded', '2026-10-18T03:30:00Z')]))->toBe([])
        ->and($codes([$run('skipped', '2026-10-18T03:30:00Z', 'manual', 'lock_held')]))->toBe([])
        // a skip for lack of disk space: at once, with its own code, never as a lock skip, until a run succeeds
        ->and($codes([$run('skipped', '2026-10-19T11:59:00Z', reason: 'low_disk_space'), $run('succeeded', '2026-10-18T03:30:00Z')]))->toBe(['backup_disk_space'])
        ->and($codes([$run('skipped', '2026-10-17T03:30:00Z', reason: 'low_disk_space'), $run('succeeded', '2026-10-16T03:30:00Z')]))->toBe(['backup_disk_space'])
        ->and($codes([$run('succeeded', '2026-10-19T11:00:00Z', 'manual'), $run('skipped', '2026-10-19T03:30:00Z', reason: 'low_disk_space')]))->toBe([])
        // overdue: two hours after the first slot following the last success
        ->and($codes([$run('succeeded', '2026-10-19T03:30:00Z')], $daily))->toBe([])
        ->and($codes([$run('succeeded', '2026-10-18T03:30:00Z')], $daily, at: '2026-10-19T05:29:00Z'))->toBe([])
        ->and($codes([$run('succeeded', '2026-10-18T03:30:00Z')], $daily, at: '2026-10-19T05:31:00Z'))->toBe(['backup_overdue'])
        ->and($codes([$run('succeeded', '2026-10-18T03:30:00Z')], ['time' => 'off', 'zone' => 'UTC']))->toBe([])
        ->and($codes([$run('succeeded', '2026-10-18T03:30:00Z')]))->toBe([])
        // without a success: following the service's start with this schedule (not the install), so a fresh or upgraded
        // instance gets its first slot first
        ->and($codes([], $daily, since: '2026-10-18T10:00:00Z', at: '2026-10-19T05:00:00Z'))->toBe([])
        ->and($codes([], $daily, since: '2026-10-18T10:00:00Z', at: '2026-10-19T05:31:00Z'))->toBe(['backup_overdue'])
        ->and($codes([], $daily, since: '2026-10-19T08:00:00Z'))->toBe([])
        ->and($codes([], $daily))->toBe([])
        ->and(BackupAlerts::time(['started_at' => 'not a time']))->toBeNull();
});

it('warns while the newest successful backup misses files the database references, until one has them all (M12 gate D5)', function (): void {
    $now = CarbonImmutable::parse('2026-10-19T12:00:00Z');
    $run = static fn (string $at, ?int $missing, string $status = 'succeeded'): array => ['kind' => 'scheduled', 'status' => $status, 'started_at' => $at, 'missing_files' => $missing];
    $alerts = static fn (array $runs): array => BackupAlerts::evaluate($runs, $now);

    expect(array_column($alerts([$run('2026-10-19T03:30:00Z', 4), $run('2026-10-18T03:30:00Z', 0)]), 'code'))->toBe(['backup_files_missing'])
        ->and($alerts([$run('2026-10-19T03:30:00Z', 4)])[0]['run']['missing_files'])->toBe(4)
        ->and(array_column($alerts([$run('2026-10-19T03:30:00Z', 0), $run('2026-10-18T03:30:00Z', 4)]), 'code'))->toBe([])
        ->and(array_column($alerts([$run('2026-10-19T04:00:00Z', null, 'failed'), $run('2026-10-19T03:30:00Z', 2)]), 'code'))->toBe(['backup_failed', 'backup_files_missing'])
        ->and(array_column($alerts([$run('2026-10-19T03:30:00Z', null)]), 'code'))->toBe([]);
});

it('tells the service\'s start records from runs: a failed start alerts, and restarts do not move the overdue reference', function (): void {
    $now = CarbonImmutable::parse('2026-10-19T12:00:00Z');
    $start = static fn (string $at, ?string $schedule = '03:30', string $status = 'started', ?string $reason = null): array => ['kind' => BackupAlerts::SERVICE, 'status' => $status, 'started_at' => $at, 'schedule' => $schedule, 'reason' => $reason];
    $starts = [$start('2026-10-19T09:00:00Z'), $start('2026-10-18T09:00:00Z'), $start('2026-10-17T09:00:00Z', 'off'), $start('2026-10-16T09:00:00Z')];
    $codes = static fn (array $runs, ?array $schedule = null): array => array_column(BackupAlerts::evaluate($runs, $now, $schedule, BackupAlerts::scheduledSince($runs)), 'code');

    expect(BackupAlerts::scheduledSince($starts)?->toIso8601ZuluString())->toBe('2026-10-18T09:00:00Z')
        ->and(BackupAlerts::scheduledSince([$start('2026-10-19T09:00:00Z', 'off'), ...$starts]))->toBeNull()
        ->and(BackupAlerts::scheduledSince([]))->toBeNull()
        ->and(BackupAlerts::jobs($starts))->toBe([])
        ->and(BackupAlerts::service([$start('2026-10-19T10:00:00Z', null, 'failed', 'invalid_schedule'), ...$starts])['reason'])->toBe('invalid_schedule')
        ->and($codes([$start('2026-10-19T10:00:00Z', null, 'failed', 'invalid_schedule'), ...$starts]))->toBe(['backup_service_failed'])
        ->and($codes([$start('2026-10-19T11:00:00Z'), $start('2026-10-19T10:00:00Z', null, 'failed', 'invalid_schedule')]))->toBe([])
        // restarted this morning, but running since the 18th without a success: the slot of the 19th was missed
        ->and($codes($starts, ['time' => '03:30', 'zone' => 'UTC']))->toBe(['backup_overdue'])
        ->and($codes([$start('2026-10-19T09:00:00Z')], ['time' => '03:30', 'zone' => 'UTC']))->toBe([]);
});

it('runs once on the nights the clock changes', function (string $from, string $until, string $slot): void {
    $schedule = BackupSchedule::parse('02:30', 'Europe/Berlin');
    $since = CarbonImmutable::parse($from);
    $success = $since;
    $runs = [];
    // the daemon's view, minute by minute: a run whenever one is due, recorded as the newest success
    for ($now = $since; $now->lessThan(CarbonImmutable::parse($until)); $now = $now->addMinute()) {
        $next = $schedule->nextRun($now, $success, $since);
        if ($next instanceof CarbonImmutable && $next->lessThanOrEqualTo($now)) {
            $runs[] = $now->toIso8601ZuluString();
            $success = $now;
        }
    }

    expect($runs)->toBe([$slot]);
})->with([
    // 02:30 happens twice when CEST ends; the slot is the second, 02:30 CET
    'clocks fall back' => ['2026-10-24T12:00:00Z', '2026-10-25T12:00:00Z', '2026-10-25T01:30:00Z'],
    // 02:30 does not exist when CEST begins; the slot is the moment after the gap, 03:30 CEST
    'clocks spring forward' => ['2026-03-28T12:00:00Z', '2026-03-29T12:00:00Z', '2026-03-29T01:30:00Z'],
]);
