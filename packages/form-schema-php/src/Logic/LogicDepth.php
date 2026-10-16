<?php

declare(strict_types=1);

namespace FormSchema\Logic;

use stdClass;

/**
 * Nesting depth of a logic expression: objects and arrays each count one level, scalars none.
 */
final class LogicDepth
{
    /** Logic nested deeper than this is an evaluation error and a lint error (spec §4.2, §7). */
    public const LIMIT = 512;

    public static function exceeds(mixed $logic, int $limit = self::LIMIT): bool
    {
        return self::of($logic, $limit + 1) > $limit;
    }

    /**
     * Depth of the value, counting at most $cap levels (deeper branches stop early).
     */
    public static function of(mixed $value, int $cap = PHP_INT_MAX): int
    {
        if ($cap <= 0) {
            return 0;
        }

        $children = match (true) {
            $value instanceof stdClass => get_object_vars($value),
            is_array($value) => $value,
            default => null,
        };

        if ($children === null) {
            return 0;
        }

        $deepest = 0;
        foreach ($children as $child) {
            $deepest = max($deepest, self::of($child, $cap - 1));

            if ($deepest + 1 >= $cap) {
                break;
            }
        }

        return $deepest + 1;
    }
}
