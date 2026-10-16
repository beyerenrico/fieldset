<?php

declare(strict_types=1);

namespace FormSchema\Lint\Rules;

use FormSchema\Definition\Definition;
use FormSchema\Definition\Element;
use FormSchema\Definition\LogicReferences;
use FormSchema\Definition\ReferenceResolver;
use FormSchema\Json\JsNumber;
use FormSchema\Lint\LintFinding;
use FormSchema\Logic\JsonLogic;
use FormSchema\Logic\OperatorNames;

/**
 * Walks one logic expression. References inside per-item arguments of iteration operators are
 * not checked (they resolve against the current item).
 */
final readonly class LogicLinter
{
    private ReferenceResolver $resolver;

    public function __construct(private Definition $definition)
    {
        $this->resolver = new ReferenceResolver($definition);
    }

    /**
     * @return list<LintFinding>
     */
    public function lint(mixed $logic, string $scopeId, string $path): array
    {
        return $this->walk($logic, $scopeId, $path, false);
    }

    /**
     * @return list<LintFinding>
     */
    private function walk(mixed $node, string $scopeId, string $path, bool $perItem): array
    {
        if (is_array($node)) {
            return $this->walkAll(array_values($node), $scopeId, $path, $perItem);
        }

        if (! JsonLogic::isOperation($node)) {
            return [];
        }

        [$operator, $args] = JsonLogic::split($node);
        $findings = OperatorNames::isSupported($operator)
            ? []
            : [LintFinding::error('unknown_operator', $path, ['operator' => $operator])];

        array_push($findings, ...$this->walkArguments($operator, $args, $scopeId, $path, $perItem));
        array_push($findings, ...self::floatLiterals($operator, $args, $path));

        if (! $perItem) {
            array_push($findings, ...$this->references($operator, $args, $scopeId, $path));
        }

        return $findings;
    }

    /**
     * @param  list<mixed>  $args
     * @return list<LintFinding>
     */
    private function walkArguments(string $operator, array $args, string $scopeId, string $path, bool $perItem): array
    {
        if (! in_array($operator, OperatorNames::ITERATION, true)) {
            return $this->walkAll($args, $scopeId, $path, $perItem);
        }

        $scoped = LogicReferences::scopedArguments($operator, $args);

        return [
            ...$this->walkAll($scoped, $scopeId, $path, $perItem),
            ...$this->walk($args[1] ?? null, $scopeId, $path, true),
        ];
    }

    /**
     * @param  list<mixed>  $nodes
     * @return list<LintFinding>
     */
    private function walkAll(array $nodes, string $scopeId, string $path, bool $perItem): array
    {
        $findings = [];

        foreach ($nodes as $node) {
            array_push($findings, ...$this->walk($node, $scopeId, $path, $perItem));
        }

        return $findings;
    }

    /**
     * @param  list<mixed>  $args
     * @return list<LintFinding>
     */
    private function references(string $operator, array $args, string $scopeId, string $path): array
    {
        if ($operator === 'var') {
            return $this->variable($args, $scopeId, $path);
        }

        if (in_array($operator, OperatorNames::DECIMAL_SENSITIVE_BUILTINS, true)) {
            return $this->decimalOperands($operator, $args, $scopeId, $path);
        }

        if (in_array($operator, OperatorNames::ROW_KEY_AGGREGATES, true)) {
            return $this->rowKey($args, $scopeId, $path);
        }

        return [];
    }

    /**
     * @param  list<mixed>  $args
     * @return list<LintFinding>
     */
    private function variable(array $args, string $scopeId, string $path): array
    {
        $reference = LogicReferences::staticPath($args[0] ?? null);

        if ($reference === null || $this->resolver->resolve($reference, $scopeId)->isKnown()) {
            return [];
        }

        return [LintFinding::error('unknown_reference', $path, ['reference' => $reference])];
    }

    /**
     * @param  list<mixed>  $args
     * @return list<LintFinding>
     */
    private function decimalOperands(string $operator, array $args, string $scopeId, string $path): array
    {
        foreach ($args as $arg) {
            $target = $this->varTarget($arg, $scopeId);

            if ($target !== null && $target->isDecimal()) {
                return [LintFinding::error('decimal_builtin_operator', $path, ['operator' => $operator])];
            }
        }

        return [];
    }

    /**
     * `sum`/`min`/`max` over a repeater `var`: the row key must be defined in the repeater's rows.
     *
     * @param  list<mixed>  $args
     * @return list<LintFinding>
     */
    private function rowKey(array $args, string $scopeId, string $path): array
    {
        $repeater = $this->varTarget($args[0] ?? null, $scopeId);
        $rowKey = $args[1] ?? null;

        if ($repeater === null || ! $repeater->isRepeater() || ! is_string($rowKey)) {
            return [];
        }

        if ($this->definition->scope($repeater->rowScopeId())?->element($rowKey) !== null) {
            return [];
        }

        return [LintFinding::error('unknown_reference', $path, ['reference' => $rowKey])];
    }

    /**
     * Target element of a literal `{"var": path}` operand.
     */
    private function varTarget(mixed $operand, string $scopeId): ?Element
    {
        if (! JsonLogic::isOperation($operand)) {
            return null;
        }

        [$operator, $args] = JsonLogic::split($operand);
        $reference = $operator === 'var' ? LogicReferences::staticPath($args[0] ?? null) : null;

        if ($reference === null || $reference === '') {
            return null;
        }

        return $this->resolver->resolve($reference, $scopeId)->target;
    }

    /**
     * @param  list<mixed>  $args
     * @return list<LintFinding>
     */
    private static function floatLiterals(string $operator, array $args, string $path): array
    {
        if (! in_array($operator, OperatorNames::DECIMAL, true)) {
            return [];
        }

        foreach ($args as $operand) {
            if ((is_int($operand) || is_float($operand)) && ! JsNumber::isSafeInteger($operand)) {
                return [LintFinding::error('float_literal', $path, ['operator' => $operator])];
            }
        }

        return [];
    }
}
