<?php

declare(strict_types=1);

namespace FormSchema\Logic\Operators;

use Closure;
use FormSchema\Logic\Js;

/**
 * ==, ===, !=, !==, <, <=, >, >= (README §4.1).
 */
final class ComparisonOperators extends EagerOperation
{
    public function names(): array
    {
        return ['==', '===', '!=', '!==', '<', '<=', '>', '>='];
    }

    protected function compute(string $operator, array $values, mixed $data): mixed
    {
        return match ($operator) {
            '==' => self::loose($values),
            '!=' => ! self::loose($values),
            '===' => self::strict($values),
            '!==' => ! self::strict($values),
            '<' => self::relational($values, static fn (int $c): bool => $c < 0, true),
            '<=' => self::relational($values, static fn (int $c): bool => $c <= 0, true),
            '>' => self::relational($values, static fn (int $c): bool => $c > 0, false),
            default => self::relational($values, static fn (int $c): bool => $c >= 0, false),
        };
    }

    /**
     * A missing operand behaves like `undefined`, which equals nothing.
     *
     * @param  list<mixed>  $values
     */
    private static function loose(array $values): bool
    {
        return count($values) >= 2 && Js::looseEquals($values[0], $values[1]);
    }

    /**
     * @param  list<mixed>  $values
     */
    private static function strict(array $values): bool
    {
        return count($values) >= 2 && Js::strictEquals($values[0], $values[1]);
    }

    /**
     * @param  list<mixed>  $values
     * @param  Closure(int): bool  $test
     */
    private static function relational(array $values, Closure $test, bool $allowBetween): bool
    {
        if (count($values) < 2) {
            return false;
        }

        $first = self::holds($values[0], $values[1], $test);

        if ($allowBetween && count($values) >= 3) {
            return $first && self::holds($values[1], $values[2], $test);
        }

        return $first;
    }

    /**
     * @param  Closure(int): bool  $test
     */
    private static function holds(mixed $a, mixed $b, Closure $test): bool
    {
        $comparison = Js::compare($a, $b);

        return $comparison !== null && $test($comparison);
    }
}
