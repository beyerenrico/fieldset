<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import\Mapping;

use App\Domain\Forms\Import\Model\AcroFormDocument;
use App\Domain\Forms\Import\Model\TextRun;
use App\Domain\Forms\Support\BlankDefinition;
use stdClass;

/**
 * Writes spec elements for mapped fields and tables: a fresh UUIDv7 id, label, type properties and
 * the `x-import` provenance `{source, field, page, rect, confidence}` (spec §9).
 */
final readonly class ElementWriter
{
    /** Repeater labels come from page text or the name stem; never from a tooltip. */
    private const float REPEATER_NAME_CONFIDENCE = 0.7;

    private const float REPEATER_ABOVE_GAP = 40.0;

    private const float UNALIGNED_TABLE_CONFIDENCE = 0.65;

    public function __construct(private LabelResolver $labels, private AcroFormDocument $document) {}

    public function field(MappedField $mapped, string $key, ?stdClass $expression, float $cap = 1.0): stdClass
    {
        $field = $mapped->field;
        $label = $this->labels->resolve($field, $this->document->textsOnPage($field->page), $field->name);
        $confidence = min($mapped->confidence, LabelResolver::CONFIDENCE[$label['source']], $cap);

        return $this->element($mapped, $key, $label['label'], $expression, $field->name, $field->page, $field->rect, $confidence);
    }

    /**
     * @param  array<string, string>  $columnKeys  column name => key
     * @param  array<string, stdClass>  $expressions  PDF field name of a first-row cell => expression
     * @param  array<string, float>  $caps  PDF field name => confidence cap
     */
    public function repeater(Table $table, string $key, array $columnKeys, array $expressions, array $caps): stdClass
    {
        $texts = $this->document->textsOnPage($table->page());
        $structure = $table->aligned ? 1.0 : self::UNALIGNED_TABLE_CONFIDENCE;
        $elements = [];
        $columnLabels = [];
        foreach ($table->columns as $name => $cells) {
            $first = Table::first($cells);
            $label = $this->labels->resolve($first->field, $texts, (string) $name, self::rowTooltip($cells));
            $columnLabels[] = $label['label'];
            $confidence = min($first->confidence, LabelResolver::CONFIDENCE[$label['source']], $structure, $caps[$first->field->name] ?? 1.0);
            $elements[] = $this->element($first, $columnKeys[$name], $label['label'], $expressions[$first->field->name] ?? null,
                $first->field->name, $first->field->page, $first->field->rect, $confidence, inRow: true);
        }

        [$label, $labelConfidence] = $this->repeaterLabel($table, $key, $texts, $columnLabels);
        $repeater = new stdClass;
        $repeater->id = BlankDefinition::id();
        $repeater->type = 'repeater';
        $repeater->key = $key;
        $repeater->label = $label;
        $repeater->display = 'table';
        $repeater->elements = $elements;
        $repeater->{'x-import'} = self::provenance($table->template, $table->page(), $table->rect(), min($structure * 0.9, $labelConfidence));

        return $repeater;
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $rect
     */
    private function element(MappedField $mapped, string $key, string $label, ?stdClass $expression, string $field, int $page, array $rect, float $confidence, bool $inRow = false): stdClass
    {
        $element = new stdClass;
        $element->id = BlankDefinition::id();
        $element->type = $mapped->type;
        $element->key = $key;
        $element->label = $label;
        foreach ($mapped->properties as $property => $value) {
            if ($inRow && $property === 'required' && $mapped->type === 'checkbox') {
                continue; // a required checkbox per row would force every row to be ticked
            }
            $element->{$property} = match ($property) {
                'validation' => (object) $value,
                'options' => array_map(static fn (array $o): stdClass => (object) $o, is_array($value) ? $value : []),
                default => $value,
            };
        }
        if ($mapped->isCalculated() && $expression !== null) {
            $element->expression = $expression;
        }
        $element->{'x-import'} = self::provenance($field, $page, $rect, $confidence);

        return $element;
    }

    /**
     * @param  list<TextRun>  $texts
     * @param  list<string>  $columnLabels
     * @return array{0: string, 1: float}
     */
    private function repeaterLabel(Table $table, string $key, array $texts, array $columnLabels): array
    {
        if (count($table->columns) === 1) {
            return [$columnLabels[0], self::REPEATER_NAME_CONFIDENCE];
        }
        $candidates = array_values(array_filter($texts, static fn ($run): bool => ! in_array($run->text, $columnLabels, true)));
        $rect = $table->rect();
        $above = $this->labels->nearestText([$rect[0], $rect[1], $rect[2], $rect[3]], $candidates, self::REPEATER_ABOVE_GAP);
        if ($above !== null) {
            return [$above, LabelResolver::CONFIDENCE[LabelResolver::TEXT]];
        }

        return [LabelResolver::humanize($key), self::REPEATER_NAME_CONFIDENCE];
    }

    /**
     * A tooltip shared by the column: identical in every row, or the first row's with its row number removed.
     *
     * @param  array<int, MappedField>  $cells
     */
    private static function rowTooltip(array $cells): ?string
    {
        $tips = array_map(static fn (MappedField $c): ?string => $c->field->tooltip, $cells);
        $first = array_key_first($tips);
        if ($first === null || $tips[$first] === null) {
            return null;
        }
        if (count(array_unique(array_map('strval', $tips))) === 1) {
            return $tips[$first];
        }
        $stripped = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/(?<!\d)'.$first.'(?!\d)/', '', $tips[$first])), " \t-:#.");

        return $stripped === '' ? null : $stripped;
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $rect
     */
    private static function provenance(string $field, int $page, array $rect, float $confidence): stdClass
    {
        return (object) [
            'source' => 'acroform',
            'field' => $field,
            'page' => $page,
            'rect' => array_map(static fn (float $n): float => round($n, 2), array_values($rect)),
            'confidence' => round(max(0.0, min(1.0, $confidence)), 2),
        ];
    }
}
