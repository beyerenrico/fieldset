<?php

declare(strict_types=1);

namespace FormSchema\Logic;

use FormSchema\Logic\Operators\AggregateOperators;
use FormSchema\Logic\Operators\ArithmeticOperators;
use FormSchema\Logic\Operators\ComparisonOperators;
use FormSchema\Logic\Operators\ControlOperators;
use FormSchema\Logic\Operators\DateOperators;
use FormSchema\Logic\Operators\DecimalOperators;
use FormSchema\Logic\Operators\IterationOperators;
use FormSchema\Logic\Operators\StringArrayOperators;
use FormSchema\Logic\Operators\VariableOperators;
use stdClass;

/**
 * Evaluator for the JsonLogic dialect of Form Schema Spec 1.0 (README §4, §5.3; ADR 0021).
 */
final class JsonLogic implements LogicEvaluator
{
    /** Nesting limit; deeper expressions throw NestingTooDeepException instead of overflowing. */
    public const MAX_DEPTH = 512;

    /** @var array<string, Operation> */
    private readonly array $operations;

    /** Current recursion depth of evaluate(). */
    private int $depth = 0;

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

    public function evaluate(mixed $logic, mixed $data): mixed
    {
        if ($this->depth >= self::MAX_DEPTH) {
            throw new NestingTooDeepException(self::MAX_DEPTH);
        }

        $this->depth++;

        try {
            return $this->evaluateNode($logic, $data);
        } finally {
            $this->depth--;
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
