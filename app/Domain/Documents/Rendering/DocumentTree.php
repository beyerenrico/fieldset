<?php

declare(strict_types=1);

namespace App\Domain\Documents\Rendering;

use Fieldset\Evaluation\Instance;
use stdClass;

/**
 * Walks the definition with the evaluated data and builds the print tree the Blade views render:
 * pages of nodes (fields, sections, columns, repeaters, photos, signatures, layout). Visibility comes
 * from the evaluation (spec §5.5), `pdf` hints (hidden, label, width, keepTogether) are honoured and
 * empty fields follow the design's "show empty fields" setting. Values are formatted, never escaped
 * here: the views escape everything except sanitized rich text.
 *
 * @phpstan-type Node array<string, mixed>
 */
final class DocumentTree
{
    private const array LAYOUT = ['section', 'columns', 'heading', 'paragraph', 'divider', 'image', 'page_break'];

    /** @var list<Node> signature blocks collected for the end of the document */
    private array $signatures = [];

    private readonly RepeaterNodes $repeaters;

    public function __construct(
        private readonly RenderInput $input,
        private readonly ValueFormatter $format,
        private readonly AssetInliner $assets,
        private readonly RichTextSanitizer $sanitizer,
    ) {
        $this->repeaters = new RepeaterNodes($this, $input, $format, $assets);
    }

    /** @return array{pages: list<array{title: string, nodes: list<Node>}>, signatures: list<Node>} */
    public function build(): array
    {
        $pages = [];
        foreach (self::list($this->input->definition->pages ?? null) as $page) {
            $nodes = $this->nodes(self::list($page->elements ?? null), $this->input->data, []);
            if ($nodes !== []) {
                $pages[] = ['title' => self::text($page->title ?? ''), 'nodes' => $nodes];
            }
        }

        return ['pages' => $pages, 'signatures' => $this->signatures];
    }

    /**
     * @param  list<stdClass>  $elements
     * @param  list<array{string, int}>  $rowPath
     * @return list<Node>
     */
    public function nodes(array $elements, stdClass $scope, array $rowPath): array
    {
        $nodes = [];
        foreach ($elements as $element) {
            $node = $this->node($element, $scope, $rowPath);
            if ($node !== null) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /** @param  list<array{string, int}>  $rowPath */
    public function isShown(stdClass $element, array $rowPath): bool
    {
        $pdf = $element->pdf ?? null;
        if (($pdf instanceof stdClass && ($pdf->hidden ?? false) === true) || ($element->type ?? null) === 'hidden') {
            return false;
        }

        return $this->input->visibility[Instance::pointerFor($rowPath, self::text($element->key ?? ''))] ?? true;
    }

    public static function label(stdClass $element): string
    {
        $pdf = $element->pdf ?? null;

        return $pdf instanceof stdClass && is_string($pdf->label ?? null) ? $pdf->label : self::text($element->label ?? '');
    }

    public static function isLayout(stdClass $element): bool
    {
        return in_array($element->type ?? null, self::LAYOUT, true);
    }

    /**
     * @param  list<array{string, int}>  $rowPath
     * @return Node|null
     */
    private function node(stdClass $element, stdClass $scope, array $rowPath): ?array
    {
        if (! $this->isShown($element, $rowPath)) {
            return null;
        }
        $node = match ((string) ($element->type ?? '')) {
            'section' => $this->section($element, $scope, $rowPath),
            'columns' => $this->columns($element, $scope, $rowPath),
            'heading' => ['kind' => 'heading', 'text' => self::text($element->text ?? ''), 'level' => max(1, min(3, is_int($element->level ?? null) ? $element->level : 2))],
            'paragraph' => ['kind' => 'paragraph', 'html' => $this->sanitizer->markdown(self::text($element->text ?? ''))],
            'divider' => ['kind' => 'divider'],
            'page_break' => ['kind' => 'page_break'],
            'image' => ($src = AssetInliner::inlineImage($element->src ?? null)) === null ? null : ['kind' => 'image', 'src' => $src, 'alt' => self::text($element->alt ?? '')],
            'repeater' => $this->repeaters->node($element, $scope, $rowPath),
            'photo' => $this->photos($element, $scope),
            'signature' => $this->signature($element, $scope),
            default => $this->field($element, $scope),
        };

        return $node === null ? null : [...$node, ...$this->hints($element)];
    }

    /**
     * @param  list<array{string, int}>  $rowPath
     * @return Node|null
     */
    private function section(stdClass $element, stdClass $scope, array $rowPath): ?array
    {
        $nodes = $this->nodes(self::list($element->elements ?? null), $scope, $rowPath);

        return $nodes === [] ? null : ['kind' => 'section', 'title' => self::label($element), 'nodes' => $nodes];
    }

    /**
     * @param  list<array{string, int}>  $rowPath
     * @return Node|null
     */
    private function columns(stdClass $element, stdClass $scope, array $rowPath): ?array
    {
        $columns = [];
        foreach (self::list($element->columns ?? null) as $column) {
            $columns[] = $this->nodes(self::list($column->elements ?? null), $scope, $rowPath);
        }

        $filled = array_filter($columns, static fn (array $nodes): bool => $nodes !== []);

        return $filled === [] ? null : ['kind' => 'columns', 'columns' => $columns];
    }

    /** @return Node|null */
    public function field(stdClass $element, stdClass $scope): ?array
    {
        $raw = $scope->{self::text($element->key ?? '')} ?? null;
        $value = ($element->type ?? null) === 'file' ? $this->fileNames($raw) : $this->format->format($element, $raw);
        if ($value === '' && ! $this->input->design->showEmpty) {
            return null;
        }

        return [
            'kind' => 'field',
            'label' => self::label($element),
            'value' => $value === '' ? '—' : $value,
            'multiline' => ($element->type ?? null) === 'textarea',
            'numeric' => $this->format->isNumeric($element),
        ];
    }

    /**
     * Photos that cannot be printed (unreadable, or above the renderer's pixel limit) are counted, so the
     * document says so instead of dropping them silently (§8.5 "nothing is ever truncated").
     *
     * @return Node|null
     */
    private function photos(stdClass $element, stdClass $scope): ?array
    {
        $raw = $scope->{self::text($element->key ?? '')} ?? null;
        $uuids = is_array($raw) ? $raw : ($raw === null ? [] : [$raw]);
        $images = array_values(array_filter(array_map(fn (mixed $uuid): ?string => $this->assets->attachment($this->input, $uuid), $uuids)));
        $omitted = count($uuids) - count($images);
        if ($images === [] && $omitted === 0 && ! $this->input->design->showEmpty) {
            return null;
        }

        return [
            'kind' => 'photos', 'label' => self::label($element), 'images' => $images, 'omitted' => $omitted,
            'columns' => $this->input->design->photoColumns,
        ];
    }

    /** @return Node|null */
    private function signature(stdClass $element, stdClass $scope): ?array
    {
        $raw = $scope->{self::text($element->key ?? '')} ?? null;
        if (! $raw instanceof stdClass && ! $this->input->design->showEmpty) {
            return null;
        }
        $node = [
            'kind' => 'signature',
            'label' => self::label($element),
            'image' => $raw instanceof stdClass ? $this->assets->attachment($this->input, $raw->attachment ?? null) : null,
            'signer' => $raw instanceof stdClass ? self::text($raw->signerName ?? '') : '',
            'signed_at' => $raw instanceof stdClass ? $this->format->dateTime($raw->signedAt ?? '') : '',
        ];
        if ($this->input->design->signaturePlacement === 'end') {
            $this->signatures[] = $node;

            return null;
        }

        return $node;
    }

    /** Names of the uploaded files of a file element (the uuid when no name is known). */
    public function fileNames(mixed $raw): string
    {
        $names = [];
        foreach (is_array($raw) ? $raw : ($raw === null ? [] : [$raw]) as $uuid) {
            $asset = $this->input->asset($uuid);
            $names[] = $asset instanceof RenderAsset && $asset->name !== null ? $asset->name : self::text($uuid);
        }

        return implode(', ', array_filter($names));
    }

    /** @return array{width: string, keep: bool} */
    private function hints(stdClass $element): array
    {
        $pdf = $element->pdf ?? null;
        $width = $pdf instanceof stdClass && in_array($pdf->width ?? null, ['half', 'third'], true) ? (string) $pdf->width : 'full';

        return ['width' => $width, 'keep' => $pdf instanceof stdClass && ($pdf->keepTogether ?? false) === true];
    }

    /**
     * Repeater rows keyed by their original index, so visibility pointers (`/items/3/qty`) stay aligned
     * even if malformed rows are skipped.
     *
     * @return array<int, stdClass>
     */
    public static function rows(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? array_filter($value, static fn (mixed $v): bool => $v instanceof stdClass) : [];
    }

    /** @return list<stdClass> */
    public static function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, static fn (mixed $v): bool => $v instanceof stdClass)) : [];
    }

    public static function text(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : '');
    }
}
