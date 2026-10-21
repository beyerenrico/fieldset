<?php

declare(strict_types=1);

namespace Fieldset\Logic;

/**
 * Operator vocabulary of the dialect (README §4.2, §5.3), grouped for the linter.
 */
final class OperatorNames
{
    /** Operators whose second argument is evaluated per item. */
    public const ITERATION = ['map', 'filter', 'reduce', 'all', 'none', 'some'];

    /** Built-ins that MUST NOT see number/money values (lint `decimal_builtin_operator`). */
    public const DECIMAL_SENSITIVE_BUILTINS = ['+', '-', '*', '/', '%', '<', '<=', '>', '>=', '==', '!=', '===', '!=='];

    /** Operators whose operands follow the decimal operand rules (lint `float_literal`). */
    public const DECIMAL = [
        'dec.add', 'dec.sub', 'dec.mul', 'dec.div', 'dec.round',
        'dec.eq', 'dec.gt', 'dec.gte', 'dec.lt', 'dec.lte',
        'sum', 'min', 'max',
    ];

    /** Aggregates with an optional row key as second operand. */
    public const ROW_KEY_AGGREGATES = ['sum', 'min', 'max'];

    private const OTHERS = [
        'var', 'missing', 'missing_some', 'if', '?:', '==', '===', '!=', '!==', '!', '!!', 'or', 'and',
        '<', '<=', '>', '>=', 'in', 'cat', 'substr', 'merge', '+', '-', '*', '/', '%',
        'count', 'date.diff', 'date.add',
    ];

    public static function isSupported(string $operator): bool
    {
        return in_array($operator, self::OTHERS, true)
            || in_array($operator, self::ITERATION, true)
            || in_array($operator, self::DECIMAL, true);
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values(array_unique([...self::OTHERS, ...self::ITERATION, ...self::DECIMAL]));
    }
}
