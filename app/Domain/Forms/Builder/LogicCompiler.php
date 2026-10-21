<?php

declare(strict_types=1);

namespace App\Domain\Forms\Builder;

use Fieldset\Json\Json;
use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * Visual condition builder ↔ JsonLogic (BUILD_PROMPT §8.2 logic editor). UI state:
 * ['mode' => none|rules|json, 'match' => all|any, 'groups' => [['match' => all|any, 'rules' => [[field, operator, value]]]],
 *  'json' => string]. Number/money fields compile to decimal operators (spec §5.3); boolean fields
 * compare with boolean literals. Anything the visual editor cannot express stays in raw JSON mode.
 */
final readonly class LogicCompiler
{
    public const array OPERATORS = ['eq', 'neq', 'empty', 'not_empty', 'gt', 'gte', 'lt', 'lte', 'checked', 'unchecked', 'contains'];

    private const array DECIMAL_TYPES = ['number', 'money', 'calculated'];

    private const array BOOLEAN_TYPES = ['checkbox', 'toggle'];

    /** Option values are strings: compare them strictly. */
    private const array OPTION_TYPES = ['select', 'radio'];

    private const array ORDERED = ['gt' => ['>', 'dec.gt'], 'gte' => ['>=', 'dec.gte'], 'lt' => ['<', 'dec.lt'], 'lte' => ['<=', 'dec.lte']];

    /** @param  array<string, string>  $fieldTypes  key => element type */
    public function __construct(private array $fieldTypes = []) {}

    /** @return array{mode: string, match: string, groups: list<array{match: string, rules: list<array{field: string, operator: string, value: string|null}>}>, json: string} */
    public function toUi(mixed $expression): array
    {
        $state = ['mode' => 'none', 'match' => 'all', 'groups' => [], 'json' => ''];
        if ($expression === null) {
            return $state;
        }

        $parsed = $this->parse($expression);
        if ($parsed !== null) {
            $candidate = [...$state, 'mode' => 'rules', ...$parsed];
            if (Json::canonical($this->fromUi($candidate)) === Json::canonical($expression)) {
                return $candidate;
            }
        }

        return [...$state, 'mode' => 'json', 'json' => Json::encode($expression)];
    }

    /** @param  array<string, mixed>|null  $state */
    public function fromUi(?array $state): mixed
    {
        $mode = $state['mode'] ?? 'none';

        if ($mode === 'json') {
            return $this->decodeJson((string) (is_scalar($state['json'] ?? null) ? $state['json'] : ''));
        }

        if ($mode !== 'rules') {
            return null;
        }

        $groups = [];
        foreach (is_array($state['groups'] ?? null) ? array_values($state['groups']) : [] as $group) {
            $compiled = is_array($group) ? $this->compileGroup($group) : null;
            if ($compiled instanceof stdClass) {
                $groups[] = $compiled;
            }
        }

        if ($groups === []) {
            return null;
        }

        return count($groups) === 1 ? $groups[0] : (object) [($state['match'] ?? 'all') === 'any' ? 'or' : 'and' => $groups];
    }

    /** @param  array<array-key, mixed>  $group */
    private function compileGroup(array $group): ?stdClass
    {
        $rules = array_values(array_filter(
            is_array($group['rules'] ?? null) ? $group['rules'] : [],
            static fn (mixed $r): bool => is_array($r) && is_string($r['field'] ?? null) && $r['field'] !== '',
        ));
        if ($rules === []) {
            return null;
        }
        $compiled = array_map($this->compileRule(...), $rules);

        return count($compiled) === 1 ? $compiled[0] : (object) [($group['match'] ?? 'all') === 'any' ? 'or' : 'and' => $compiled];
    }

    /** @param  array<array-key, mixed>  $rule */
    private function compileRule(array $rule): stdClass
    {
        $field = (string) $rule['field'];
        $var = (object) ['var' => $field];
        $value = is_scalar($rule['value'] ?? null) ? (string) $rule['value'] : '';
        $type = $this->fieldTypes[$field] ?? '';
        $decimal = in_array($type, self::DECIMAL_TYPES, true);
        $boolean = in_array($type, self::BOOLEAN_TYPES, true);
        $option = in_array($type, self::OPTION_TYPES, true);
        $operator = is_string($rule['operator'] ?? null) ? $rule['operator'] : 'eq';

        return match (true) {
            $boolean && in_array($operator, ['eq', 'neq'], true) => (object) [$operator === 'eq' ? '===' : '!==' => [$var, $this->truthy($value)]],
            $option && in_array($operator, ['eq', 'neq'], true) => (object) [$operator === 'eq' ? '===' : '!==' => [$var, $value]],
            $operator === 'neq' => $decimal ? (object) ['!' => (object) ['dec.eq' => [$var, $value]]] : (object) ['!=' => [$var, $value]],
            in_array($operator, ['empty', 'unchecked'], true) => (object) ['!' => $var],
            in_array($operator, ['not_empty', 'checked'], true) => (object) ['!!' => $var],
            isset(self::ORDERED[$operator]) => (object) [self::ORDERED[$operator][$decimal ? 1 : 0] => [$var, $value]],
            $operator === 'contains' => (object) ['in' => [$value, $var]],
            default => $decimal ? (object) ['dec.eq' => [$var, $value]] : (object) ['==' => [$var, $value]],
        };
    }

    /** @return array{match: string, groups: list<array{match: string, rules: list<array{field: string, operator: string, value: string|null}>}>}|null */
    private function parse(mixed $expression): ?array
    {
        $group = $this->parseGroup($expression);
        if ($group !== null) {
            return ['match' => 'all', 'groups' => [$group]];
        }

        [$operator, $items] = $this->junction($expression);
        if ($operator === null) {
            return null;
        }
        $groups = [];
        foreach ($items as $item) {
            $parsed = $this->parseGroup($item);
            if ($parsed === null) {
                return null;
            }
            $groups[] = $parsed;
        }

        return ['match' => $operator === 'or' ? 'any' : 'all', 'groups' => $groups];
    }

    /** @return array{match: string, rules: list<array{field: string, operator: string, value: string|null}>}|null */
    private function parseGroup(mixed $node): ?array
    {
        $rule = $this->parseRule($node);
        if ($rule !== null) {
            return ['match' => 'all', 'rules' => [$rule]];
        }

        [$operator, $items] = $this->junction($node);
        if ($operator === null) {
            return null;
        }
        $rules = [];
        foreach ($items as $item) {
            $parsed = $this->parseRule($item);
            if ($parsed === null) {
                return null;
            }
            $rules[] = $parsed;
        }

        return ['match' => $operator === 'or' ? 'any' : 'all', 'rules' => $rules];
    }

    /** @return array{0: string|null, 1: list<mixed>} */
    private function junction(mixed $node): array
    {
        if (! $node instanceof stdClass || count(get_object_vars($node)) !== 1) {
            return [null, []];
        }
        $operator = (string) array_key_first(get_object_vars($node));
        $items = $node->{$operator};

        return in_array($operator, ['and', 'or'], true) && is_array($items) && count($items) > 1
            ? [$operator, array_values($items)]
            : [null, []];
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
            $field = $this->varName($args);
            if ($field !== null) {
                return ['field' => $field, 'operator' => $op === '!' ? 'empty' : 'not_empty', 'value' => null];
            }
            $inner = $op === '!' && $args instanceof stdClass ? $this->parseRule($args) : null;

            return $inner !== null && $inner['operator'] === 'eq' ? [...$inner, 'operator' => 'neq'] : null;
        }

        if (! is_array($args) || count($args) !== 2) {
            return null;
        }

        return $this->parseBinary($op, $args);
    }

    /**
     * @param  array<array-key, mixed>  $args
     * @return array{field: string, operator: string, value: string|null}|null
     */
    private function parseBinary(string $op, array $args): ?array
    {
        if ($op === 'in') {
            $field = $this->varName($args[1] ?? null);

            return $field !== null && is_scalar($args[0] ?? null) ? ['field' => $field, 'operator' => 'contains', 'value' => (string) $args[0]] : null;
        }

        $field = $this->varName($args[0] ?? null);
        if ($field !== null && in_array($op, ['===', '!=='], true) && (is_bool($args[1] ?? null) || is_string($args[1] ?? null))) {
            $literal = $args[1];

            return ['field' => $field, 'operator' => $op === '===' ? 'eq' : 'neq', 'value' => is_bool($literal) ? ($literal ? 'true' : 'false') : $literal];
        }

        $map = ['==' => 'eq', 'dec.eq' => 'eq', '!=' => 'neq', '>' => 'gt', 'dec.gt' => 'gt', '>=' => 'gte', 'dec.gte' => 'gte',
            '<' => 'lt', 'dec.lt' => 'lt', '<=' => 'lte', 'dec.lte' => 'lte'];
        if (! isset($map[$op]) || $field === null || ! is_string($args[1] ?? null)) {
            return null;
        }

        return ['field' => $field, 'operator' => $map[$op], 'value' => $args[1]];
    }

    private function decodeJson(string $json): mixed
    {
        if (trim($json) === '') {
            return null;
        }
        try {
            return Json::decode($json);
        } catch (JsonException) {
            throw new InvalidArgumentException(__('forms.errors.invalid_definition'));
        }
    }

    private function truthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['true', '1', 'yes', 'ja'], true);
    }

    private function varName(mixed $node): ?string
    {
        return $node instanceof stdClass && count(get_object_vars($node)) === 1 && is_string($node->var ?? null) ? $node->var : null;
    }
}
