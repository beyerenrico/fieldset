<?php

declare(strict_types=1);

namespace App\Domain\Documents\Rendering;

use Illuminate\Support\Carbon;
use stdClass;

/**
 * Sample submission data per element type for the builder's "Preview PDF" (§8.2): plausible values,
 * three repeater rows, placeholder photos and a signature. Calculated values are left to the
 * evaluator, so the preview shows real totals.
 */
final readonly class SampleData
{
    public const string PHOTO_UUID = '00000000-0000-7000-8000-00000000f070';

    public const string PHOTO_UUID_2 = '00000000-0000-7000-8000-00000000f071';

    public const string SIGNATURE_UUID = '00000000-0000-7000-8000-0000000051a0';

    public const string FILE_UUID = '00000000-0000-7000-8000-00000000f11e';

    private const int REPEATER_ROWS = 3;

    public function __construct(private Carbon $now) {}

    public function for(stdClass $definition): stdClass
    {
        $data = new stdClass;
        foreach (DocumentTree::list($definition->pages ?? null) as $page) {
            $this->fill(DocumentTree::list($page->elements ?? null), $data, 0);
        }

        return $data;
    }

    /** @return array<string, RenderAsset> */
    public static function assets(): array
    {
        return [
            self::PHOTO_UUID => RenderAsset::fromBytes('photo', 'image/png', PlaceholderImages::photo()),
            self::PHOTO_UUID_2 => RenderAsset::fromBytes('photo', 'image/png', PlaceholderImages::photo()),
            self::SIGNATURE_UUID => RenderAsset::fromBytes('signature', 'image/png', PlaceholderImages::signature()),
            self::FILE_UUID => new RenderAsset('file', 'application/pdf', hash('sha256', 'sample'), name: 'document.pdf'),
        ];
    }

    /** @param  list<stdClass>  $elements */
    private function fill(array $elements, stdClass $scope, int $row): void
    {
        foreach ($elements as $element) {
            $type = (string) ($element->type ?? '');
            $key = DocumentTree::text($element->key ?? '');
            if ($type === 'section') {
                $this->fill(DocumentTree::list($element->elements ?? null), $scope, $row);
            } elseif ($type === 'columns') {
                foreach (DocumentTree::list($element->columns ?? null) as $column) {
                    $this->fill(DocumentTree::list($column->elements ?? null), $scope, $row);
                }
            } elseif ($type === 'repeater') {
                $scope->{$key} = $this->rows($element);
            } elseif ($key !== '' && ($value = $this->value($element, $row)) !== null) {
                $scope->{$key} = $value;
            }
        }
    }

    /** @return list<stdClass> */
    private function rows(stdClass $element): array
    {
        $count = max(self::REPEATER_ROWS, is_int($element->minItems ?? null) ? $element->minItems : 0);
        $count = is_int($element->maxItems ?? null) ? min($count, $element->maxItems) : $count;
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $row = new stdClass;
            $this->fill(DocumentTree::list($element->elements ?? null), $row, $i + 1);
            $rows[] = $row;
        }

        return $rows;
    }

    private function value(stdClass $element, int $row): mixed
    {
        $label = DocumentTree::label($element);
        $options = array_values(array_filter(array_map(static fn (stdClass $o): mixed => $o->value ?? null, DocumentTree::list($element->options ?? null)), is_string(...)));
        $validation = $element->validation ?? null;
        $min = $validation instanceof stdClass && is_numeric($validation->min ?? null) ? (string) $validation->min : null;

        return match ((string) ($element->type ?? '')) {
            'text' => $row > 0 ? "{$label} {$row}" : (string) __('documents.sample.text', ['label' => $label]),
            'textarea' => (string) __('documents.sample.textarea'),
            'email' => 'max.mustermann@example.com',
            'phone' => '+49 30 1234567',
            'number' => $min !== null && (float) $min > 0 ? $min : (string) max(1, $row),
            'money' => number_format(19.5 * max(1, $row) + 80, is_int($element->scale ?? null) ? $element->scale : 2, '.', ''),
            'date' => $this->now->format('Y-m-d'),
            'time' => '09:30',
            'datetime' => $this->now->format('Y-m-d\TH:i:sP'),
            'select', 'radio' => $options[0] ?? null,
            'multiselect' => array_slice($options, 0, 2),
            'checkbox', 'toggle' => true,
            'rating' => max(1, (is_int($element->max ?? null) ? $element->max : 5) - 1),
            'photo' => ($element->multiple ?? false) === true ? [self::PHOTO_UUID, self::PHOTO_UUID_2] : self::PHOTO_UUID,
            'file' => ($element->multiple ?? false) === true ? [self::FILE_UUID] : self::FILE_UUID,
            'signature' => (object) ['attachment' => self::SIGNATURE_UUID, 'signerName' => 'Max Mustermann', 'signedAt' => $this->now->format('Y-m-d\TH:i:sP')],
            'location' => (object) ['lat' => 52.520008, 'lng' => 13.404954, 'accuracy' => 8, 'capturedAt' => $this->now->format('Y-m-d\TH:i:sP')],
            default => null,
        };
    }
}
