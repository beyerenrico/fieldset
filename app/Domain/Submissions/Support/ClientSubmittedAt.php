<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Support;

use Illuminate\Support\Carbon;

/**
 * `client_submitted_at` of a submit (M6, ADR 0035, QUESTIONS #14): when the user tapped "Submit" on the
 * device, which may be long before an offline queue reaches the server. An ISO 8601 date-time with an
 * offset (`Z` or `±hh:mm`, optional fraction); at most MAX_SKEW_SECONDS ahead of the server clock, any
 * age is fine. Informational only: number, period, submitted_at and `$today` use server time.
 */
final class ClientSubmittedAt
{
    /** Tolerated clock skew of a device ahead of the server. */
    public const int MAX_SKEW_SECONDS = 300;

    /** Earliest plausible device time; older values (year 1 with an offset) overflow the database column. */
    public const string EARLIEST = '2000-01-01T00:00:00Z';

    public const string PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/';

    /**
     * Validation rules; failures render as `validation_failed` with pointer `/client_submitted_at` and the
     * codes `string`, `regex`, `date`, `after_or_equal` (before EARLIEST) or `before_or_equal` (too far in
     * the future).
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        return [
            'bail', 'nullable', 'string', 'regex:'.self::PATTERN, 'date', 'after_or_equal:'.self::EARLIEST,
            'before_or_equal:'.Carbon::now()->addSeconds(self::MAX_SKEW_SECONDS)->toIso8601String(),
        ];
    }

    /** The validated value as an instant (null when absent). */
    public static function parse(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value)->utc() : null;
    }
}
