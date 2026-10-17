<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Audit\Models\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Readable rows of an audit entry for the audit log UI: keys labelled (`audit.attributes.*`, headline as the
 * fallback), attribute diffs and `before`/`after` properties as "old → new" per key, Yes/No for booleans, ISO
 * times as `L LT` in the given (viewer's) time zone, and "hidden (secret)" for values the Redactor masked when the
 * entry was written. The operator of an impersonation is shown separately (AuditLabels::impersonator).
 */
final class AuditEntryView
{
    private const string ISO_TIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/';

    /** @return array<string, string> */
    public static function changes(Activity $activity, string $timezone): array
    {
        $changes = $activity->attribute_changes?->all() ?? [];
        $new = is_array($changes['attributes'] ?? null) ? $changes['attributes'] : [];
        $old = is_array($changes['old'] ?? null) ? $changes['old'] : [];
        $rows = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            $rows = self::add($rows, $key, array_key_exists($key, $old)
                ? self::value($old[$key], $timezone).' → '.self::value($new[$key] ?? null, $timezone)
                : self::value($new[$key] ?? null, $timezone));
        }

        return $rows;
    }

    /** @return array<string, string> */
    public static function properties(Activity $activity, string $timezone): array
    {
        $properties = $activity->properties?->except(AuditImpersonator::PROPERTY)->all() ?? [];
        $rows = [];
        if (array_key_exists('before', $properties) && array_key_exists('after', $properties)) {
            $rows = self::beforeAfter($properties['before'], $properties['after'], $timezone);
            unset($properties['before'], $properties['after']);
        }
        foreach ($properties as $key => $value) {
            $rows = self::add($rows, $key, self::value($value, $timezone));
        }

        return $rows;
    }

    /** The label of an attribute or property key. */
    public static function label(int|string $key): string
    {
        $translationKey = 'audit.attributes.'.$key;
        $label = __($translationKey);

        return is_string($label) && $label !== $translationKey ? $label : Str::headline((string) $key);
    }

    /**
     * One row per key when both sides are records (a missing side is "—"), else one "Change" row.
     *
     * @return array<string, string>
     */
    private static function beforeAfter(mixed $before, mixed $after, string $timezone): array
    {
        $record = static fn (mixed $side): bool => $side === null || (is_array($side) && ! array_is_list($side));
        if (! $record($before) || ! $record($after) || ($before === null && $after === null)) {
            return [(string) __('audit.fields.change') => self::value($before, $timezone).' → '.self::value($after, $timezone)];
        }
        $before = is_array($before) ? $before : [];
        $after = is_array($after) ? $after : [];
        $rows = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $rows = self::add($rows, $key, self::value($before[$key] ?? null, $timezone).' → '.self::value($after[$key] ?? null, $timezone));
        }

        return $rows;
    }

    /**
     * The rows with one more labelled row; a label already taken keeps its row and the new one names its key.
     *
     * @param  array<string, string>  $rows
     * @return array<string, string>
     */
    private static function add(array $rows, int|string $key, string $value): array
    {
        $label = self::label($key);
        if (array_key_exists($label, $rows)) {
            $label .= ' ('.$key.')';
        }

        return array_replace($rows, [$label => $value]);
    }

    private static function value(mixed $value, string $timezone): string
    {
        return match (true) {
            $value === null, $value === [] => '—',
            is_bool($value) => (string) __($value ? 'audit.values.yes' : 'audit.values.no'),
            $value === Redactor::MASK => (string) __('audit.values.hidden'),
            is_string($value) && preg_match(self::ISO_TIME, $value) === 1 => self::time($value, $timezone),
            is_scalar($value) => (string) $value,
            is_array($value) && array_is_list($value) => implode(', ', array_map(static fn (mixed $v): string => self::value($v, $timezone), $value)),
            is_array($value) => self::record($value, $timezone),
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }

    /** @param  array<array-key, mixed>  $value */
    private static function record(array $value, string $timezone): string
    {
        $parts = [];
        foreach ($value as $key => $item) {
            $parts[] = self::label($key).': '.self::value($item, $timezone);
        }

        return implode('; ', $parts);
    }

    private static function time(string $value, string $timezone): string
    {
        try {
            return CarbonImmutable::parse($value)->setTimezone($timezone)->settings(['locale' => app()->getLocale()])->isoFormat('L LT');
        } catch (Throwable) {
            return $value;
        }
    }
}
