<?php

declare(strict_types=1);

namespace FormSchema\Logic\Operators;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Closure;
use FormSchema\Logic\Decimal;

/**
 * dec.add, dec.sub, dec.mul, dec.div, dec.round and the decimal comparisons (§5.3).
 */
final class DecimalOperators extends EagerOperation
{
    public function names(): array
    {
        return ['dec.add', 'dec.sub', 'dec.mul', 'dec.div', 'dec.round', 'dec.eq', 'dec.gt', 'dec.gte', 'dec.lt', 'dec.lte'];
    }

    protected function compute(string $operator, array $values, mixed $data): mixed
    {
        return match ($operator) {
            'dec.add' => self::fold($values, static fn (BigDecimal $a, BigDecimal $b): BigDecimal => $a->plus($b)),
            'dec.sub' => self::fold($values, static fn (BigDecimal $a, BigDecimal $b): BigDecimal => $a->minus($b)),
            'dec.mul' => self::fold($values, static fn (BigDecimal $a, BigDecimal $b): BigDecimal => $a->multipliedBy($b)),
            'dec.div' => self::divide($values[0] ?? null, $values[1] ?? null, $values[2] ?? null),
            'dec.round' => self::round($values[0] ?? null, $values[1] ?? null),
            default => self::comparison($operator, $values[0] ?? null, $values[1] ?? null),
        };
    }

    /**
     * Exact left fold; brick/math keeps max(scale) for plus/minus and sum(scales) for multiplication.
     *
     * @param  list<mixed>  $values
     * @param  Closure(BigDecimal, BigDecimal): BigDecimal  $step
     */
    private static function fold(array $values, Closure $step): ?string
    {
        $operands = Decimal::parseAll($values);

        if ($operands === null) {
            return null;
        }

        $result = $operands[0];
        foreach (array_slice($operands, 1) as $operand) {
            $result = $step($result, $operand);
        }

        return Decimal::format($result);
    }

    private static function divide(mixed $a, mixed $b, mixed $scale): ?string
    {
        $dividend = Decimal::parse($a);
        $divisor = Decimal::parse($b);
        $targetScale = Decimal::scaleOperand($scale);

        if ($dividend === null || $divisor === null || $targetScale === null || $divisor->isZero()) {
            return null;
        }

        return Decimal::format($dividend->dividedBy($divisor, $targetScale, RoundingMode::HalfUp));
    }

    private static function round(mixed $value, mixed $scale): ?string
    {
        $decimal = Decimal::parse($value);
        $targetScale = Decimal::scaleOperand($scale);

        if ($decimal === null || $targetScale === null) {
            return null;
        }

        return Decimal::format(Decimal::toScale($decimal, $targetScale));
    }

    private static function comparison(string $operator, mixed $a, mixed $b): bool
    {
        $comparison = Decimal::compare($a, $b);

        if ($comparison === null) {
            return false;
        }

        return match ($operator) {
            'dec.eq' => $comparison === 0,
            'dec.gt' => $comparison > 0,
            'dec.gte' => $comparison >= 0,
            'dec.lt' => $comparison < 0,
            default => $comparison <= 0,
        };
    }
}
