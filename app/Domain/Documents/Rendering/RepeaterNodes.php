<?php

declare(strict_types=1);

namespace App\Domain\Documents\Rendering;

use stdClass;

/**
 * Repeaters in print (§8.5): a table (header row repeated on every page, rows never split) or one
 * card per row, per the design or — with the "auto" style — the repeater's own `display`.
 *
 * @phpstan-import-type Node from DocumentTree
 */
final readonly class RepeaterNodes
{
    public function __construct(
        private DocumentTree $tree,
        private RenderInput $input,
        private ValueFormatter $format,
    ) {}

    /**
     * @param  list<array{string, int}>  $rowPath
     * @return Node|null
     */
    public function node(stdClass $element, stdClass $scope, array $rowPath): ?array
    {
        $key = DocumentTree::text($element->key ?? '');
        $rows = DocumentTree::rows($scope->{$key} ?? null);
        if ($rows === [] && ! $this->input->design->showEmpty) {
            return null;
        }
        $style = $this->input->design->repeaters === 'auto'
            ? (($element->display ?? 'cards') === 'table' ? 'table' : 'cards')
            : $this->input->design->repeaters;

        return $style === 'table'
            ? $this->table($element, $key, $rows, $rowPath)
            : $this->cards($element, $key, $rows, $rowPath);
    }

    /**
     * @param  array<int, stdClass>  $rows  keyed by the original row index (visibility pointers)
     * @param  list<array{string, int}>  $rowPath
     * @return Node
     */
    private function table(stdClass $element, string $key, array $rows, array $rowPath): array
    {
        $columns = $this->leaves(DocumentTree::list($element->elements ?? null));
        $body = [];
        foreach ($rows as $index => $row) {
            $path = [...$rowPath, [$key, $index]];
            $body[] = array_map(fn (stdClass $column): string => $this->cell($column, $row, $path), $columns);
        }

        return [
            'kind' => 'table',
            'label' => DocumentTree::label($element),
            'headers' => array_map(DocumentTree::label(...), $columns),
            'numeric' => array_map($this->format->isNumeric(...), $columns),
            'rows' => $body,
        ];
    }

    /**
     * @param  array<int, stdClass>  $rows  keyed by the original row index
     * @param  list<array{string, int}>  $rowPath
     * @return Node
     */
    private function cards(stdClass $element, string $key, array $rows, array $rowPath): array
    {
        $itemLabel = DocumentTree::text($element->itemLabel ?? '') ?: DocumentTree::label($element);
        $cards = [];
        foreach ($rows as $index => $row) {
            $cards[] = [
                'title' => $itemLabel.' '.($index + 1),
                'nodes' => $this->tree->nodes(DocumentTree::list($element->elements ?? null), $row, [...$rowPath, [$key, $index]]),
            ];
        }

        return ['kind' => 'cards', 'label' => DocumentTree::label($element), 'cards' => $cards];
    }

    /** @param  list<array{string, int}>  $path */
    private function cell(stdClass $column, stdClass $row, array $path): string
    {
        if (! $this->tree->isShown($column, $path)) {
            return '';
        }
        $value = $row->{DocumentTree::text($column->key ?? '')} ?? null;

        return match ($column->type ?? null) {
            'repeater' => is_array($value) ? (string) count($value) : '',
            'photo', 'file' => is_array($value) ? (string) count($value) : ($value === null ? '' : '1'),
            'signature' => $value instanceof stdClass ? DocumentTree::text($value->signerName ?? '✓') : '',
            default => $this->format->format($column, $value),
        };
    }

    /**
     * Data elements of a row in document order: layout containers flattened, anything hidden from
     * print (`pdf.hidden` on the element or an enclosing container, `hidden` type) left out. Row-level
     * `visibleIf` of containers is already folded into each leaf's visibility by the evaluator.
     *
     * @param  list<stdClass>  $elements
     * @return list<stdClass>
     */
    private function leaves(array $elements): array
    {
        $leaves = [];
        foreach ($elements as $element) {
            $type = $element->type ?? null;
            if ($type === 'hidden' || (($element->pdf ?? null) instanceof stdClass && ($element->pdf->hidden ?? false) === true)) {
                continue;
            }
            if ($type === 'section') {
                $leaves = [...$leaves, ...$this->leaves(DocumentTree::list($element->elements ?? null))];
            } elseif ($type === 'columns') {
                foreach (DocumentTree::list($element->columns ?? null) as $column) {
                    $leaves = [...$leaves, ...$this->leaves(DocumentTree::list($column->elements ?? null))];
                }
            } elseif (! DocumentTree::isLayout($element)) {
                $leaves[] = $element;
            }
        }

        return $leaves;
    }
}
