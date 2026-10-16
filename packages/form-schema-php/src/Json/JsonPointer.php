<?php

declare(strict_types=1);

namespace FormSchema\Json;

use stdClass;

/**
 * RFC 6901 JSON Pointer helpers.
 */
final class JsonPointer
{
    public static function escape(string|int $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], (string) $segment);
    }

    public static function append(string $pointer, string|int $segment): string
    {
        return $pointer.'/'.self::escape($segment);
    }

    /**
     * @param  list<string|int>  $segments
     */
    public static function fromSegments(array $segments): string
    {
        return array_reduce(
            $segments,
            static fn (string $pointer, string|int $segment): string => self::append($pointer, $segment),
            '',
        );
    }

    /**
     * @return list<string>
     */
    public static function segments(string $pointer): array
    {
        if ($pointer === '') {
            return [];
        }

        $parts = explode('/', substr($pointer, 1));

        return array_map(static fn (string $part): string => str_replace(['~1', '~0'], ['/', '~'], $part), $parts);
    }

    /**
     * Resolves a pointer in a JSON tree.
     *
     * @return array{bool, mixed} [found, value]
     */
    public static function get(mixed $document, string $pointer): array
    {
        $current = $document;
        foreach (self::segments($pointer) as $segment) {
            if ($current instanceof stdClass && property_exists($current, $segment)) {
                $current = $current->{$segment};

                continue;
            }

            if (is_array($current) && preg_match('/^(0|[1-9]\d*)$/D', $segment) === 1
                && array_key_exists((int) $segment, $current)) {
                $current = $current[(int) $segment];

                continue;
            }

            return [false, null];
        }

        return [true, $current];
    }
}
