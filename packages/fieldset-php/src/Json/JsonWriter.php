<?php

declare(strict_types=1);

namespace Fieldset\Json;

use InvalidArgumentException;
use JsonSerializable;
use stdClass;

/**
 * Serializer shared by Json::encode() and Json::canonical().
 *
 * Non-finite floats are written as `null` (like JSON.stringify).
 */
final class JsonWriter
{
    private const STRING_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_LINE_TERMINATORS
        | JSON_THROW_ON_ERROR;

    public static function write(mixed $value, bool $sortKeys): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => JsNumber::toString($value),
            is_float($value) => is_finite($value) ? JsNumber::toString($value) : 'null',
            is_string($value) => self::string($value),
            is_array($value) => array_is_list($value)
                ? self::list($value, $sortKeys)
                : self::object($value, $sortKeys),
            $value instanceof stdClass => self::object(get_object_vars($value), $sortKeys),
            $value instanceof JsonSerializable => self::write($value->jsonSerialize(), $sortKeys),
            default => throw new InvalidArgumentException(
                'Value is not JSON-encodable: '.get_debug_type($value)
            ),
        };
    }

    public static function string(string $value): string
    {
        return json_encode($value, self::STRING_FLAGS);
    }

    /**
     * @param  list<mixed>  $items
     */
    private static function list(array $items, bool $sortKeys): string
    {
        $parts = array_map(static fn (mixed $item): string => self::write($item, $sortKeys), $items);

        return '['.implode(',', $parts).']';
    }

    /**
     * @param  array<int|string, mixed>  $properties
     */
    private static function object(array $properties, bool $sortKeys): string
    {
        $entries = [];
        foreach ($properties as $key => $item) {
            $entries[] = [(string) $key, $item];
        }

        if ($sortKeys) {
            usort($entries, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        }

        $parts = array_map(
            static fn (array $entry): string => self::string($entry[0]).':'.self::write($entry[1], $sortKeys),
            $entries,
        );

        return '{'.implode(',', $parts).'}';
    }
}
