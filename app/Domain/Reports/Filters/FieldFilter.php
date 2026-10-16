<?php

declare(strict_types=1);

namespace App\Domain\Reports\Filters;

/**
 * A field-value filter (BUILD_PROMPT §8.4): `in` (select, radio, multiselect: any of the option values),
 * `is` (checkbox, toggle: true or false), `between` (date: Y-m-d days; number/money: decimal strings),
 * both ends inclusive and optional. Invalid input yields null from fromArray().
 */
final readonly class FieldFilter
{
    public const string IN = 'in';

    public const string IS = 'is';

    public const string BETWEEN = 'between';

    /** @param  list<string|bool|null>  $values */
    public function __construct(public string $key, public string $operator, public array $values) {}

    /** @param  array<array-key, mixed>  $data */
    public static function fromArray(array $data): ?self
    {
        $key = $data['key'] ?? null;
        $operator = $data['operator'] ?? null;
        $values = is_array($data['values'] ?? null) ? array_values($data['values']) : [];
        if (! is_string($key) || preg_match('/^[a-z][a-z0-9_.]{0,63}$/', $key) !== 1) {
            return null;
        }

        return match ($operator) {
            self::IN => self::in($key, $values),
            self::IS => isset($values[0]) && is_bool($values[0]) ? new self($key, self::IS, [$values[0]]) : null,
            self::BETWEEN => self::between($key, $values),
            default => null,
        };
    }

    /** @return array{key: string, operator: string, values: list<string|bool|null>} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'operator' => $this->operator, 'values' => $this->values];
    }

    public static function isDecimal(string $value): bool
    {
        return preg_match('/^-?\d{1,28}(\.\d{1,10})?$/', $value) === 1;
    }

    public static function isDay(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** @param  list<mixed>  $values */
    private static function in(string $key, array $values): ?self
    {
        $options = array_values(array_unique(array_map(strval(...), array_filter($values, is_scalar(...)))));

        return $options === [] ? null : new self($key, self::IN, array_slice($options, 0, 100));
    }

    /** @param  list<mixed>  $values */
    private static function between(string $key, array $values): ?self
    {
        $bound = static fn (mixed $v): ?string => is_string($v) && (self::isDecimal($v) || self::isDay($v)) ? $v : null;
        $from = $bound($values[0] ?? null);
        $until = $bound($values[1] ?? null);

        return $from === null && $until === null ? null : new self($key, self::BETWEEN, [$from, $until]);
    }
}
