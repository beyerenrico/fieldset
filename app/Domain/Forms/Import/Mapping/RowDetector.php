<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import\Mapping;

/**
 * Repeater detection (§8.13): fields whose names differ only by a row index — `Row1Desc` …
 * `Row8Desc`, `desc.0` … `desc.7`, hierarchical `items.0.desc` … — form one table with one column
 * per name template. Names like `Text1`, `Text2` only count as a table when at least two such
 * columns share the row indexes and their rows line up on the page.
 */
final class RowDetector
{
    private const array ELIGIBLE = ['text', 'textarea', 'email', 'phone', 'number', 'money', 'date', 'time', 'datetime',
        'checkbox', 'select', 'multiselect', 'calculated'];

    private const array KIND_PRIORITY = ['h-stem' => 4, 'h-col' => 3, 'f-stem' => 2, 'f-end' => 1];

    /**
     * @param  list<MappedField>  $fields
     * @return array{0: list<Table>, 1: list<MappedField>} tables and the remaining (scalar) fields
     */
    public function detect(array $fields): array
    {
        $kinds = [];
        $stems = [];
        $columns = [];
        $templates = [];
        foreach ($fields as $field) {
            $candidate = in_array($field->type, self::ELIGIBLE, true) ? $this->candidate($field->field->name) : null;
            if ($candidate === null) {
                continue;
            }
            $key = $candidate['kind']."\0".($candidate['stem'] ?? '')."\0".$field->field->page;
            $kinds[$key] = $candidate['kind'];
            $stems[$key] = $candidate['stem'];
            if (! isset($columns[$key][$candidate['column']][$candidate['index']])) {
                $columns[$key][$candidate['column']][$candidate['index']] = $field;
                $templates[$key][$candidate['column']] ??= $candidate['template'];
            }
        }

        $tables = [];
        foreach ($kinds as $key => $kind) {
            foreach ($this->tables($kind, $stems[$key], $columns[$key] ?? [], $templates[$key] ?? []) as $table) {
                $tables[] = $table;
            }
        }

        $used = [];
        foreach ($tables as $table) {
            foreach ($table->cells() as $cell) {
                $used[$cell->field->name] = true;
            }
        }

        return [$tables, array_values(array_filter($fields, static fn (MappedField $f): bool => ! isset($used[$f->field->name])))];
    }

    /**
     * @param  array<string, array<int, MappedField>>  $columns
     * @param  array<string, string>  $templates
     * @return list<Table>
     */
    private function tables(string $kind, ?string $stem, array $columns, array $templates): array
    {
        // A column needs at least two consecutive rows.
        $columns = array_filter($columns, static fn (array $cells): bool => count($cells) >= 2 && self::consecutive(array_keys($cells)));
        if ($columns === []) {
            return [];
        }

        if ($stem !== null) {
            return [$this->table($stem, $columns, $templates, $kind)];
        }

        // Without a stem, columns with identical row indexes form one table.
        $bySet = [];
        foreach ($columns as $name => $cells) {
            $indices = array_keys($cells);
            sort($indices);
            $bySet[implode(',', $indices)][(string) $name] = $cells;
        }

        $tables = [];
        foreach ($bySet as $set) {
            $table = $this->table(null, $set, $templates, $kind);
            if ($kind === 'f-end' && (count($set) < 2 || ! $table->aligned)) {
                continue;
            }
            $tables[] = $table;
        }

        return $tables;
    }

    /**
     * @param  array<string, array<int, MappedField>>  $columns
     * @param  array<string, string>  $templates
     */
    private function table(?string $stem, array $columns, array $templates, string $kind): Table
    {
        foreach ($columns as $name => $cells) {
            ksort($cells);
            $columns[$name] = $cells;
        }
        uksort($columns, static fn (string $a, string $b): int => Table::first($columns[$a])->field->rect[0] <=> Table::first($columns[$b])->field->rect[0] ?: strcmp($a, $b));

        $indices = array_values(array_unique(array_merge(...array_map(array_keys(...), array_values($columns)))));
        sort($indices);
        $first = (string) array_key_first($columns);
        $template = count($columns) > 1 && $kind !== 'h-stem' && $kind !== 'f-stem'
            ? ($kind === 'h-col' ? '*.{n}' : '*{n}')
            : $templates[$first];

        return new Table($stem, $template, $columns, $indices, $this->aligned($columns));
    }

    /**
     * Index position and name template of a field name, preferring hierarchical forms and, among
     * equal forms, the last digit run.
     *
     * @return array{kind: string, stem: ?string, column: string, index: int, template: string}|null
     */
    private function candidate(string $name): ?array
    {
        if (preg_match_all('/\d+/', $name, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }

        $best = null;
        foreach ($matches[0] as [$digits, $offset]) {
            $prefix = substr($name, 0, $offset);
            $suffix = substr($name, $offset + strlen($digits));
            $candidate = $this->shape($prefix, $suffix, (int) $digits);
            if ($candidate !== null && strlen($digits) <= 4
                && ($best === null || self::KIND_PRIORITY[$candidate['kind']] >= self::KIND_PRIORITY[$best['kind']])) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /** @return array{kind: string, stem: ?string, column: string, index: int, template: string}|null */
    private function shape(string $prefix, string $suffix, int $index): ?array
    {
        $dotBefore = str_ends_with($prefix, '.');
        $dotAfter = str_starts_with($suffix, '.');
        [$kind, $stem, $column, $template] = match (true) {
            $dotBefore && $dotAfter => ['h-stem', substr($prefix, 0, -1), substr($suffix, 1), $prefix.'{n}.*'],
            $dotBefore && $suffix === '' => ['h-col', null, substr($prefix, 0, -1), $prefix.'{n}'],
            $prefix !== '' && $suffix !== '' && ! $dotBefore && ! $dotAfter => ['f-stem', $this->trimSeparators($prefix), $this->trimSeparators($suffix), $prefix.'{n}*'],
            $prefix !== '' && $suffix === '' && ! $dotBefore => ['f-end', null, $this->trimSeparators($prefix), '*{n}'],
            default => [null, null, '', ''],
        };

        if ($kind === null || $column === '' || $stem === '') {
            return null;
        }

        return ['kind' => $kind, 'stem' => $stem, 'column' => $column, 'index' => $index, 'template' => $template];
    }

    private function trimSeparators(string $part): string
    {
        return trim($part, ' _-#.:');
    }

    /** @param  list<int>  $indices */
    private static function consecutive(array $indices): bool
    {
        sort($indices);

        return count($indices) - 1 === $indices[count($indices) - 1] - $indices[0];
    }

    /**
     * Rows line up: per row, every cell's vertical center is close to the others, and rows run top
     * to bottom in index order.
     *
     * @param  array<string, array<int, MappedField>>  $columns
     */
    private function aligned(array $columns): bool
    {
        $rows = [];
        foreach ($columns as $cells) {
            foreach ($cells as $index => $cell) {
                $rows[$index][] = $cell->field;
            }
        }
        ksort($rows);

        $previous = INF;
        foreach ($rows as $fields) {
            $centers = array_map(static fn ($f): float => $f->centerY(), $fields);
            $tolerance = max(4.0, 0.5 * min(array_map(static fn ($f): float => $f->height(), $fields)));
            if (max($centers) - min($centers) > $tolerance || max($centers) >= $previous) {
                return false;
            }
            $previous = min($centers);
        }

        return true;
    }
}
