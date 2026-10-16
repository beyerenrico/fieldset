<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import\Mapping;

use stdClass;

/**
 * Compiles an Acrobat built-in calculation (AFSimple_Calculate) into decimal JsonLogic (§7.4):
 * SUM → `sum`, PRD → `dec.mul`, AVG → `dec.div`, MIN → `min`, MAX → `max`. Acrobat treats empty
 * fields as 0, so operands of PRD/MIN/MAX use a `"0"` fallback. A whole repeater column becomes an
 * aggregate such as `{"sum": [{"var": "rows"}, "total"]}`. Inside a repeater row, cells of the
 * same row become row variables and top-level fields `$root.<key>`.
 *
 * @phpstan-type Ref array{kind: 'scalar', key: string}|array{kind: 'cell', key: string, repeater: string, index: int, rows: int}
 */
final class CalculationMapper
{
    /**
     * The expression and its warnings, or a reason code (`calculation_reference`, `unsupported_calculation`).
     *
     * @param  array{function: string, fields: list<string>}  $calculation
     * @param  array<string, Ref>  $refs  PDF field name => reference
     * @param  array{repeater: string, index: int}|null  $row  the repeater row the calculated element lives in
     * @return array{expression: stdClass, warnings: list<array{code: string, params?: array<string, int|string>}>}|string
     */
    public function compile(array $calculation, array $refs, int $scale, ?array $row = null): array|string
    {
        $resolved = $this->resolve($calculation['fields'], $refs);
        if ($resolved === null) {
            return 'calculation_reference';
        }

        $scalars = [];
        $columns = [];
        $warnings = [];
        foreach ($resolved as $ref) {
            if ($ref['kind'] === 'scalar') {
                $scalars[] = $row === null ? $ref['key'] : '$root.'.$ref['key'];
            } elseif ($row !== null && $ref['repeater'] === $row['repeater'] && $ref['index'] === $row['index']) {
                $scalars[] = $ref['key'];
            } elseif ($row !== null) {
                return 'unsupported_calculation'; // another row: not expressible per row
            } else {
                $columns[$ref['repeater']."\0".$ref['key']][] = $ref;
            }
        }

        foreach ($columns as $cells) {
            if (count(array_unique(array_column($cells, 'index'))) < ($cells[0]['rows'] ?? 0)) {
                $warnings[] = ['code' => 'partial_column', 'params' => ['column' => $cells[0]['key']]];
            }
        }

        $expression = $this->expression($calculation['function'], $scalars, array_keys($columns), $scale);

        return $expression instanceof stdClass ? ['expression' => $expression, 'warnings' => $warnings] : 'unsupported_calculation';
    }

    /**
     * @param  list<string>  $vars  variable paths of scalar operands
     * @param  list<string>  $columns  "repeater\0column" of whole-column operands
     */
    private function expression(string $function, array $vars, array $columns, int $scale): ?stdClass
    {
        $aggregate = static function (string $op, string $column): stdClass {
            [$repeater, $key] = explode("\0", $column);

            return self::op($op, [self::op('var', $repeater), $key]);
        };
        $zeroed = array_map(static fn (string $v): stdClass => self::op('var', [$v, '0']), $vars);

        return match ($function) {
            'SUM' => count($columns) === 1 && $vars === []
                ? $aggregate('sum', $columns[0])
                : self::op('sum', [[...array_map(static fn (string $v): stdClass => self::op('var', $v), $vars), ...array_map(static fn (string $c): stdClass => $aggregate('sum', $c), $columns)]]),
            'PRD' => $columns !== [] ? null : self::op('dec.mul', count($zeroed) === 1 ? [$zeroed[0], 1] : $zeroed),
            'AVG' => self::op('dec.div', [$this->expression('SUM', $vars, $columns, $scale), $this->count($vars, $columns), $scale]),
            'MIN', 'MAX' => $this->extreme(strtolower($function), $zeroed, array_map(static fn (string $c): stdClass => $aggregate(strtolower($function), $c), $columns)),
            default => null,
        };
    }

    /**
     * @param  list<string>  $vars
     * @param  list<string>  $columns
     */
    private function count(array $vars, array $columns): int|stdClass
    {
        $terms = array_map(static fn (string $c): stdClass => self::op('count', [self::op('var', explode("\0", $c)[0])]), $columns);
        if ($terms === []) {
            return count($vars);
        }
        if ($vars === [] && count($terms) === 1) {
            return $terms[0];
        }

        return self::op('dec.add', [...($vars === [] ? [] : [count($vars)]), ...$terms]);
    }

    /**
     * @param  list<stdClass>  $scalars
     * @param  list<stdClass>  $aggregates
     */
    private function extreme(string $op, array $scalars, array $aggregates): stdClass
    {
        $terms = [...$scalars, ...$aggregates];

        return count($terms) === 1 ? $terms[0] : self::op($op, $terms);
    }

    /**
     * Acrobat names may address a parent field: "items" means every field below "items.".
     *
     * @param  list<string>  $names
     * @param  array<string, Ref>  $refs
     * @return list<Ref>|null
     */
    private function resolve(array $names, array $refs): ?array
    {
        $resolved = [];
        foreach ($names as $name) {
            if (isset($refs[$name])) {
                $resolved[] = $refs[$name];

                continue;
            }
            $children = array_filter($refs, static fn (string $candidate): bool => str_starts_with($candidate, $name.'.'), ARRAY_FILTER_USE_KEY);
            if ($children === []) {
                return null;
            }
            array_push($resolved, ...array_values($children));
        }

        return $resolved === [] ? null : $resolved;
    }

    private static function op(string $operator, mixed $args): stdClass
    {
        $node = new stdClass;
        $node->{$operator} = $args;

        return $node;
    }
}
