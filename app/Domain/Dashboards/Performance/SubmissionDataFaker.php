<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Performance;

use Carbon\CarbonImmutable;
use stdClass;

/**
 * Plausible submission data for a form definition (load generator, no Faker so it also runs in the
 * production image) plus the `submission_values` rows the SubmissionValueProjector would write for it.
 * The row shape mirrors the projector; tests re-project generated submissions and compare.
 * Capture elements (photo, file, signature) stay empty: generated submissions have no attachments.
 */
final class SubmissionDataFaker
{
    private const array LAYOUT = ['section', 'columns', 'heading', 'paragraph', 'divider', 'image', 'page_break'];

    private const array NUMERIC = ['number', 'money', 'rating'];

    private const array WORDS = ['Heizung', 'Wartung', 'Filter', 'Pumpe', 'Ventil', 'Leitung', 'Kessel', 'Brenner', 'Thermostat',
        'Rohr', 'Dichtung', 'Speicher', 'Anlage', 'geprüft', 'ersetzt', 'gereinigt', 'eingestellt', 'Druck', 'Abgas', 'Messung'];

    private const array NAMES = ['Müller', 'Schmidt', 'Schneider', 'Fischer', 'Weber', 'Meyer', 'Wagner', 'Becker', 'Hoffmann', 'Schulz'];

    /** @var list<array{key: string, path: string, role: ?string, value_text: string, value_num: ?string, value_date: ?string}> */
    private array $rows = [];

    /**
     * @return array{data: stdClass, rows: list<array{key: string, path: string, role: ?string, value_text: string, value_num: ?string, value_date: ?string}>}
     */
    public function make(stdClass $definition, CarbonImmutable $at): array
    {
        $this->rows = [];
        $data = new stdClass;
        foreach (is_array($definition->pages ?? null) ? $definition->pages : [] as $page) {
            if ($page instanceof stdClass) {
                $this->scope(is_array($page->elements ?? null) ? $page->elements : [], $data, '', '', $at);
            }
        }

        return ['data' => $data, 'rows' => $this->rows];
    }

    /** @param  array<array-key, mixed>  $elements */
    private function scope(array $elements, stdClass $data, string $pointer, string $keyPrefix, CarbonImmutable $at): void
    {
        foreach ($elements as $element) {
            if (! $element instanceof stdClass || ! is_string($element->type ?? null) || ! is_string($element->key ?? null)) {
                continue;
            }
            if (in_array($element->type, self::LAYOUT, true)) {
                $this->scope(is_array($element->elements ?? null) ? $element->elements : [], $data, $pointer, $keyPrefix, $at);
                foreach (is_array($element->columns ?? null) ? $element->columns : [] as $column) {
                    if ($column instanceof stdClass) {
                        $this->scope(is_array($column->elements ?? null) ? $column->elements : [], $data, $pointer, $keyPrefix, $at);
                    }
                }

                continue;
            }
            $path = $pointer.'/'.$element->key;
            if ($element->type === 'repeater') {
                $data->{$element->key} = $this->repeater($element, $path, $keyPrefix, $at);

                continue;
            }
            $value = $this->value($element, $at);
            if ($value === null) {
                continue;
            }
            $data->{$element->key} = $value;
            $this->rows[] = $this->row($element, $value, $path, $keyPrefix.$element->key);
        }
    }

    /** @return list<stdClass> */
    private function repeater(stdClass $element, string $path, string $keyPrefix, CarbonImmutable $at): array
    {
        $rows = [];
        $count = mt_rand(1, 4);
        for ($i = 0; $i < $count; $i++) {
            $row = new stdClass;
            $this->scope(is_array($element->elements ?? null) ? $element->elements : [], $row, $path.'/'.$i, $keyPrefix.$element->key.'.', $at);
            $rows[] = $row;
        }

        return $rows;
    }

    private function value(stdClass $element, CarbonImmutable $at): mixed
    {
        return match ($element->type) {
            'text', 'hidden' => self::NAMES[array_rand(self::NAMES)].' '.self::WORDS[array_rand(self::WORDS)],
            'textarea' => $this->sentence(mt_rand(6, 18)),
            'email' => 'kunde'.mt_rand(1, 99999).'@example.com',
            'phone' => '+49 30 '.mt_rand(1000000, 9999999),
            'number' => mt_rand(1, 20),
            'money' => $this->decimal(mt_rand(500, 25000)),
            'rating' => mt_rand(1, 5),
            'calculated' => ($element->format ?? 'number') === 'text' ? $this->sentence(2) : $this->decimal(mt_rand(1000, 500000)),
            'date' => $at->subDays(mt_rand(0, 30))->toDateString(),
            'datetime' => $at->subMinutes(mt_rand(0, 600))->toIso8601String(),
            'time' => sprintf('%02d:%02d', mt_rand(7, 18), mt_rand(0, 3) * 15),
            'select', 'radio' => $this->option($element),
            'multiselect' => array_values(array_unique(array_filter([$this->option($element), $this->option($element)]))),
            'checkbox', 'toggle' => mt_rand(0, 9) > 1,
            'location' => (object) ['lat' => 52.5 + mt_rand(-5000, 5000) / 10000, 'lng' => 13.4 + mt_rand(-5000, 5000) / 10000, 'accuracy' => mt_rand(3, 30)],
            default => null, // photo, file, signature and unknown types: no attachments in generated data
        };
    }

    private function option(stdClass $element): ?string
    {
        $options = is_array($element->options ?? null) ? $element->options : [];
        if ($options === []) {
            return null;
        }
        $option = $options[array_rand($options)];

        return $option instanceof stdClass && is_scalar($option->value ?? null) ? (string) $option->value : null;
    }

    private function sentence(int $words): string
    {
        $picked = [];
        for ($i = 0; $i < $words; $i++) {
            $picked[] = self::WORDS[array_rand(self::WORDS)];
        }

        return ucfirst(implode(' ', $picked)).'.';
    }

    private function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @return array{key: string, path: string, role: ?string, value_text: string, value_num: ?string, value_date: ?string} */
    private function row(stdClass $element, mixed $value, string $path, string $key): array
    {
        $numeric = in_array($element->type, self::NUMERIC, true)
            || ($element->type === 'calculated' && ($element->format ?? 'number') !== 'text');

        return [
            'key' => mb_substr($key, 0, 64),
            'path' => mb_substr($path, 0, 255),
            'role' => is_string($element->role ?? null) ? $element->role : null,
            'value_text' => is_scalar($value) ? (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'value_num' => $numeric && is_numeric($value) ? (string) $value : null,
            'value_date' => in_array($element->type, ['date', 'datetime'], true) && is_string($value) ? substr($value, 0, 10) : null,
        ];
    }
}
