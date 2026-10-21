<?php

declare(strict_types=1);

namespace Fieldset\Logic\Operators;

use Fieldset\Logic\Js;
use Fieldset\Logic\LogicEvaluator;
use Fieldset\Logic\Operation;

/**
 * if / ?:, and, or, !, !! — `if`, `and`, `or` evaluate lazily.
 */
final class ControlOperators implements Operation
{
    public function names(): array
    {
        return ['if', '?:', 'and', 'or', '!', '!!'];
    }

    public function apply(string $operator, array $args, mixed $data, LogicEvaluator $logic): mixed
    {
        return match ($operator) {
            'if', '?:' => self::ifThenElse($args, $data, $logic),
            'and' => self::logical($args, $data, $logic, false),
            'or' => self::logical($args, $data, $logic, true),
            '!' => ! Js::truthy($logic->evaluate($args[0] ?? null, $data)),
            default => Js::truthy($logic->evaluate($args[0] ?? null, $data)),
        };
    }

    /**
     * @param  list<mixed>  $args
     */
    private static function ifThenElse(array $args, mixed $data, LogicEvaluator $logic): mixed
    {
        $count = count($args);

        for ($i = 0; $i < $count - 1; $i += 2) {
            if (Js::truthy($logic->evaluate($args[$i], $data))) {
                return $logic->evaluate($args[$i + 1], $data);
            }
        }

        return $count % 2 === 1 ? $logic->evaluate($args[$count - 1], $data) : null;
    }

    /**
     * Returns the deciding operand: the first truthy (or) / falsy (and) one, else the last.
     *
     * @param  list<mixed>  $args
     */
    private static function logical(array $args, mixed $data, LogicEvaluator $logic, bool $stopOn): mixed
    {
        $current = null;

        foreach ($args as $arg) {
            $current = $logic->evaluate($arg, $data);

            if (Js::truthy($current) === $stopOn) {
                return $current;
            }
        }

        return $current;
    }
}
