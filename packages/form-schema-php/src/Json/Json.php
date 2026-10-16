<?php

declare(strict_types=1);

namespace FormSchema\Json;

use JsonException;
use stdClass;

/**
 * JSON helpers for the tree representation used throughout the SDK.
 *
 * Objects are stdClass, arrays are PHP lists, numbers are int|float — exactly what
 * json_decode($json, false) produces. This keeps `{}` and `[]` apart.
 */
final class Json
{
    /** Maximum nesting of objects and arrays accepted by decode() (spec §4.2). */
    public const MAX_DEPTH = 1024;

    /**
     * Decodes JSON into a JSON tree; documents nested deeper than MAX_DEPTH objects/arrays are
     * rejected. json_decode() counts the innermost value as a level, hence MAX_DEPTH + 1.
     *
     * @throws JsonException for invalid JSON or deeper nesting
     */
    public static function decode(string $json): mixed
    {
        return json_decode($json, false, self::MAX_DEPTH + 1, JSON_THROW_ON_ERROR);
    }

    /**
     * Compact JSON with unescaped slashes and unicode, numbers in shortest round-trip form.
     */
    public static function encode(mixed $value): string
    {
        return JsonWriter::write($value, false);
    }

    /**
     * Canonical JSON (spec §6): keys sorted by code point, no whitespace, `/` and non-ASCII
     * unescaped, numbers in shortest round-trip form (integral floats without fraction).
     */
    public static function canonical(mixed $value): string
    {
        return JsonWriter::write($value, true);
    }

    /**
     * Lowercase hex SHA-256 of the canonical JSON.
     */
    public static function checksum(mixed $value): string
    {
        return hash('sha256', self::canonical($value));
    }

    /**
     * Deep copy of a JSON tree, so callers can never share mutable stdClass instances.
     */
    public static function copy(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $copy = new stdClass;
            foreach (get_object_vars($value) as $key => $item) {
                $copy->{(string) $key} = self::copy($item);
            }

            return $copy;
        }

        if (is_array($value)) {
            return array_map(self::copy(...), $value);
        }

        return $value;
    }

    /**
     * True for a JSON array (a PHP list).
     *
     * @phpstan-assert-if-true list<mixed> $value
     */
    public static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }
}
