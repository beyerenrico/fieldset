<?php

declare(strict_types=1);

namespace FormSchema\Logic\Operators;

use FormSchema\Logic\Js;
use stdClass;

/**
 * var, missing, missing_some (README §4.3).
 */
final class VariableOperators extends EagerOperation
{
    public function names(): array
    {
        return ['var', 'missing', 'missing_some'];
    }

    protected function compute(string $operator, array $values, mixed $data): mixed
    {
        return match ($operator) {
            'var' => self::variable($values[0] ?? null, $values[1] ?? null, $data),
            'missing' => self::missing($values, $data),
            default => self::missingSome($values, $data),
        };
    }

    /**
     * Reads a dotted path; returns $fallback when the value is null or missing.
     */
    public static function variable(mixed $path, mixed $fallback, mixed $data): mixed
    {
        if ($path === null || $path === '') {
            return $data;
        }

        $current = $data;
        foreach (explode('.', Js::toJsString($path)) as $segment) {
            $current = self::child($current, $segment);

            if ($current === null) {
                return $fallback;
            }
        }

        return $current;
    }

    private static function child(mixed $value, string $segment): mixed
    {
        if ($value instanceof stdClass) {
            return property_exists($value, $segment) ? $value->{$segment} : null;
        }

        if (! is_array($value)) {
            return null;
        }

        if (! array_is_list($value)) {
            return $value[$segment] ?? null;
        }

        return preg_match('/^(0|[1-9]\d*)$/D', $segment) === 1 ? ($value[(int) $segment] ?? null) : null;
    }

    /**
     * @param  list<mixed>  $values
     * @return list<mixed>
     */
    private static function missing(array $values, mixed $data): array
    {
        $keys = isset($values[0]) && is_array($values[0]) ? $values[0] : $values;
        $missing = [];

        foreach ($keys as $key) {
            $value = self::variable($key, null, $data);

            if ($value === null || $value === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * @param  list<mixed>  $values
     * @return list<mixed>
     */
    private static function missingSome(array $values, mixed $data): array
    {
        $options = isset($values[1]) && is_array($values[1]) ? array_values($values[1]) : [];
        $missing = self::missing([$options], $data);
        $present = count($options) - count($missing);

        return $present >= Js::toNumber($values[0] ?? null) ? [] : $missing;
    }
}
