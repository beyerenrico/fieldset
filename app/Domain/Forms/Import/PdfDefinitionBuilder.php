<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import;

use App\Domain\Forms\Import\Mapping\CalculationMapper;
use App\Domain\Forms\Import\Mapping\ElementWriter;
use App\Domain\Forms\Import\Mapping\FieldMapper;
use App\Domain\Forms\Import\Mapping\KeyAllocator;
use App\Domain\Forms\Import\Mapping\LabelResolver;
use App\Domain\Forms\Import\Mapping\MappedField;
use App\Domain\Forms\Import\Mapping\ReadingOrder;
use App\Domain\Forms\Import\Mapping\RowDetector;
use App\Domain\Forms\Import\Mapping\Table;
use App\Domain\Forms\Import\Mapping\UnmappedField;
use App\Domain\Forms\Import\Model\AcroField;
use App\Domain\Forms\Import\Model\AcroFormDocument;
use App\Domain\Forms\Support\BlankDefinition;
use Fieldset\Json\Json;
use stdClass;

/**
 * Builds a draft Form Definition from an AcroForm, deterministically and without AI (§8.13):
 * map fields, detect repeaters, order elements by reading order, assign unique snake_case keys,
 * compile built-in calculations, one page per PDF page that has imported fields.
 *
 * @phpstan-import-type Ref from CalculationMapper
 *
 * @phpstan-type Item array{page: int, rect: array{0: float, 1: float, 2: float, 3: float}, key: string, field?: MappedField, table?: Table, columns?: array<string, string>}
 */
final readonly class PdfDefinitionBuilder
{
    public function __construct(
        private LabelResolver $labels = new LabelResolver,
        private CalculationMapper $calculations = new CalculationMapper,
        private float $lowConfidence = 0.6,
    ) {}

    public function build(AcroFormDocument $document, string $title, string $locale, string $currency = 'EUR'): PdfImportResult
    {
        $mapper = new FieldMapper($currency);
        $mapped = [];
        $unmapped = [];
        foreach ($document->fields as $field) {
            $result = $mapper->map($field);
            $result instanceof UnmappedField ? $unmapped[] = $result : $mapped[] = $result;
        }

        [$tables, $scalars] = (new RowDetector)->detect($mapped);
        $items = $this->keyed(ReadingOrder::sort($this->placed($tables, $scalars)));
        [$items, $expressions, $failed, $warnings, $caps] = $this->compile($items);
        foreach ($failed as $name => $reason) {
            $unmapped[] = new UnmappedField($name, $this->pageOf($document, $name), $reason);
        }

        $writer = new ElementWriter($this->labels, $document);
        $pages = [];
        foreach ($items as $item) {
            $pages[$item['page']][] = isset($item['table'])
                ? $writer->repeater($item['table'], $item['key'], $item['columns'] ?? [], $expressions, $caps)
                : $writer->field($item['field'] ?? throw new \LogicException('item without field'), $item['key'], $expressions[$item['field']->field->name] ?? null, $caps[$item['field']->field->name] ?? 1.0);
        }
        ksort($pages);

        $definition = $this->definition($title, $locale, $pages);
        $warnings = [...$this->fieldWarnings($mapped, $tables, $failed), ...$warnings, ...array_map(static fn (string $code): array => ['code' => $code], $document->warnings)];
        if (count($pages) < $document->pageCount) {
            $warnings[] = ['code' => 'empty_pages_skipped', 'params' => ['count' => $document->pageCount - count($pages)]];
        }

        return new PdfImportResult($definition, new ImportReport(
            $this->sortUnmapped($document, $unmapped),
            $warnings,
            $this->stats($document, $definition, $unmapped, $tables),
        ));
    }

    /**
     * @param  list<Table>  $tables
     * @param  list<MappedField>  $scalars
     * @return list<array{page: int, rect: array{0: float, 1: float, 2: float, 3: float}, field?: MappedField, table?: Table}>
     */
    private function placed(array $tables, array $scalars): array
    {
        return [
            ...array_map(static fn (MappedField $f): array => ['page' => $f->field->page, 'rect' => $f->field->rect, 'field' => $f], $scalars),
            ...array_map(static fn (Table $t): array => ['page' => $t->page(), 'rect' => $t->rect(), 'table' => $t], $tables),
        ];
    }

    /**
     * Keys in reading order: root scope for fields and repeaters, one scope per repeater row.
     *
     * @param  list<array{page: int, rect: array{0: float, 1: float, 2: float, 3: float}, field?: MappedField, table?: Table}>  $items
     * @return list<Item>
     */
    private function keyed(array $items): array
    {
        $root = new KeyAllocator;
        $keyed = [];
        foreach ($items as $item) {
            if (isset($item['table'])) {
                $table = $item['table'];
                $item['key'] = $root->allocate($table->stem === null ? 'rows' : $this->plural(KeyAllocator::snake($table->stem)));
                $row = new KeyAllocator;
                $item['columns'] = [];
                foreach (array_keys($table->columns) as $name) {
                    $item['columns'][(string) $name] = $row->allocate((string) $name);
                }
            } else {
                $item['key'] = $root->allocate(($item['field'] ?? throw new \LogicException('item without field'))->field->name);
            }
            $keyed[] = $item;
        }

        return $keyed;
    }

    /**
     * Compiles calculations until stable: a calculation that cannot be expressed, or that refers to a
     * field that was not imported, is dropped and reported, which may drop calculations using it.
     *
     * @param  list<Item>  $items
     * @return array{0: list<Item>, 1: array<string, stdClass>, 2: array<string, string>, 3: list<array{code: string, field?: string, page?: int, params?: array<string, int|string>}>, 4: array<string, float>}
     */
    private function compile(array $items): array
    {
        $failed = [];
        do {
            $refs = $this->refs($items, $failed);
            $expressions = [];
            $warnings = [];
            $caps = [];
            $new = [];
            foreach ($this->calculatedCells($items) as [$cell, $cells, $row]) {
                if (isset($failed[$cell->field->name])) {
                    continue;
                }
                $result = $this->calculations->compile($cell->calculation ?? ['function' => '', 'fields' => []], $refs, $this->scale($cell), $row);
                if (is_string($result)) {
                    foreach ($cells as $member) {
                        $new[$member->field->name] = $result;
                    }

                    continue;
                }
                $expressions[$cell->field->name] = $result['expression'];
                foreach ($result['warnings'] as $warning) {
                    $warnings[] = [...$warning, 'field' => $cell->field->name, 'page' => $cell->field->page];
                    $caps[$cell->field->name] = 0.7;
                }
                if ($row !== null && ! $this->rowsAgree($cells, $refs, $row['repeater'], Json::canonical($result['expression']))) {
                    $warnings[] = ['code' => 'inconsistent_rows', 'field' => $cell->field->name, 'page' => $cell->field->page];
                    $caps[$cell->field->name] = 0.7;
                }
            }
            $failed += $new;
        } while ($new !== []);

        return [$this->withoutFailed($items, $failed), $expressions, $failed, $warnings, $caps];
    }

    /**
     * Calculated scalars as [cell, [cell], null]; calculated table columns as [first cell, all cells, row].
     *
     * @param  list<Item>  $items
     * @return list<array{0: MappedField, 1: list<MappedField>, 2: array{repeater: string, index: int}|null}>
     */
    private function calculatedCells(array $items): array
    {
        $cells = [];
        foreach ($items as $item) {
            if (isset($item['field']) && $item['field']->isCalculated()) {
                $cells[] = [$item['field'], [$item['field']], null];
            }
            foreach (isset($item['table']) ? $item['table']->columns : [] as $column) {
                $index = Table::firstIndex($column);
                if ($column[$index]->isCalculated()) {
                    $cells[] = [$column[$index], array_values($column), ['repeater' => $item['key'], 'index' => $index]];
                }
            }
        }

        return $cells;
    }

    /**
     * Every row of a calculated column must compile to the first row's expression.
     *
     * @param  list<MappedField>  $cells
     * @param  array<string, Ref>  $refs
     */
    private function rowsAgree(array $cells, array $refs, string $repeater, string $expected): bool
    {
        foreach ($cells as $cell) {
            $index = $refs[$cell->field->name]['index'] ?? null;
            $result = $cell->calculation === null || $index === null ? null
                : $this->calculations->compile($cell->calculation, $refs, $this->scale($cell), ['repeater' => $repeater, 'index' => $index]);
            if (! is_array($result) || Json::canonical($result['expression']) !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<Item>  $items
     * @param  array<string, string>  $failed
     * @return array<string, Ref>
     */
    private function refs(array $items, array $failed): array
    {
        $refs = [];
        foreach ($items as $item) {
            if (isset($item['field']) && ! isset($failed[$item['field']->field->name])) {
                $refs[$item['field']->field->name] = ['kind' => 'scalar', 'key' => $item['key']];
            }
            if (! isset($item['table'])) {
                continue;
            }
            foreach ($item['table']->columns as $name => $column) {
                foreach ($column as $index => $cell) {
                    if (! isset($failed[$cell->field->name])) {
                        $refs[$cell->field->name] = ['kind' => 'cell', 'repeater' => $item['key'], 'key' => $item['columns'][$name] ?? '',
                            'index' => $index, 'rows' => count($item['table']->indices)];
                    }
                }
            }
        }

        return $refs;
    }

    /**
     * @param  list<Item>  $items
     * @param  array<string, string>  $failed
     * @return list<Item>
     */
    private function withoutFailed(array $items, array $failed): array
    {
        $kept = [];
        foreach ($items as $item) {
            if (isset($item['field'])) {
                if (! isset($failed[$item['field']->field->name])) {
                    $kept[] = $item;
                }

                continue;
            }
            $table = $item['table'] ?? throw new \LogicException('item without table');
            $columns = array_filter($table->columns, static fn (array $cells): bool => ! isset($failed[Table::first($cells)->field->name]));
            if ($columns !== []) {
                $item['table'] = new Table($table->stem, $table->template, $columns, $table->indices, $table->aligned);
                $kept[] = $item;
            }
        }

        return $kept;
    }

    /** @param  array<int, list<stdClass>>  $pages */
    private function definition(string $title, string $locale, array $pages): stdClass
    {
        $definition = BlankDefinition::make(BlankDefinition::keyFromName($title), $title, $locale);
        $definition->pages = [];
        foreach ($pages as $number => $elements) {
            $definition->pages[] = (object) [
                'id' => BlankDefinition::id(),
                'key' => 'page_'.$number,
                'title' => (string) __('forms.import.page_title', ['number' => $number]),
                'elements' => $elements,
            ];
        }

        return $definition;
    }

    /**
     * @param  list<MappedField>  $mapped
     * @param  list<Table>  $tables
     * @param  array<string, string>  $failed
     * @return list<array{code: string, field?: string, page?: int, params?: array<string, int|string>}>
     */
    private function fieldWarnings(array $mapped, array $tables, array $failed): array
    {
        $warnings = [];
        foreach ($mapped as $field) {
            foreach (isset($failed[$field->field->name]) ? [] : $field->warnings as $warning) {
                $warnings[] = [...$warning, 'field' => $field->field->name, 'page' => $field->field->page];
            }
        }
        foreach ($tables as $table) {
            if (! $table->aligned) {
                $warnings[] = ['code' => 'table_not_aligned', 'field' => $table->template, 'page' => $table->page()];
            }
        }

        return $warnings;
    }

    /**
     * @param  list<UnmappedField>  $unmapped
     * @param  list<Table>  $tables
     * @return array<string, int>
     */
    private function stats(AcroFormDocument $document, stdClass $definition, array $unmapped, array $tables): array
    {
        $elements = 0;
        $low = 0;
        $calculated = 0;
        $walk = function (array $nodes) use (&$walk, &$elements, &$low, &$calculated): void {
            foreach ($nodes as $node) {
                $elements++;
                $calculated += ($node->type ?? null) === 'calculated' ? 1 : 0;
                $low += ($node->{'x-import'}->confidence ?? 1.0) < $this->lowConfidence ? 1 : 0;
                $walk(is_array($node->elements ?? null) ? $node->elements : []);
            }
        };
        foreach ($definition->pages as $page) {
            $walk($page->elements);
        }

        return [
            'pages' => $document->pageCount,
            'fields' => count($document->fields),
            'imported' => count($document->fields) - count($unmapped),
            'unmapped' => count($unmapped),
            'elements' => $elements,
            'repeaters' => count(array_filter($tables, static fn (Table $t): bool => $t->columns !== [])),
            'calculated' => $calculated,
            'low_confidence' => $low,
        ];
    }

    /**
     * @param  list<UnmappedField>  $unmapped
     * @return list<UnmappedField>
     */
    private function sortUnmapped(AcroFormDocument $document, array $unmapped): array
    {
        $order = array_flip(array_map(static fn (AcroField $f): string => $f->name, $document->fields));
        usort($unmapped, static fn (UnmappedField $a, UnmappedField $b): int => ($order[$a->field] ?? 0) <=> ($order[$b->field] ?? 0));

        return $unmapped;
    }

    private function pageOf(AcroFormDocument $document, string $name): int
    {
        foreach ($document->fields as $field) {
            if ($field->name === $name) {
                return $field->page;
            }
        }

        return 0;
    }

    private function scale(MappedField $field): int
    {
        $scale = $field->properties['scale'] ?? null;

        return is_int($scale) ? $scale : (($field->properties['format'] ?? null) === 'money' ? 2 : 6);
    }

    private function plural(string $key): string
    {
        return str_ends_with($key, 's') ? $key : $key.'s';
    }
}
