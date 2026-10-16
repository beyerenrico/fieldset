<?php

declare(strict_types=1);

namespace App\Domain\Forms\Builder;

use FormSchema\Json\Json;
use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * Visual condition builder ↔ JsonLogic (BUILD_PROMPT §8.2 logic editor). UI state:
 * ['mode' => none|rules|json, 'match' => all|any, 'rules' => [[field, operator, value]], 'json' => string].
 * Number and money fields compile to decimal operators (spec §5.3), never to built-in comparisons.
 */
final class LogicCompiler
{
    public const array OPERATORS = ['eq', 'neq', 'empty', 'not_empty', 'gt', 'gte', 'lt', 'lte', 'checked', 'unchecked', 'contains'];

    private const array DECIMAL_TYPES = ['number', 'money', 'calculated'];

    private const array ORDERED = ['gt' => ['>', 'dec.gt'], 'gte' => ['>=', 'dec.gte'], 'lt' => ['<', 'dec.lt'], 'lte' => ['<=', 'dec.lte']];

    /** @param  array<string, string>  $fieldTypes  key => element type */
    public function __construct(private readonly array $fieldTypes = []) {}

    /** @return array{mode: string, match: string, rules: list<array{field: string, operator: string, value: string|null}>, json: string} */
    public function toUi(mixed $expression): array
    {
        $state = ['mode' => 'none', 'match' => 'all', 'rules' => [], 'json' => ''];
        if ($expression === null) {
            return $state;
        }

        $parsed = $this->parse($expression);
        if ($parsed !== null && Json::canonical($this->compile($parsed['match'], $parsed['rules'])) === Json::canonical($expression)) {
            return [...$state, 'mode' => 'rules', ...$parsed];
        }

        return [...$state, 'mode' => 'json', 'json' => Json::encode($expression)];
    }

    /** @param  array<string, mixed>|null  $state */
    public function fromUi(?array $state): mixed
    {
        $mode = $state['mode'] ?? 'none';

        if ($mode === 'json') {
            $json = trim((string) ($state['json'] ?? ''));
            if ($json === '') {
                return null;
            }
            try {
                return Json::decode($json);
            } catch (JsonException) {
                throw new InvalidArgumentException(__('forms.errors.invalid_definition'));
            }
        }

        if ($mode !== 'rules') {
            return null;
        }

        $rules = array_values(array_filter(
            is_array($state['rules'] ?? null) ? $state['rules'] : [],
            static fn (mixed $r): bool => is_array($r) && is_string($r['field'] ?? null) && $r['field'] !== '',
        ));

        return $rules === [] ? null : $this->compile(($state['match'] ?? 'all') === 'any' ? 'any' : 'all', $rules);
    }

    /** @param  list<array<string, mixed>>  $rules */
    private function compile(string $match, array $rules): mixed
    {
        $compiled = array_map(fn (array $rule): stdClass => $this->compileRule($rule), $rules);

        return count($compiled) === 1 ? $compiled[0] : (object) [$match === 'any' ? 'or' : 'and' => $compiled];
    }

    /** @param  array<string, mixed>  $rule */
    private function compileRule(array $rule): stdClass
    {
        $field = (string) $rule['field'];
        $var = (object) ['var' => $field];
        $value = isset($rule['value']) ? (string) $rule['value'] : '';
        $decimal = in_array($this->fieldTypes[$field] ?? '', self::DECIMAL_TYPES, true);
        $operator = (string) ($rule['operator'] ?? 'eq');

        return match ($operator) {
            'neq' => $decimal ? (object) ['!' => (object) ['dec.eq' => [$var, $value]]] : (object) ['!=' => [$var, $value]],
            'empty', 'unchecked' => (object) ['!' => $var],
            'not_empty', 'checked' => (object) ['!!' => $var],
            'gt', 'gte', 'lt', 'lte' => (object) [self::ORDERED[$operator][$decimal ? 1 : 0] => [$var, $value]],
            'contains' => (object) ['in' => [$value, $var]],
            default => $decimal ? (object) ['dec.eq' => [$var, $value]] : (object) ['==' => [$var, $value]],
        };
    }

    /** @return array{match: string, rules: list<array{field: string, operator: string, value: string|null}>}|null */
    private function parse(mixed $expression): ?array
    {
        if (! $expression instanceof stdClass) {
            return null;
        }
        $vars = get_object_vars($expression);
        $operator = count($vars) === 1 ? (string) array_key_first($vars) : '';

        if (in_array($operator, ['and', 'or'], true) && is_array($vars[$operator]) && count($vars[$operator]) > 1) {
            $rules = array_map(fn (mixed $part): ?array => $this->parseRule($part), $vars[$operator]);
            if (in_array(null, $rules, true)) {
                return null;
            }

            /** @var list<array{field: string, operator: string, value: string|null}> $rules */
            return ['match' => $operator === 'or' ? 'any' : 'all', 'rules' => $rules];
        }

        $rule = $this->parseRule($expression);

        return $rule === null ? null : ['match' => 'all', 'rules' => [$rule]];
    }

    /** @return array{field: string, operator: string, value: string|null}|null */
    private function parseRule(mixed $node): ?array
    {
        if (! $node instanceof stdClass || count(get_object_vars($node)) !== 1) {
            return null;
        }
        $op = (string) array_key_first(get_object_vars($node));
        $args = $node->{$op};

        if ($op === '!' || $op === '!!') {
            $field = self::varName($args);
            if ($field !== null) {
                return ['field' => $field, 'operator' => $op === '!' ? 'empty' : 'not_empty', 'value' => null];
            }
            $inner = $op === '!' && $args instanceof stdClass ? $this->parseRule($args) : null;

            return $inner !== null && $inner['operator'] === 'eq' ? [...$inner, 'operator' => 'neq'] : null;
        }

        if (! is_array($args) || count($args) !== 2) {
            return null;
        }

        if ($op === 'in') {
            $field = self::varName($args[1]);

            return $field !== null && is_scalar($args[0]) ? ['field' => $field, 'operator' => 'contains', 'value' => (string) $args[0]] : null;
        }

        $map = ['==' => 'eq', 'dec.eq' => 'eq', '!=' => 'neq', '>' => 'gt', 'dec.gt' => 'gt', '>=' => 'gte', 'dec.gte' => 'gte',
            '<' => 'lt', 'dec.lt' => 'lt', '<=' => 'lte', 'dec.lte' => 'lte'];
        $field = self::varName($args[0]);
        if (! isset($map[$op]) || $field === null || ! is_string($args[1])) {
            return null;
        }

        return ['field' => $field, 'operator' => $map[$op], 'value' => $args[1]];
    }

    private static function varName(mixed $node): ?string
    {
        if ($node instanceof stdClass && count(get_object_vars($node)) === 1 && is_string($node->var ?? null)) {
            return $node->var;
        }

        return null;
    }
}
