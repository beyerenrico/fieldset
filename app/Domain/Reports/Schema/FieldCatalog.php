<?php

declare(strict_types=1);

namespace App\Domain\Reports\Schema;

use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use stdClass;

/**
 * The columns of a form's reports: the union of the data keys of ALL its published versions, matched
 * by key (BUILD_PROMPT §8.4, §7.6 "reports join data by key"). Label, type and options come from the
 * newest version that has the key. Order: the newest version's document order, then keys that only
 * older versions have (newest first). Keys are built exactly like SubmissionValueProjector builds them.
 */
final readonly class FieldCatalog
{
    private const array LAYOUT = ['section', 'columns', 'heading', 'paragraph', 'divider', 'image', 'page_break'];

    /** @param  array<string, ReportField>  $fields  in column order */
    private function __construct(private array $fields) {}

    public static function forForm(Form $form): self
    {
        $definitions = FormVersion::query()->where('form_id', $form->id)->orderByDesc('version')
            ->get(['id', 'organization_id', 'form_id', 'version', 'definition'])
            ->map(fn (FormVersion $version): stdClass => $version->definition)->all();

        return self::fromDefinitions($definitions);
    }

    /** @param  iterable<stdClass>  $definitions  newest first */
    public static function fromDefinitions(iterable $definitions): self
    {
        $fields = [];
        foreach ($definitions as $definition) {
            foreach (is_array($definition->pages ?? null) ? $definition->pages : [] as $page) {
                if ($page instanceof stdClass) {
                    self::scope(is_array($page->elements ?? null) ? $page->elements : [], null, '', $fields);
                }
            }
        }

        return new self($fields);
    }

    /** @return list<ReportField> */
    public function all(): array
    {
        return array_values($this->fields);
    }

    public function get(string $key): ?ReportField
    {
        return $this->fields[$key] ?? null;
    }

    /** @return list<ReportField> top-level keys (repeaters included, as one column each) */
    public function topLevel(): array
    {
        return $this->where(fn (ReportField $f): bool => $f->isTopLevel());
    }

    /** @return list<ReportField> every repeater, nested ones included */
    public function repeaters(): array
    {
        return $this->where(fn (ReportField $f): bool => $f->isRepeater());
    }

    /** @return list<ReportField> value keys of one repeater's rows (nested repeaters excluded) */
    public function children(string $repeaterKey): array
    {
        return $this->where(fn (ReportField $f): bool => $f->repeater === $repeaterKey && ! $f->isRepeater());
    }

    /** @return list<ReportField> keys a sum/avg can run over (repeater children included) */
    public function numeric(): array
    {
        return $this->where(fn (ReportField $f): bool => $f->isNumeric());
    }

    /** @return list<ReportField> top-level keys with a field-value filter */
    public function filterable(): array
    {
        return $this->where(fn (ReportField $f): bool => $f->isTopLevel() && $f->filterKind() !== null);
    }

    /** @return list<ReportField> */
    public function groupable(): array
    {
        return $this->where(fn (ReportField $f): bool => $f->isGroupable());
    }

    /**
     * @param  callable(ReportField): bool  $keep
     * @return list<ReportField>
     */
    private function where(callable $keep): array
    {
        return array_values(array_filter($this->fields, $keep));
    }

    /**
     * @param  array<array-key, mixed>  $elements
     * @param  array<string, ReportField>  $fields
     */
    private static function scope(array $elements, ?string $repeater, string $prefix, array &$fields): void
    {
        foreach ($elements as $element) {
            if (! $element instanceof stdClass || ! is_string($element->type ?? null) || ! is_string($element->key ?? null)) {
                continue;
            }
            if (in_array($element->type, self::LAYOUT, true)) {
                self::scope(is_array($element->elements ?? null) ? $element->elements : [], $repeater, $prefix, $fields);
                foreach (is_array($element->columns ?? null) ? $element->columns : [] as $column) {
                    if ($column instanceof stdClass) {
                        self::scope(is_array($column->elements ?? null) ? $column->elements : [], $repeater, $prefix, $fields);
                    }
                }

                continue;
            }

            $key = mb_substr($prefix.$element->key, 0, 64);
            $fields[$key] ??= new ReportField($key, self::label($element), $element->type, $element, $repeater);
            if ($element->type === 'repeater') {
                self::scope(is_array($element->elements ?? null) ? $element->elements : [], $key, $key.'.', $fields);
            }
        }
    }

    private static function label(stdClass $element): string
    {
        return is_string($element->label ?? null) && trim($element->label) !== '' ? trim($element->label) : (string) $element->key;
    }
}
