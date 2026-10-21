<?php

declare(strict_types=1);

namespace Fieldset\Logic\Operators;

use Fieldset\Logic\LogicEvaluator;
use Fieldset\Logic\Operation;

/**
 * Base for operators whose arguments are all evaluated before the operator runs.
 */
abstract class EagerOperation implements Operation
{
    final public function apply(string $operator, array $args, mixed $data, LogicEvaluator $logic): mixed
    {
        $values = array_map(static fn (mixed $arg): mixed => $logic->evaluate($arg, $data), $args);

        return $this->compute($operator, $values, $data);
    }

    /**
     * @param  list<mixed>  $values  evaluated arguments
     */
    abstract protected function compute(string $operator, array $values, mixed $data): mixed;
}
