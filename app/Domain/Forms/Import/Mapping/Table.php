<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import\Mapping;

use LogicException;

/**
 * A detected table: fields whose names differ only by a row index, one column per name template.
 * Becomes one `repeater` without a row limit (§8.13).
 */
final readonly class Table
{
    /**
     * @param  array<string, array<int, MappedField>>  $columns  column name => row index => field, columns left to right
     * @param  list<int>  $indices  row indexes in ascending order
     */
    public function __construct(
        public ?string $stem,
        public string $template,
        public array $columns,
        public array $indices,
        public bool $aligned,
    ) {}

    public function page(): int
    {
        return $this->firstRow()[0]->field->page;
    }

    /** @return list<MappedField> fields of the first row, left to right */
    public function firstRow(): array
    {
        return array_values(array_map(self::first(...), $this->columns));
    }

    /** @return list<MappedField> */
    public function cells(): array
    {
        $cells = [];
        foreach ($this->columns as $column) {
            foreach ($column as $cell) {
                $cells[] = $cell;
            }
        }

        return $cells;
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} union of all cell rectangles */
    public function rect(): array
    {
        $box = null;
        foreach ($this->cells() as $cell) {
            $r = $cell->field->rect;
            $box = $box === null ? $r : [min($box[0], $r[0]), min($box[1], $r[1]), max($box[2], $r[2]), max($box[3], $r[3])];
        }

        return $box ?? throw new LogicException('A table has at least one cell.');
    }

    /** @param  array<int, MappedField>  $cells */
    public static function firstIndex(array $cells): int
    {
        $first = null;
        foreach (array_keys($cells) as $index) {
            $first = $first === null ? $index : min($first, $index);
        }

        return $first ?? throw new LogicException('A column has at least one cell.');
    }

    /** @param  array<int, MappedField>  $cells */
    public static function first(array $cells): MappedField
    {
        return $cells[self::firstIndex($cells)];
    }
}
