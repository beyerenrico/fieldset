<?php

declare(strict_types=1);

namespace Fieldset\Logic;

use Fieldset\Logic\Operators\AggregateOperators;
use Fieldset\Logic\Operators\ArithmeticOperators;
use Fieldset\Logic\Operators\ComparisonOperators;
use Fieldset\Logic\Operators\ControlOperators;
use Fieldset\Logic\Operators\DateOperators;
use Fieldset\Logic\Operators\DecimalOperators;
use Fieldset\Logic\Operators\IterationOperators;
use Fieldset\Logic\Operators\StringArrayOperators;
use Fieldset\Logic\Operators\VariableOperators;
use stdClass;

/**
 * Evaluator for the JsonLogic dialect of Fieldset Spec 1.0 (README §4, §5.3; ADR 0021).
 */
final class JsonLogic implements LogicEvaluator
{
    /** Nesting limit (spec §4.2): deeper expressions are evaluation errors. */
    public const MAX_DEPTH = LogicDepth::LIMIT;

    /** @var array<string, Operation> */
    private readonly array $operations;

    /** True while an outermost evaluation is running (nested calls come from operators). */
    private bool $running = false;

    public function __construct()
    {
        $families = [
            new VariableOperators,
            new ControlOperators,
            new ComparisonOperators,
            new StringArrayOperators,
            new IterationOperators,
            new ArithmeticOperators,
            new DecimalOperators,
            new AggregateOperators,
            new DateOperators,
        ];

        $operations = [];
        foreach ($families as $family) {
            foreach ($family->names() as $name) {
                $operations[$name] = $family;
            }
        }

        $this->operations = $operations;
    }

    /**
     * Evaluates an expression; an evaluation error (unknown operator, nesting deeper than 512
     * levels) makes the whole expression null (spec §4.2).
     */
    public function evaluate(mixed $logic, mixed $data): mixed
    {
        if ($this->running) {
            return $this->evaluateNode($logic, $data);
        }

        try {
            return $this->evaluateStrict($logic, $data);
        } catch (LogicError) {
            return null;
        }
    }

    /**
     * Like evaluate(), but throws the LogicError instead of returning null.
     *
     * @throws LogicError
     */
    public function evaluateStrict(mixed $logic, mixed $data): mixed
    {
        if ($this->running) {
            return $this->evaluateNode($logic, $data);
        }

        if (LogicDepth::exceeds($logic, self::MAX_DEPTH)) {
            throw new NestingTooDeepException(self::MAX_DEPTH);
        }

        $this->running = true;

        try {
            return $this->evaluateNode($logic, $data);
        } finally {
            $this->running = false;
        }
    }

    private function evaluateNode(mixed $logic, mixed $data): mixed
    {
        if (is_array($logic)) {
            return array_map(fn (mixed $item): mixed => $this->evaluate($item, $data), array_values($logic));
        }

        if (! self::isOperation($logic)) {
            return $logic;
        }

        [$operator, $args] = self::split($logic);
        $operation = $this->operations[$operator] ?? throw new UnknownOperatorException($operator);

        return $operation->apply($operator, $args, $data, $this);
    }

    /**
     * True for an object with exactly one property, which JsonLogic treats as an operation.
     *
     * @phpstan-assert-if-true stdClass $logic
     */
    public static function isOperation(mixed $logic): bool
    {
        return $logic instanceof stdClass && count(get_object_vars($logic)) === 1;
    }

    /**
     * Splits an operation into its operator and argument list (a non-list argument is wrapped).
     *
     * @return array{string, list<mixed>}
     */
    public static function split(stdClass $operation): array
    {
        $properties = get_object_vars($operation);
        $operator = (string) array_key_first($properties);
        $args = $properties[$operator] ?? null;

        if (is_array($args) && array_is_list($args)) {
            return [$operator, $args];
        }

        return [$operator, [$args]];
    }
}
