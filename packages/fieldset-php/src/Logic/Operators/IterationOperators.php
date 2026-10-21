<?php

declare(strict_types=1);

namespace Fieldset\Logic\Operators;

use Fieldset\Logic\Js;
use Fieldset\Logic\LogicEvaluator;
use Fieldset\Logic\Operation;
use stdClass;

/**
 * map, filter, reduce, all, none, some — the second argument is evaluated per item.
 */
final class IterationOperators implements Operation
{
    public function names(): array
    {
        return ['map', 'filter', 'reduce', 'all', 'none', 'some'];
    }

    public function apply(string $operator, array $args, mixed $data, LogicEvaluator $logic): mixed
    {
        $items = $logic->evaluate($args[0] ?? null, $data);
        $body = $args[1] ?? null;

        if ($operator === 'reduce') {
            return self::reduce($items, $body, $args, $data, $logic);
        }

        $list = is_array($items) ? array_values($items) : [];

        return match ($operator) {
            'map' => array_map(static fn (mixed $item): mixed => $logic->evaluate($body, $item), $list),
            'filter' => self::filter($list, $body, $logic),
            'all' => self::all($list, $body, $logic),
            'none' => self::filter($list, $body, $logic) === [],
            default => self::filter($list, $body, $logic) !== [],
        };
    }

    /**
     * @param  list<mixed>  $items
     * @return list<mixed>
     */
    private static function filter(array $items, mixed $body, LogicEvaluator $logic): array
    {
        $kept = [];

        foreach ($items as $item) {
            if (Js::truthy($logic->evaluate($body, $item))) {
                $kept[] = $item;
            }
        }

        return $kept;
    }

    /**
     * @param  list<mixed>  $items
     */
    private static function all(array $items, mixed $body, LogicEvaluator $logic): bool
    {
        if ($items === []) {
            return false;
        }

        foreach ($items as $item) {
            if (! Js::truthy($logic->evaluate($body, $item))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<mixed>  $args
     */
    private static function reduce(mixed $items, mixed $body, array $args, mixed $data, LogicEvaluator $logic): mixed
    {
        $accumulator = array_key_exists(2, $args) ? $logic->evaluate($args[2], $data) : null;

        if (! is_array($items)) {
            return $accumulator;
        }

        foreach ($items as $item) {
            $scope = new stdClass;
            $scope->current = $item;
            $scope->accumulator = $accumulator;
            $accumulator = $logic->evaluate($body, $scope);
        }

        return $accumulator;
    }
}
