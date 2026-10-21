<?php

declare(strict_types=1);

namespace Fieldset\Logic;

/**
 * Evaluates the JsonLogic dialect of Fieldset Spec 1.0 (README §4, §5.3).
 */
interface LogicEvaluator
{
    /**
     * Evaluation errors (an operator outside the dialect, nesting deeper than 512 levels) make the
     * whole expression null (spec §4.2).
     *
     * @param  mixed  $logic  a JsonLogic expression (JSON tree) or a literal
     * @param  mixed  $data  the scope object (JSON tree) `var` resolves against
     */
    public function evaluate(mixed $logic, mixed $data): mixed;
}
