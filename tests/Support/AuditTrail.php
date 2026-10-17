<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Audit\Models\Activity;
use Closure;

/**
 * Audit coverage helpers (§8.11 AC "every action produces exactly one entry"): run an action and get the
 * events it wrote, in order. Collects the raw JSON of every entry written, so a test can prove that no secret
 * ever reached the audit log.
 */
final class AuditTrail
{
    private static string $raw = '';

    public static function mark(): int
    {
        return (int) Activity::query()->max('id');
    }

    /** @return list<string> events written since the mark, oldest first */
    public static function since(int $mark): array
    {
        $entries = Activity::query()->where('id', '>', $mark)->orderBy('id')->get();
        self::$raw .= $entries->toJson();

        return array_values(array_map(static fn (mixed $event): string => (string) $event, $entries->pluck('event')->all()));
    }

    /** @return list<string> the events the action wrote */
    public static function of(Closure $action): array
    {
        $mark = self::mark();
        $action();

        return self::since($mark);
    }

    /** Raw JSON of every entry seen by since()/of() in this test. */
    public static function seen(): string
    {
        return self::$raw;
    }

    public static function reset(): void
    {
        self::$raw = '';
    }
}
