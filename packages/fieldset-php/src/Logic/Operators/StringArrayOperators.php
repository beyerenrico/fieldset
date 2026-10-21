<?php

declare(strict_types=1);

namespace Fieldset\Logic\Operators;

use Fieldset\Logic\Js;
use Fieldset\Support\Text;

/**
 * in, cat, substr, merge.
 */
final class StringArrayOperators extends EagerOperation
{
    public function names(): array
    {
        return ['in', 'cat', 'substr', 'merge'];
    }

    protected function compute(string $operator, array $values, mixed $data): mixed
    {
        return match ($operator) {
            'in' => self::in($values[0] ?? null, $values[1] ?? null),
            'cat' => implode('', array_map(Js::catString(...), $values)),
            'substr' => self::substr($values),
            default => self::merge($values),
        };
    }

    private static function in(mixed $needle, mixed $haystack): bool
    {
        if (is_string($haystack)) {
            return str_contains($haystack, Js::toJsString($needle));
        }

        if (! is_array($haystack)) {
            return false;
        }

        foreach ($haystack as $item) {
            if (Js::strictEquals($item, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * JavaScript String.prototype.substr on code points, with JsonLogic's negative length rule.
     *
     * @param  list<mixed>  $values
     */
    private static function substr(array $values): string
    {
        $chars = Text::codePoints(Js::catString($values[0] ?? null));
        $tail = self::slice($chars, self::toInteger($values[1] ?? null), null);

        if (! array_key_exists(2, $values)) {
            return implode('', $tail);
        }

        $length = self::toInteger($values[2]);

        if ($length < 0) {
            return implode('', self::slice($tail, 0, max(count($tail) + $length, 0)));
        }

        return implode('', self::slice($tail, 0, $length));
    }

    /**
     * @param  list<string>  $chars
     * @return list<string>
     */
    private static function slice(array $chars, int $start, ?int $length): array
    {
        $size = count($chars);
        $from = $start < 0 ? max($size + $start, 0) : min($start, $size);

        return array_slice($chars, $from, $length);
    }

    private static function toInteger(mixed $value): int
    {
        $number = Js::toNumber($value);

        if (! is_finite($number)) {
            return 0;
        }

        $clamped = max(min($number, (float) PHP_INT_MAX / 2), (float) PHP_INT_MIN / 2);

        return (int) ($clamped < 0 ? ceil($clamped) : floor($clamped));
    }

    /**
     * Flattens one level: array arguments are spread, other values appended.
     *
     * @param  list<mixed>  $values
     * @return list<mixed>
     */
    private static function merge(array $values): array
    {
        $merged = [];

        foreach ($values as $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $merged[] = $item;
                }

                continue;
            }

            $merged[] = $value;
        }

        return $merged;
    }
}
