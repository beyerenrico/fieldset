<?php

declare(strict_types=1);

namespace App\Domain\Documents\Designs;

/**
 * Compares two design settings snapshots setting by setting (§8.10 "diff between any two versions").
 * Nested settings (margins) are flattened to dotted names.
 */
final class DesignDiff
{
    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return list<array{setting: string, before: string, after: string}>
     */
    public static function compare(array $from, array $to): array
    {
        $before = self::flatten(DesignSettings::fromArray($from)->toArray());
        $after = self::flatten(DesignSettings::fromArray($to)->toArray());
        $changes = [];
        foreach ($after as $setting => $value) {
            if (($before[$setting] ?? null) !== $value) {
                $changes[] = ['setting' => $setting, 'before' => $before[$setting] ?? '', 'after' => $value];
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, string>
     */
    private static function flatten(array $settings, string $prefix = ''): array
    {
        $flat = [];
        foreach ($settings as $key => $value) {
            $flat = is_array($value)
                ? [...$flat, ...self::flatten($value, $prefix.$key.'.')]
                : [...$flat, $prefix.$key => self::display($value)];
        }

        return $flat;
    }

    private static function display(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => '',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }
}
