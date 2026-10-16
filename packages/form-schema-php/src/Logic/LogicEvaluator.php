<?php

declare(strict_types=1);

namespace FormSchema\Logic;

/**
 * Evaluates the JsonLogic dialect of Form Schema Spec 1.0 (README §4, §5.3).
 */
interface LogicEvaluator
{
    /**
     * @param  mixed  $logic  a JsonLogic expression (JSON tree) or a literal
     * @param  mixed  $data  the scope object (JSON tree) `var` resolves against
     *
     * @throws LogicError for an operator outside the dialect or nesting deeper than 512 levels
     */
    public function evaluate(mixed $logic, mixed $data): mixed;
}
