<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * When the backup service runs (E13, KTD15, ADR 0084): daily at `HH:MM` in the instance's time zone (default 03:30;
 * `off` disables scheduled runs). The next run follows from the last success, so a restart loses nothing:
 *
 * - due now when the last success (or, without one, the daemon's start) lies before the most recent slot, i.e. a slot
 *   was missed while the service was down; otherwise at the next slot;
 * - after a run that did not succeed it waits: a minute after a skip because an operation held the instance lock (the
 *   operation usually finishes soon, and only the first skip of a slot is recorded), an hour after a failure.
 */
final readonly class BackupSchedule
{
    public const string DEFAULT_TIME = '03:30';

    public const string OFF = 'off';

    public const int LOCK_RETRY_SECONDS = 60;

    public const int FAILURE_RETRY_SECONDS = 3600;

    private const string TIME = '/^([01]\d|2[0-3]):([0-5]\d)$/D';

    private function __construct(public ?string $time, public DateTimeZone $zone) {}

    /** @throws InvalidArgumentException for a time that is not HH:MM (24 h) or `off`, or an unknown time zone */
    public static function parse(string $value, string $zone = 'UTC'): self
    {
        $value = strtolower(trim($value));
        if ($value !== self::OFF && preg_match(self::TIME, $value) !== 1) {
            throw new InvalidArgumentException('The backup schedule must be a time of day as HH:MM (24 hours) or `off`.');
        }
        if (! self::isZone($zone)) {
            throw new InvalidArgumentException("Unknown time zone [{$zone}].");
        }

        return new self($value === self::OFF ? null : $value, new DateTimeZone($zone));
    }

    public static function isTime(string $value): bool
    {
        return preg_match(self::TIME, $value) === 1;
    }

    public static function isZone(string $zone): bool
    {
        return in_array($zone, DateTimeZone::listIdentifiers(), true);
    }

    public function enabled(): bool
    {
        return $this->time !== null;
    }

    /** The most recent slot at or before `$now` (UTC). */
    public function slotBefore(CarbonImmutable $now): CarbonImmutable
    {
        $slot = $this->slotOn($now);

        return $slot->greaterThan($now) ? $this->slotOn($now->setTimezone($this->zone)->subDay()) : $slot;
    }

    /** The first slot after `$now` (UTC). */
    public function slotAfter(CarbonImmutable $now): CarbonImmutable
    {
        $slot = $this->slotOn($now);

        return $slot->greaterThan($now) ? $slot : $this->slotOn($now->setTimezone($this->zone)->addDay());
    }

    /**
     * @param  CarbonImmutable|null  $lastSuccess  the newest successful scheduled run
     * @param  CarbonImmutable  $since  when the daemon started (the reference without a success)
     * @param  array{status: string, at: CarbonImmutable}|null  $lastAttempt  the newest run after the last success that did not succeed
     */
    public function nextRun(CarbonImmutable $now, ?CarbonImmutable $lastSuccess, CarbonImmutable $since, ?array $lastAttempt = null): ?CarbonImmutable
    {
        if (! $this->enabled()) {
            return null;
        }
        $reference = $lastSuccess ?? $since;
        $due = $reference->lessThan($this->slotBefore($now)) ? $now : $this->slotAfter($now);
        if ($lastAttempt === null || ($lastSuccess instanceof CarbonImmutable && $lastAttempt['at']->lessThanOrEqualTo($lastSuccess))) {
            return $due;
        }
        $wait = $lastAttempt['status'] === 'skipped' ? self::LOCK_RETRY_SECONDS : self::FAILURE_RETRY_SECONDS;
        $retry = $lastAttempt['at']->addSeconds($wait);

        return $retry->greaterThan($due) ? $retry : $due;
    }

    private function slotOn(CarbonImmutable $day): CarbonImmutable
    {
        [$hour, $minute] = array_map(intval(...), explode(':', (string) $this->time));

        return $day->setTimezone($this->zone)->setTime($hour, $minute)->utc();
    }
}
