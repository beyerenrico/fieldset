<?php

declare(strict_types=1);

namespace FormSchema\Logic;

/**
 * A family of JsonLogic operators.
 */
interface Operation
{
    /**
     * @return list<string> operator names handled by this family
     */
    public function names(): array;

    /**
     * @param  list<mixed>  $args  raw (unevaluated) arguments
     */
    public function apply(string $operator, array $args, mixed $data, LogicEvaluator $logic): mixed;
}
