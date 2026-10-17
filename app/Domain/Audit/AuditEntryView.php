<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Audit\Models\Activity;

/**
 * Flat, readable views of an audit entry's before/after diff and properties for the audit log UI: one row
 * per attribute ("old → new") and per property (scalars as they are, structures as JSON). The stored values
 * were redacted when the entry was written.
 */
final class AuditEntryView
{
    /** @return array<string, string> */
    public static function changes(Activity $activity): array
    {
        $changes = $activity->attribute_changes?->all() ?? [];
        $new = is_array($changes['attributes'] ?? null) ? $changes['attributes'] : [];
        $old = is_array($changes['old'] ?? null) ? $changes['old'] : [];
        $rows = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            $rows[(string) $key] = array_key_exists($key, $old)
                ? self::value($old[$key]).' → '.self::value($new[$key] ?? null)
                : self::value($new[$key] ?? null);
        }

        return $rows;
    }

    /** @return array<string, string> */
    public static function properties(Activity $activity): array
    {
        $rows = [];
        foreach ($activity->properties?->all() ?? [] as $key => $value) {
            $rows[(string) $key] = self::value($value);
        }

        return $rows;
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }
}
