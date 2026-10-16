<?php

declare(strict_types=1);

namespace FormSchema\Logic\Operators;

use FormSchema\Logic\Js;

/**
 * + - * / % on IEEE-754 doubles (README §4.2).
 *
 * Operands are converted like relational operands (Js::toNumber). Results are floats; NaN and
 * infinities are not JSON and become null, -0 becomes 0.
 */
final class ArithmeticOperators extends EagerOperation
{
    public function names(): array
    {
        return ['+', '-', '*', '/', '%'];
    }

    protected function compute(string $operator, array $values, mixed $data): mixed
    {
        $numbers = array_map(Js::toNumber(...), $values);

        return match ($operator) {
            '+' => self::finite(array_sum($numbers)),
            '*' => $numbers === [] ? null : self::finite(array_product($numbers)),
            '-' => self::difference($numbers),
            '/' => self::finite(fdiv($numbers[0] ?? NAN, $numbers[1] ?? NAN)),
            default => self::finite(fmod($numbers[0] ?? NAN, $numbers[1] ?? NAN)),
        };
    }

    /**
     * @param  list<float>  $numbers
     */
    private static function difference(array $numbers): ?float
    {
        if ($numbers === []) {
            return null;
        }

        return self::finite(count($numbers) === 1 ? -$numbers[0] : $numbers[0] - $numbers[1]);
    }

    private static function finite(int|float $value): ?float
    {
        if (! is_finite($value)) {
            return null;
        }

        return $value === 0.0 || $value === 0 ? 0.0 : (float) $value;
    }
}
