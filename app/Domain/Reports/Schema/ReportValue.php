<?php

declare(strict_types=1);

namespace App\Domain\Reports\Schema;

use App\Domain\Submissions\Admin\ValueFormatter;
use stdClass;

/**
 * Turns a projected `submission_values.value_text` back into the submission value and formats it for
 * the explorer (locale display) or an export (exact, machine-friendly). Number and money values stay
 * decimal strings throughout (§7.4, §11): never cast to float.
 */
final readonly class ReportValue
{
    private const array JSON_TYPES = ['multiselect', 'location', 'signature', 'photo', 'file'];

    public static function decode(ReportField $field, ?string $text): mixed
    {
        if ($text === null) {
            return null;
        }
        if ($field->isBoolean()) {
            return $text === 'true';
        }
        if (in_array($field->type, self::JSON_TYPES, true)) {
            $decoded = json_decode($text);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $text;
        }

        return $text;
    }

    /**
     * Explorer cell text in the viewer's locale and time zone. Money uses the currency of the submission's
     * version; choices use the option labels of all versions (FieldCatalog merges them).
     */
    public static function display(ReportField $field, ?string $text, ValueFormatter $formatter, ?int $versionId = null): string
    {
        $value = self::decode($field, $text);

        return match (true) {
            $value === null => ValueFormatter::EMPTY,
            in_array($field->type, ['photo', 'file'], true) => trans_choice('reports.value.files', self::count($value)),
            $field->type === 'signature' => self::signer($value),
            in_array($field->type, ['select', 'radio', 'multiselect'], true) => self::choices($field, $value),
            default => $formatter->format($field->elementFor($versionId), $value),
        };
    }

    /**
     * Export cell: decimal strings for numbers and money (as stored), true/false for booleans, option
     * labels for choices, "lat, lng" for locations, the signer for signatures, a count for files.
     */
    public static function export(ReportField $field, ?string $text): string|bool|int|null
    {
        $value = self::decode($field, $text);

        return match (true) {
            $value === null => null,
            is_bool($value) => $value,
            in_array($field->type, ['select', 'radio', 'multiselect'], true) => self::choices($field, $value),
            in_array($field->type, ['photo', 'file'], true) => self::count($value),
            $field->type === 'signature' => self::signer($value),
            $field->type === 'location' && $value instanceof stdClass => self::scalar($value->lat ?? '').', '.self::scalar($value->lng ?? ''),
            default => self::scalar($value),
        };
    }

    /** Option labels (all versions) of a choice value; unknown values as they are. */
    private static function choices(ReportField $field, mixed $value): string
    {
        $options = $field->options();

        return implode(', ', array_map(
            static fn (mixed $v): string => $options[self::scalar($v)] ?? self::scalar($v),
            is_array($value) ? $value : [$value],
        ));
    }

    private static function count(mixed $value): int
    {
        return is_array($value) ? count($value) : 1;
    }

    private static function signer(mixed $value): string
    {
        $name = $value instanceof stdClass && is_string($value->signerName ?? null) ? trim($value->signerName) : '';

        return $name !== '' ? $name : (string) __('reports.value.signed');
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }
}
