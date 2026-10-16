<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import\Qpdf;

use App\Domain\Forms\Import\Model\AcroField;
use App\Domain\Forms\Import\Model\AcroFormDocument;
use App\Domain\Forms\Import\Model\TextRun;

/**
 * Turns qpdf JSON v2 (`acroform`, `pages`, `qpdf` keys; ADR 0024) into an AcroFormDocument.
 * qpdf lists one entry per widget; entries with the same full name are one field (e.g. a radio
 * group). Tooltips, actions and options live on the terminal field dictionary, i.e. the nearest
 * dictionary with /T in the widget's /Parent chain. Field values (/V) are never read.
 */
final class QpdfDocumentParser
{
    /**
     * @param  array<string, mixed>  $structure  first-pass qpdf JSON
     * @param  array<string, string>  $streams  `N G R` => decoded stream bytes (second pass)
     */
    public function parse(array $structure, array $streams = []): AcroFormDocument
    {
        $objects = new QpdfObjects(self::objectTable($structure), $streams);
        $pages = self::pages($structure);

        return new AcroFormDocument(
            count($pages),
            $this->fields($objects, self::fieldEntries($structure)),
            $this->texts($objects, $pages),
            $this->title($objects),
        );
    }

    /**
     * Streams the second pass must fetch: page contents and JavaScript actions stored as streams.
     *
     * @param  array<string, mixed>  $structure
     * @return list<string>
     */
    public function streamRefs(array $structure): array
    {
        $objects = new QpdfObjects(self::objectTable($structure));
        $refs = [];
        foreach (self::pages($structure) as $page) {
            foreach (is_array($page['contents'] ?? null) ? $page['contents'] : [] as $ref) {
                $refs[] = $ref;
            }
        }
        foreach (self::fieldEntries($structure) as $entry) {
            foreach ($this->actionDictionaries($objects, $entry) as $action) {
                $js = $objects->dict($action)['/JS'] ?? null;
                if ($objects->isStream($js)) {
                    $refs[] = $js;
                }
            }
        }

        return array_values(array_unique(array_filter($refs, QpdfObjects::isRef(...))));
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<AcroField>
     */
    private function fields(QpdfObjects $objects, array $entries): array
    {
        $groups = [];
        foreach ($entries as $entry) {
            $name = is_string($entry['fullname'] ?? null) ? $entry['fullname'] : '';
            if ($name !== '') {
                $groups[$name][] = $entry;
            }
        }

        $fields = [];
        foreach ($groups as $name => $widgets) {
            $fields[] = $this->field($objects, (string) $name, $widgets);
        }

        return $fields;
    }

    /** @param  non-empty-list<array<string, mixed>>  $widgets */
    private function field(QpdfObjects $objects, string $name, array $widgets): AcroField
    {
        $first = $widgets[0];
        $chain = $objects->chain(self::widgetRef($first));
        $terminal = self::terminal($chain);
        $type = ltrim((string) ($first['fieldtype'] ?? ''), '/');
        $type = $type !== '' ? $type : (string) $objects->name(QpdfObjects::inherited($chain, '/FT'));
        $flags = is_int($first['fieldflags'] ?? null) ? $first['fieldflags'] : 0;
        $actions = $this->actions($objects, $first);
        $maxLength = $objects->number(QpdfObjects::inherited($chain, '/MaxLen'));

        return new AcroField(
            name: $name,
            type: $type,
            flags: $flags,
            annotationFlags: array_reduce($widgets, static fn (int $carry, array $w): int => $carry & (int) ($w['annotation']['annotationflags'] ?? 0), PHP_INT_MAX),
            page: (int) ($first['pageposfrom1'] ?? 0),
            rect: $this->rect($objects, $widgets),
            tooltip: self::clean($objects->text($terminal['/TU'] ?? null)),
            maxLength: $maxLength !== null && $maxLength >= 1 ? (int) $maxLength : null,
            options: $type === 'Btn' ? $this->exportValues($objects, $widgets, $chain) : $this->choiceOptions($objects, $chain),
            formatScript: $actions['/F'],
            keystrokeScript: $actions['/K'],
            calculateScript: $actions['/C'],
            widgets: count($widgets),
        );
    }

    /**
     * Field-level JavaScript actions (format, keystroke, calculate) as text; other actions are ignored.
     *
     * @param  array<string, mixed>  $entry
     * @return array{'/F': ?string, '/K': ?string, '/C': ?string}
     */
    private function actions(QpdfObjects $objects, array $entry): array
    {
        $scripts = ['/F' => null, '/K' => null, '/C' => null];
        foreach ($this->actionDictionaries($objects, $entry) as $trigger => $action) {
            $dict = $objects->dict($action);
            if ($objects->name($dict['/S'] ?? null) === 'JavaScript') {
                $scripts[$trigger] = $objects->text($dict['/JS'] ?? null) ?? '';
            }
        }

        return $scripts;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<'/F'|'/K'|'/C', mixed> trigger => action (terminal field wins over its widget)
     */
    private function actionDictionaries(QpdfObjects $objects, array $entry): array
    {
        $chain = $objects->chain(self::widgetRef($entry));
        $merged = [...$objects->dict($chain[0]['/AA'] ?? null), ...$objects->dict(self::terminal($chain)['/AA'] ?? null)];

        return array_intersect_key($merged, ['/F' => true, '/K' => true, '/C' => true]);
    }

    /**
     * @param  list<array<string, mixed>>  $widgets
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function rect(QpdfObjects $objects, array $widgets): array
    {
        $box = null;
        foreach ($widgets as $widget) {
            $raw = array_map(fn (mixed $n): float => $objects->number($n) ?? 0.0, $objects->array($objects->dict(self::widgetRef($widget))['/Rect'] ?? null));
            if (count($raw) !== 4) {
                continue;
            }
            $r = [min($raw[0], $raw[2]), min($raw[1], $raw[3]), max($raw[0], $raw[2]), max($raw[1], $raw[3])];
            $box = $box === null ? $r : [min($box[0], $r[0]), min($box[1], $r[1]), max($box[2], $r[2]), max($box[3], $r[3])];
        }
        $box ??= [0.0, 0.0, 0.0, 0.0];

        return [round($box[0], 2), round($box[1], 2), round($box[2], 2), round($box[3], 2)];
    }

    /**
     * Radio and checkbox export values: the "on" appearance states of the widgets. When the field has
     * /Opt, state names are indexes into it (pdf-lib and Acrobat do this for non-ASCII values).
     *
     * @param  list<array<string, mixed>>  $widgets
     * @param  list<array<string, mixed>>  $chain
     * @return list<array{value: string, label: string}>
     */
    private function exportValues(QpdfObjects $objects, array $widgets, array $chain): array
    {
        $opt = $objects->array(QpdfObjects::inherited($chain, '/Opt'));
        $values = [];
        foreach ($widgets as $widget) {
            $appearance = $objects->dict($objects->dict(self::widgetRef($widget))['/AP'] ?? null);
            $normal = $appearance['/N'] ?? null;
            if ($objects->isStream($normal)) {
                continue; // a single appearance (push button), not a state dictionary
            }
            foreach (array_keys($objects->dict($normal)) as $state) {
                $state = self::decodeName((string) $state);
                if ($state === 'Off' || $state === '') {
                    continue;
                }
                $value = ctype_digit($state) && isset($opt[(int) $state]) ? ($objects->text($opt[(int) $state]) ?? $state) : $state;
                $values[$value] = ['value' => $value, 'label' => $value];
            }
        }

        return array_values($values);
    }

    /**
     * @param  list<array<string, mixed>>  $chain
     * @return list<array{value: string, label: string}>
     */
    private function choiceOptions(QpdfObjects $objects, array $chain): array
    {
        $options = [];
        foreach ($objects->array(QpdfObjects::inherited($chain, '/Opt')) as $item) {
            $pair = $objects->array($item);
            $value = $pair === [] ? $objects->text($item) : $objects->text($pair[0] ?? null);
            $label = $pair === [] ? $value : ($objects->text($pair[1] ?? null) ?? $value);
            if (is_string($value) && $value !== '') {
                $options[] = ['value' => $value, 'label' => is_string($label) && trim($label) !== '' ? trim($label) : $value];
            }
        }

        return $options;
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return list<TextRun>
     */
    private function texts(QpdfObjects $objects, array $pages): array
    {
        $runs = [];
        foreach ($pages as $page) {
            $number = (int) ($page['pageposfrom1'] ?? 0);
            $content = '';
            foreach (is_array($page['contents'] ?? null) ? $page['contents'] : [] as $ref) {
                $content .= "\n".(is_string($ref) ? ($objects->streamData($ref) ?? '') : '');
            }
            if (trim($content) !== '') {
                $runs = [...$runs, ...ContentStreamText::extract($content, $number, $this->undecodableFonts($objects, (string) ($page['object'] ?? '')))];
            }
        }

        return $runs;
    }

    /** @return array<string, true> font resource names of composite (Type0) fonts */
    private function undecodableFonts(QpdfObjects $objects, string $pageRef): array
    {
        $resources = $objects->dict(QpdfObjects::inherited($objects->chain($pageRef), '/Resources'));
        $fonts = [];
        foreach ($objects->dict($resources['/Font'] ?? null) as $name => $font) {
            if ($objects->name($objects->dict($font)['/Subtype'] ?? null) === 'Type0') {
                $fonts[self::decodeName((string) $name)] = true;
            }
        }

        return $fonts;
    }

    private function title(QpdfObjects $objects): ?string
    {
        $info = $objects->dict($objects->trailer()['/Info'] ?? null);

        return self::clean($objects->text($info['/Title'] ?? null));
    }

    /**
     * @param  list<array<string, mixed>>  $chain
     * @return array<string, mixed>
     */
    private static function terminal(array $chain): array
    {
        foreach ($chain as $dict) {
            if (array_key_exists('/T', $dict)) {
                return $dict;
            }
        }

        return $chain[0] ?? [];
    }

    /** @param  array<string, mixed>  $entry */
    private static function widgetRef(array $entry): string
    {
        $ref = $entry['annotation']['object'] ?? $entry['object'] ?? '';

        return is_string($ref) ? $ref : '';
    }

    /** Name keys come as "/Name" with #xx escapes. */
    private static function decodeName(string $name): string
    {
        return (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn (array $m): string => chr((int) hexdec($m[1])), ltrim($name, '/'));
    }

    private static function clean(?string $text): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<string, mixed>  $structure
     * @return array<string, mixed>
     */
    private static function objectTable(array $structure): array
    {
        $qpdf = $structure['qpdf'] ?? null;

        return is_array($qpdf) && is_array($qpdf[1] ?? null) ? $qpdf[1] : [];
    }

    /**
     * @param  array<string, mixed>  $structure
     * @return list<array<string, mixed>>
     */
    private static function pages(array $structure): array
    {
        return is_array($structure['pages'] ?? null) ? array_values(array_filter($structure['pages'], is_array(...))) : [];
    }

    /**
     * @param  array<string, mixed>  $structure
     * @return list<array<string, mixed>>
     */
    private static function fieldEntries(array $structure): array
    {
        $fields = $structure['acroform']['fields'] ?? null;

        return is_array($fields) ? array_values(array_filter($fields, is_array(...))) : [];
    }
}
