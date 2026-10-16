<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Admin;

use App\Domain\Submissions\Models\Attachment;
use Closure;
use stdClass;

/**
 * Turns submission data into a read-only view model with the labels of the pinned form version
 * (M3-T09): pages → items. Item kinds:
 * - field: {label, value} (formatted string)
 * - group: {label, items} (a section)
 * - table: {label, columns, rows} (a repeater; cells are items)
 * - media: {label, files: list<{name, url, image, missing}>, caption} (photo, file, signature)
 *
 * Elements whose key is absent from the data (invisible or never filled) are left out; layout-only
 * elements (heading, paragraph, image, divider, page break) carry no data and are skipped.
 */
final readonly class SubmissionDataPresenter
{
    private const array LAYOUT = ['heading', 'paragraph', 'divider', 'image', 'page_break'];

    /**
     * @param  array<string, Attachment>  $attachments  by lowercase uuid
     * @param  Closure(Attachment): string  $attachmentUrl
     */
    public function __construct(private ValueFormatter $formatter, private array $attachments, private Closure $attachmentUrl) {}

    /** @return list<array{title: string, items: list<array<string, mixed>>}> */
    public function pages(stdClass $definition, stdClass $data): array
    {
        $pages = [];
        foreach (self::list($definition->pages ?? null) as $page) {
            $items = $this->items(self::list($page->elements ?? null), $data);
            if ($items !== []) {
                $pages[] = ['title' => self::text($page->title ?? null, self::text($page->key ?? null, '')), 'items' => $items];
            }
        }

        return $pages;
    }

    /**
     * @param  list<stdClass>  $elements
     * @return list<array<string, mixed>>
     */
    private function items(array $elements, stdClass $scope): array
    {
        $items = [];
        foreach ($elements as $element) {
            $type = self::text($element->type ?? null, '');
            if ($type === 'section') {
                $children = $this->items(self::list($element->elements ?? null), $scope);
                if ($children !== []) {
                    $items[] = ['kind' => 'group', 'label' => self::text($element->label ?? null, ''), 'items' => $children];
                }
            } elseif ($type === 'columns') {
                foreach (self::list($element->columns ?? null) as $column) {
                    array_push($items, ...$this->items(self::list($column->elements ?? null), $scope));
                }
            } elseif (! in_array($type, self::LAYOUT, true) && property_exists($scope, self::text($element->key ?? null, ''))) {
                $items[] = $this->item($element, $scope->{self::text($element->key ?? null, '')});
            }
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function item(stdClass $element, mixed $value): array
    {
        $label = self::text($element->label ?? null, self::text($element->key ?? null, ''));

        return match ($element->type ?? null) {
            'repeater' => $this->table($element, $label, $value),
            'photo', 'file' => ['kind' => 'media', 'label' => $label, 'files' => $this->files(is_array($value) ? $value : [$value]), 'caption' => null],
            'signature' => $this->signature($label, $value),
            default => ['kind' => 'field', 'label' => $label, 'value' => $this->formatter->format($element, $value)],
        };
    }

    /** @return array<string, mixed> */
    private function table(stdClass $element, string $label, mixed $rows): array
    {
        $columns = $this->dataElements(self::list($element->elements ?? null));
        $cells = fn (mixed $row): array => array_map(
            fn (stdClass $column): array => $row instanceof stdClass && property_exists($row, self::text($column->key ?? null, ''))
                ? $this->item($column, $row->{self::text($column->key ?? null, '')})
                : ['kind' => 'field', 'label' => self::text($column->label ?? null, ''), 'value' => ValueFormatter::EMPTY],
            $columns,
        );

        return [
            'kind' => 'table',
            'label' => $label,
            'columns' => array_map(fn (stdClass $c): string => self::text($c->label ?? null, self::text($c->key ?? null, '')), $columns),
            'rows' => array_map($cells, is_array($rows) ? array_values($rows) : []),
        ];
    }

    /** @return array<string, mixed> */
    private function signature(string $label, mixed $value): array
    {
        if (! $value instanceof stdClass) {
            return ['kind' => 'field', 'label' => $label, 'value' => ValueFormatter::EMPTY];
        }

        $signedAt = is_string($value->signedAt ?? null) ? $this->formatter->dateTime($value->signedAt) : null;
        $caption = implode(' · ', array_filter([self::text($value->signerName ?? null, ''), $signedAt], fn (?string $s): bool => $s !== null && $s !== ''));

        return ['kind' => 'media', 'label' => $label, 'files' => $this->files([$value->attachment ?? null]), 'caption' => $caption !== '' ? $caption : null];
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @return list<array{name: string, url: string|null, image: bool, missing: bool}>
     */
    private function files(array $ids): array
    {
        $files = [];
        foreach ($ids as $id) {
            if (! is_string($id) || $id === '') {
                continue;
            }
            $attachment = $this->attachments[strtolower($id)] ?? null;
            $files[] = $attachment === null
                ? ['name' => $id, 'url' => null, 'image' => false, 'missing' => true]
                : [
                    'name' => $attachment->original_name ?? $attachment->kind.' '.substr($attachment->uuid, 0, 8),
                    'url' => ($this->attachmentUrl)($attachment),
                    'image' => str_starts_with($attachment->mime, 'image/'),
                    'missing' => false,
                ];
        }

        return $files;
    }

    /**
     * Data elements of a repeater row, with sections and columns flattened (table columns).
     *
     * @param  list<stdClass>  $elements
     * @return list<stdClass>
     */
    private function dataElements(array $elements): array
    {
        $result = [];
        foreach ($elements as $element) {
            $type = self::text($element->type ?? null, '');
            if ($type === 'section') {
                array_push($result, ...$this->dataElements(self::list($element->elements ?? null)));
            } elseif ($type === 'columns') {
                foreach (self::list($element->columns ?? null) as $column) {
                    array_push($result, ...$this->dataElements(self::list($column->elements ?? null)));
                }
            } elseif (! in_array($type, self::LAYOUT, true)) {
                $result[] = $element;
            }
        }

        return $result;
    }

    /** @return list<stdClass> */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, fn (mixed $v): bool => $v instanceof stdClass)) : [];
    }

    private static function text(mixed $value, string $fallback): string
    {
        return is_string($value) && $value !== '' ? $value : $fallback;
    }
}
