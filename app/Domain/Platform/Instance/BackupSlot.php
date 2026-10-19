<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance;

use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The daily slots of a backup schedule (`HH:MM` in a time zone; ADR 0084), shared by the backup service (BackupSchedule)
 * and the alerts on `/platform` and in `fsa status` (BackupAlerts). One slot per calendar day of the zone: on the night
 * the clock falls back, the ambiguous time is its first occurrence; on the night it springs forward, a missing time is
 * the moment after the gap (PHP's DateTime rules).
 */
final readonly class BackupSlot
{
    /**
     * The slot on the zone's calendar day of `$day` (UTC), parsed from that date and the time, so it does not depend on
     * the moment it is computed from (setTime keeps the moment's own offset, which gave two slots on the night the clock
     * falls back).
     */
    public static function on(string $time, DateTimeZone $zone, CarbonImmutable $day): CarbonImmutable
    {
        $slot = CarbonImmutable::createFromFormat('!Y-m-d H:i', $day->setTimezone($zone)->format('Y-m-d').' '.$time, $zone);
        if (! $slot instanceof CarbonImmutable) {
            throw new InvalidArgumentException("Not a backup time: [{$time}].");
        }

        return $slot->utc();
    }

    /** The most recent slot at or before `$moment` (UTC). */
    public static function before(string $time, DateTimeZone $zone, CarbonImmutable $moment): CarbonImmutable
    {
        $slot = self::on($time, $zone, $moment);

        return $slot->greaterThan($moment) ? self::on($time, $zone, $moment->setTimezone($zone)->subDay()) : $slot;
    }

    /** The first slot after `$moment` (UTC). */
    public static function after(string $time, DateTimeZone $zone, CarbonImmutable $moment): CarbonImmutable
    {
        $slot = self::on($time, $zone, $moment);

        return $slot->greaterThan($moment) ? $slot : self::on($time, $zone, $moment->setTimezone($zone)->addDay());
    }
}
