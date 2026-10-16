<?php

declare(strict_types=1);

namespace FormSchema\Logic\Operators;

use Brick\Math\BigDecimal;
use FormSchema\Logic\Decimal;
use stdClass;

/**
 * sum, min, max (decimal, replacing the JsonLogic built-ins) and count (§5.3).
 */
final class AggregateOperators extends EagerOperation
{
    public function names(): array
    {
        return ['sum', 'min', 'max', 'count'];
    }

    protected function compute(string $operator, array $values, mixed $data): mixed
    {
        return match ($operator) {
            'sum' => self::sum($values),
            'count' => count(array_filter(self::items($values[0] ?? null), static fn (mixed $item): bool => $item !== null)),
            default => self::extreme($values, $operator === 'max' ? 1 : -1),
        };
    }

    /**
     * Decimal sum of the items (or of item[rowKey]), skipping null; "0" when empty.
     *
     * @param  list<mixed>  $values
     */
    private static function sum(array $values): string
    {
        $total = BigDecimal::zero();

        foreach (self::operands(self::items($values[0] ?? null), $values[1] ?? null) as $operand) {
            $total = $total->plus($operand);
        }

        return Decimal::format($total);
    }

    /**
     * `[array, rowKey?]` when the first operand is an array or the only operand, otherwise
     * `[a, b, …]`. The winner keeps its own scale; ties keep the first item.
     *
     * @param  list<mixed>  $values
     */
    private static function extreme(array $values, int $direction): ?string
    {
        $operands = is_array($values[0] ?? null) || count($values) === 1
            ? self::operands(self::items($values[0] ?? null), $values[1] ?? null)
            : self::operands($values, null);

        $best = null;
        foreach ($operands as $operand) {
            if ($best === null || $operand->compareTo($best) * $direction > 0) {
                $best = $operand;
            }
        }

        return $best === null ? null : Decimal::format($best);
    }

    /**
     * @return list<mixed>
     */
    private static function items(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * Decimal operands of the items, skipping anything that counts as null.
     *
     * @param  list<mixed>  $items
     * @return list<BigDecimal>
     */
    private static function operands(array $items, mixed $rowKey): array
    {
        $operands = [];

        foreach ($items as $item) {
            $value = is_string($rowKey) ? self::property($item, $rowKey) : $item;
            $decimal = Decimal::parse($value);

            if ($decimal !== null) {
                $operands[] = $decimal;
            }
        }

        return $operands;
    }

    private static function property(mixed $item, string $key): mixed
    {
        if ($item instanceof stdClass) {
            return property_exists($item, $key) ? $item->{$key} : null;
        }

        return is_array($item) && ! array_is_list($item) ? ($item[$key] ?? null) : null;
    }
}
