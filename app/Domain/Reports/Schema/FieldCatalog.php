<?php

declare(strict_types=1);

namespace App\Domain\Reports\Schema;

use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use Illuminate\Support\Facades\Log;
use stdClass;

/**
 * The columns of a form's reports: the union of the data keys of ALL its published versions, matched
 * by key (BUILD_PROMPT §8.4, §7.6 "reports join data by key"). Label and type come from the newest
 * version that has the key; options are merged over all versions (newest label wins), and every
 * version's own element is kept (money currency and scale per submission version). Order: the newest
 * version's document order, then keys that only older versions have (newest first). Keys are built
 * exactly like SubmissionValueProjector builds them (truncated to 64 characters; collisions() lists
 * keys that two elements of one version share after truncation).
 */
final readonly class FieldCatalog
{
    private const array LAYOUT = ['section', 'columns', 'heading', 'paragraph', 'divider', 'image', 'page_break'];

    /**
     * @param  array<string, ReportField>  $fields  in column order
     * @param  list<string>  $collisions
     */
    private function __construct(private array $fields, private array $collisions = []) {}

    public static function forForm(Form $form): self
    {
        $definitions = FormVersion::query()->where('form_id', $form->id)->orderByDesc('version')
            ->get(['id', 'organization_id', 'form_id', 'version', 'definition'])
            ->mapWithKeys(fn (FormVersion $version): array => [$version->id => $version->definition])->all();

        $catalog = self::fromDefinitions($definitions);
        if ($catalog->collisions !== []) {
            Log::warning('Report keys collide after truncation to 64 characters.', ['form_id' => $form->id, 'keys' => $catalog->collisions]);
        }

        return $catalog;
    }

    /** @param  iterable<int, stdClass>  $definitions  newest first, keyed by form version id */
    public static function fromDefinitions(iterable $definitions): self
    {
        $found = [];
        $collisions = [];
        foreach ($definitions as $versionId => $definition) {
            $seen = [];
            foreach (is_array($definition->pages ?? null) ? $definition->pages : [] as $page) {
                if ($page instanceof stdClass) {
                    self::scope(is_array($page->elements ?? null) ? $page->elements : [], null, '', (int) $versionId, $found, $seen, $collisions);
                }
            }
        }

        $fields = [];
        foreach ($found as $key => $entry) {
            $fields[$key] = new ReportField((string) $key, $entry['label'], $entry['type'], $entry['element'], $entry['repeater'], $entry['versions']);
        }

        return new self($fields, array_values(array_unique($collisions)));
    }

    /** @return list<string> keys two elements of one version share after truncation (their values mix) */
    public function collisions(): array
    {
        return $this->collisions;
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
     * @param  array<string, array{label: string, type: string, element: stdClass, repeater: ?string, versions: array<int, stdClass>}>  $found
     * @param  array<string, string>  $seen  truncated key => full key within the current version
     * @param  list<string>  $collisions
     */
    private static function scope(array $elements, ?string $repeater, string $prefix, int $versionId, array &$found, array &$seen, array &$collisions): void
    {
        foreach ($elements as $element) {
            if (! $element instanceof stdClass || ! is_string($element->type ?? null) || ! is_string($element->key ?? null)) {
                continue;
            }
            if (in_array($element->type, self::LAYOUT, true)) {
                self::scope(is_array($element->elements ?? null) ? $element->elements : [], $repeater, $prefix, $versionId, $found, $seen, $collisions);
                foreach (is_array($element->columns ?? null) ? $element->columns : [] as $column) {
                    if ($column instanceof stdClass) {
                        self::scope(is_array($column->elements ?? null) ? $column->elements : [], $repeater, $prefix, $versionId, $found, $seen, $collisions);
                    }
                }

                continue;
            }

            $full = $prefix.$element->key;
            $key = mb_substr($full, 0, 64);
            if (isset($seen[$key]) && $seen[$key] !== $full) {
                $collisions[] = $key;
            }
            $seen[$key] = $full;
            $found[$key] ??= ['label' => self::label($element), 'type' => $element->type, 'element' => $element, 'repeater' => $repeater, 'versions' => []];
            $found[$key]['versions'][$versionId] ??= $element;
            if ($element->type === 'repeater') {
                self::scope(is_array($element->elements ?? null) ? $element->elements : [], $key, $key.'.', $versionId, $found, $seen, $collisions);
            }
        }
    }

    private static function label(stdClass $element): string
    {
        return is_string($element->label ?? null) && trim($element->label) !== '' ? trim($element->label) : (string) $element->key;
    }
}
