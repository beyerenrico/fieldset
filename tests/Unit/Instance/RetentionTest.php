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

it('projects the next backup from the newest one and keeps the free-space threshold', function (): void {
    $entries = [backupEntry('scheduled-20261001T033000Z', 1000), backupEntry('manual-20261002T080000Z', 4000)];

    expect(BackupRetention::projectedBytes($entries))->toBe(5000)
        ->and(BackupRetention::projectedBytes($entries, encrypted: true))->toBe(10000) // the plain set and its age file side by side
        ->and(BackupRetention::projectedBytes([]))->toBe(0)
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
        ->and(BackupName::parse('final-20261019T033000Z'))->toBeNull()
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

it('alerts on a failed run until a success, on skips older than a day, and on an overdue schedule', function (): void {
    $now = CarbonImmutable::parse('2026-10-19T12:00:00Z');
    $run = static fn (string $status, string $at, string $kind = 'scheduled', ?string $reason = null): array => ['kind' => $kind, 'status' => $status, 'started_at' => $at, 'reason' => $reason];
    $codes = static fn (array $runs, bool $scheduled = false, ?CarbonImmutable $since = null): array => array_column(BackupAlerts::evaluate($runs, $now, $scheduled, $since), 'code');

    expect($codes([$run('failed', '2026-10-19T03:30:00Z', reason: 'pg_dump_failed'), $run('succeeded', '2026-10-18T03:30:00Z')]))->toBe(['backup_failed'])
        ->and($codes([$run('skipped', '2026-10-19T04:00:00Z'), $run('failed', '2026-10-19T03:30:00Z')]))->toBe(['backup_failed'])
        ->and($codes([$run('succeeded', '2026-10-19T11:00:00Z', 'manual'), $run('failed', '2026-10-19T03:30:00Z')]))->toBe([])
        ->and($codes([$run('skipped', '2026-10-19T03:30:00Z'), $run('skipped', '2026-10-18T03:30:00Z'), $run('succeeded', '2026-10-17T03:30:00Z')]))->toBe(['backup_skipped'])
        ->and($codes([$run('skipped', '2026-10-19T03:30:00Z'), $run('succeeded', '2026-10-18T03:30:00Z')]))->toBe([])
        ->and($codes([$run('skipped', '2026-10-18T03:30:00Z', 'manual')]))->toBe([])
        ->and($codes([$run('succeeded', '2026-10-18T09:00:00Z')], scheduled: true))->toBe(['backup_overdue'])
        ->and($codes([$run('succeeded', '2026-10-18T11:00:00Z')], scheduled: true))->toBe([])
        ->and($codes([], scheduled: true, since: CarbonImmutable::parse('2026-10-17T12:00:00Z')))->toBe(['backup_overdue'])
        ->and($codes([], scheduled: true, since: CarbonImmutable::parse('2026-10-19T08:00:00Z')))->toBe([])
        ->and($codes([], scheduled: false, since: CarbonImmutable::parse('2026-10-01T00:00:00Z')))->toBe([])
        ->and(BackupAlerts::time(['started_at' => 'not a time']))->toBeNull();
});
