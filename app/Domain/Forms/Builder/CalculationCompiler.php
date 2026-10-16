<?php

declare(strict_types=1);

namespace App\Domain\Forms\Builder;

use FormSchema\Json\Json;
use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * Guided calculation editor ↔ JsonLogic for `calculated` elements (BUILD_PROMPT §8.2). UI state:
 * ['mode' => sum|product|add|subtract|percent|json, 'repeater', 'column', 'fields' => list, 'field', 'rate', 'json'].
 */
final class CalculationCompiler
{
    public const array MODES = ['sum', 'product', 'add', 'subtract', 'percent', 'json'];

    private const array FIELD_OPS = ['product' => 'dec.mul', 'add' => 'dec.add', 'subtract' => 'dec.sub'];

    /** @return array{mode: string, repeater: string|null, column: string|null, fields: list<string>, field: string|null, rate: string|null, json: string} */
    public function toUi(mixed $expression): array
    {
        $state = ['mode' => 'json', 'repeater' => null, 'column' => null, 'fields' => [], 'field' => null, 'rate' => null, 'json' => ''];
        $parsed = $this->parse($expression);

        if ($parsed !== null) {
            $candidate = [
                'mode' => is_string($parsed['mode'] ?? null) ? $parsed['mode'] : 'json',
                'repeater' => isset($parsed['repeater']) && is_string($parsed['repeater']) ? $parsed['repeater'] : null,
                'column' => isset($parsed['column']) && is_string($parsed['column']) ? $parsed['column'] : null,
                'fields' => isset($parsed['fields']) && is_array($parsed['fields']) ? array_values(array_map(strval(...), $parsed['fields'])) : [],
                'field' => isset($parsed['field']) && is_string($parsed['field']) ? $parsed['field'] : null,
                'rate' => isset($parsed['rate']) && is_string($parsed['rate']) ? $parsed['rate'] : null,
                'json' => '',
            ];
            if (Json::canonical($this->fromUi($candidate)) === Json::canonical($expression)) {
                return $candidate;
            }
        }

        return [...$state, 'json' => $expression === null ? '' : Json::encode($expression)];
    }

    /** @param  array<string, mixed>|null  $state */
    public function fromUi(?array $state): mixed
    {
        $mode = (string) ($state['mode'] ?? 'json');
        $var = static fn (mixed $key): stdClass => (object) ['var' => (string) $key];

        return match ($mode) {
            'sum' => (object) ['sum' => [$var($state['repeater'] ?? ''), (string) ($state['column'] ?? '')]],
            'product', 'add', 'subtract' => (object) [self::FIELD_OPS[$mode] => array_map($var, array_values(is_array($state['fields'] ?? null) ? $state['fields'] : []))],
            'percent' => (object) ['dec.round' => [(object) ['dec.mul' => [$var($state['field'] ?? ''), (string) ($state['rate'] ?? '0')]], 2]],
            default => $this->decodeJson((string) ($state['json'] ?? '')),
        };
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

    /** @return array<string, mixed>|null */
    private function parse(mixed $expression): ?array
    {
        if (! $expression instanceof stdClass || count(get_object_vars($expression)) !== 1) {
            return null;
        }
        $op = (string) array_key_first(get_object_vars($expression));
        $args = $expression->{$op};
        if (! is_array($args)) {
            return null;
        }

        if ($op === 'sum' && count($args) === 2 && $this->var($args[0]) !== null && is_string($args[1])) {
            return ['mode' => 'sum', 'repeater' => $this->var($args[0]), 'column' => $args[1]];
        }

        $mode = array_search($op, self::FIELD_OPS, true);
        if ($mode !== false) {
            $fields = array_map($this->var(...), $args);

            return in_array(null, $fields, true) ? null : ['mode' => $mode, 'fields' => $fields];
        }

        if ($op === 'dec.round' && count($args) === 2 && $args[1] === 2 && $args[0] instanceof stdClass) {
            $mul = $args[0]->{'dec.mul'} ?? null;
            if (is_array($mul) && count($mul) === 2 && $this->var($mul[0]) !== null && is_string($mul[1])) {
                return ['mode' => 'percent', 'field' => $this->var($mul[0]), 'rate' => $mul[1]];
            }
        }

        return null;
    }

    private function var(mixed $node): ?string
    {
        return $node instanceof stdClass && count(get_object_vars($node)) === 1 && is_string($node->var ?? null) ? $node->var : null;
    }
}
