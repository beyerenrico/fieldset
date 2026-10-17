<?php

declare(strict_types=1);

namespace App\Domain\Forms\Builder;

/**
 * The Builder v2 palette (ADR 0037): element types in groups, each with a translated label, an icon
 * (shared with Builder v1 blocks) and a default node without id that already passes the meta-schema
 * (the client assigns a UUIDv7 on insert and makes the key unique).
 */
final class PaletteCatalog
{
    public const array GROUPS = [
        'input' => ElementFields::INPUT_TYPES,
        'capture' => ElementFields::CAPTURE_TYPES,
        'computed' => ['calculated', 'hidden'],
        'structure' => ['section', 'repeater', 'columns'],
        'layout' => ['heading', 'paragraph', 'divider', 'image', 'page_break'],
    ];

    public const array ICONS = [
        'text' => 'heroicon-o-pencil', 'textarea' => 'heroicon-o-bars-3-bottom-left', 'email' => 'heroicon-o-at-symbol',
        'phone' => 'heroicon-o-phone', 'number' => 'heroicon-o-hashtag', 'money' => 'heroicon-o-currency-euro',
        'date' => 'heroicon-o-calendar', 'time' => 'heroicon-o-clock', 'datetime' => 'heroicon-o-calendar-days',
        'select' => 'heroicon-o-chevron-up-down', 'multiselect' => 'heroicon-o-list-bullet', 'radio' => 'heroicon-o-stop-circle',
        'checkbox' => 'heroicon-o-check-circle', 'toggle' => 'heroicon-o-power', 'rating' => 'heroicon-o-star',
        'photo' => 'heroicon-o-camera', 'file' => 'heroicon-o-paper-clip', 'signature' => 'heroicon-o-pencil-square',
        'location' => 'heroicon-o-map-pin', 'calculated' => 'heroicon-o-calculator', 'hidden' => 'heroicon-o-eye-slash',
        'repeater' => 'heroicon-o-table-cells', 'section' => 'heroicon-o-rectangle-group', 'columns' => 'heroicon-o-view-columns',
        'heading' => 'heroicon-o-h1', 'paragraph' => 'heroicon-o-document-text', 'divider' => 'heroicon-o-minus',
        'image' => 'heroicon-o-photo', 'page_break' => 'heroicon-o-scissors',
    ];

    /** Key bases that differ from the type name. */
    private const array KEY_BASES = ['repeater' => 'rows'];

    /** A 1×1 transparent PNG: `image` requires a src, the designer replaces it in the inspector. */
    private const string PLACEHOLDER_IMAGE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    /** Types without a label property in the builder. */
    private const array UNLABELLED = ['columns', 'heading', 'paragraph', 'divider', 'image', 'page_break'];

    public static function icon(string $type): ?string
    {
        return self::ICONS[$type] ?? null;
    }

    /** @return list<array{group: string, label: string, items: list<array{type: string, label: string, icon: string, node: array<string, mixed>}>}> */
    public static function groups(): array
    {
        $groups = [];
        foreach (self::GROUPS as $group => $types) {
            $groups[] = [
                'group' => $group,
                'label' => (string) __('forms.canvas.palette.'.$group),
                'items' => array_map(self::item(...), $types),
            ];
        }

        return $groups;
    }

    /** @return array{type: string, label: string, icon: string, node: array<string, mixed>} */
    private static function item(string $type): array
    {
        $label = (string) __('forms.types.'.$type);

        return ['type' => $type, 'label' => $label, 'icon' => self::ICONS[$type] ?? 'heroicon-o-square-2-stack', 'node' => self::node($type, $label)];
    }

    /** @return array<string, mixed> */
    private static function node(string $type, string $label): array
    {
        $node = ['type' => $type, 'key' => self::KEY_BASES[$type] ?? $type];
        if (! in_array($type, self::UNLABELLED, true)) {
            $node['label'] = $label;
        }

        return $node + match ($type) {
            'money' => ['currency' => 'EUR'],
            'select', 'radio', 'multiselect' => ['options' => [
                ['value' => 'option_1', 'label' => (string) __('forms.canvas.option', ['number' => 1])],
                ['value' => 'option_2', 'label' => (string) __('forms.canvas.option', ['number' => 2])],
            ]],
            'calculated' => ['expression' => '0'],
            'section', 'repeater' => ['elements' => []],
            'columns' => ['columns' => [['elements' => []], ['elements' => []]]],
            'heading', 'paragraph' => ['text' => $label],
            'image' => ['src' => self::PLACEHOLDER_IMAGE],
            default => [],
        };
    }
}
