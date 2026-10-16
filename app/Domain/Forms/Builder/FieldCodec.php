<?php

declare(strict_types=1);

namespace App\Domain\Forms\Builder;

use FormSchema\Json\Json;
use JsonException;
use stdClass;

/**
 * Converts single definition values to builder field values and back (per FieldSpec kind).
 * `fromUi` returns JsonPath::ABSENT for "leave the property out".
 */
final readonly class FieldCodec
{
    public function __construct(private LogicCompiler $logic, private CalculationCompiler $calc) {}

    public function toUi(FieldSpec $spec, mixed $value): mixed
    {
        $absent = $value === JsonPath::ABSENT;

        return match ($spec->kind) {
            FieldSpec::BOOL => $value === true,
            FieldSpec::INT => is_int($value) || (is_float($value) && floor($value) === $value) ? (int) $value : null,
            FieldSpec::NUMERIC => $absent ? null : (is_string($value) ? $value : Json::encode($value)),
            FieldSpec::OPTIONS => is_array($value) ? array_map(self::optionToUi(...), array_values($value)) : [],
            FieldSpec::LOGIC => $this->logic->toUi($absent ? null : $value),
            FieldSpec::CALC => $this->calc->toUi($absent ? null : $value),
            FieldSpec::DEFAULT => $absent ? null : self::defaultToUi($value),
            FieldSpec::LIST => is_array($value) ? array_values(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $value)) : [],
            default => is_string($value) ? $value : (is_scalar($value) ? (string) $value : null),
        };
    }

    public function fromUi(FieldSpec $spec, mixed $ui): mixed
    {
        $result = match ($spec->kind) {
            FieldSpec::BOOL => $ui === true || $ui === 1 || $ui === '1' ? true : null,
            FieldSpec::INT => is_numeric($ui) && preg_match('/^-?\d+$/', (string) $ui) === 1 ? (int) $ui : null,
            FieldSpec::NUMERIC => self::numericFromUi($ui),
            FieldSpec::OPTIONS => self::optionsFromUi($ui),
            FieldSpec::LOGIC => $this->logic->fromUi(is_array($ui) ? $ui : null),
            FieldSpec::CALC => $this->calc->fromUi(is_array($ui) ? $ui : null),
            FieldSpec::DEFAULT => self::defaultFromUi($ui),
            FieldSpec::LIST => is_array($ui) ? array_values(array_filter(array_map('strval', $ui), static fn (string $v): bool => $v !== '')) : null,
            default => is_string($ui) && $ui !== '' ? $ui : null,
        };

        return $result === null || $result === [] ? JsonPath::ABSENT : $result;
    }

    /** Loose comparison of builder values: empty values are equal, numbers compare as strings. */
    public static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value === null, $value === '', $value === [], $value === JsonPath::ABSENT => null,
            is_int($value), is_float($value) => (string) $value,
            is_array($value) => array_map(self::normalize(...), $value),
            default => $value,
        };
    }

    /** @return array{_source: string, value: string, label: string} */
    private static function optionToUi(mixed $option): array
    {
        $source = $option instanceof stdClass ? clone $option : new stdClass;

        return [
            '_source' => Json::encode($source),
            'value' => is_scalar($source->value ?? null) ? (string) $source->value : '',
            'label' => is_scalar($source->label ?? null) ? (string) $source->label : '',
        ];
    }

    /** @return list<stdClass>|null */
    private static function optionsFromUi(mixed $ui): ?array
    {
        if (! is_array($ui)) {
            return null;
        }
        $options = [];
        foreach (array_values($ui) as $row) {
            if (! is_array($row) || ! is_scalar($row['value'] ?? null) || (string) $row['value'] === '') {
                continue;
            }
            $source = self::decodeObject($row['_source'] ?? null);
            $source->value = (string) $row['value'];
            $source->label = is_scalar($row['label'] ?? null) && (string) $row['label'] !== '' ? (string) $row['label'] : (string) $row['value'];
            $options[] = $source;
        }

        return $options;
    }

    private static function numericFromUi(mixed $ui): int|string|null
    {
        $text = is_scalar($ui) ? trim((string) $ui) : '';
        if ($text === '') {
            return null;
        }

        return preg_match('/^-?(0|[1-9]\d{0,14})$/', $text) === 1 ? (int) $text : $text;
    }

    private static function defaultToUi(mixed $value): string
    {
        $isPlain = is_string($value) && ! in_array(substr($value, 0, 1), ['{', '[', '"'], true);

        return $isPlain ? $value : Json::encode($value);
    }

    private static function defaultFromUi(mixed $ui): mixed
    {
        $text = is_scalar($ui) ? trim((string) $ui) : '';
        if ($text === '') {
            return null;
        }
        if (! in_array($text[0], ['{', '[', '"'], true)) {
            return $text;
        }
        try {
            return Json::decode($text);
        } catch (JsonException) {
            return $text;
        }
    }

    public static function decodeObject(mixed $json): stdClass
    {
        if (! is_string($json) || $json === '') {
            return new stdClass;
        }
        try {
            $decoded = Json::decode($json);
        } catch (JsonException) {
            return new stdClass;
        }

        return $decoded instanceof stdClass ? $decoded : new stdClass;
    }
}
