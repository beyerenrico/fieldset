<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms\Builder;

use App\Domain\Forms\Builder\ElementFields;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Builder v1: one Filament Builder block per element type (BUILD_PROMPT §8.2). Repeater and section
 * blocks nest another Builder; columns nest a Repeater of Builders. Every block carries hidden `id`
 * and `_source` so the mapper round-trips losslessly.
 */
final class ElementBlocks
{
    private const int MAX_DEPTH = 3;

    private const array ICONS = [
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

    public static function builder(string $name = 'elements', int $depth = 1): Builder
    {
        return Builder::make($name)
            ->label(__('forms.builder.elements'))
            ->blocks(self::blocks($depth))
            ->collapsible()
            ->collapsed()
            ->cloneable()
            ->reorderable()
            ->blockNumbers(false)
            ->blockPickerColumns(3)
            ->addActionLabel(__('forms.builder.elements').' +')
            ->default([]);
    }

    /** @return list<Block> */
    public static function blocks(int $depth): array
    {
        $types = $depth >= self::MAX_DEPTH
            ? array_values(array_diff(ElementFields::ALL_TYPES, ['repeater', 'section', 'columns']))
            : ElementFields::ALL_TYPES;

        return array_map(fn (string $type): Block => Block::make($type)
            ->label(fn (?array $state): string => self::blockLabel($type, $state))
            ->icon(self::ICONS[$type] ?? null)
            ->schema(self::schema($type, $depth)), $types);
    }

    /** @param  array<string, mixed>|null  $state */
    private static function blockLabel(string $type, ?array $state): string
    {
        $typeLabel = (string) __('forms.types.'.$type);
        $caption = (string) ($state['label'] ?? $state['text'] ?? '');
        $key = (string) ($state['key'] ?? '');

        return trim($typeLabel.($caption !== '' ? ': '.$caption : '').($key !== '' ? " ({$key})" : ''));
    }

    /** @return list<Component> */
    private static function schema(string $type, int $depth): array
    {
        $fields = array_map(fn ($spec): string => $spec->ui, ElementFields::for($type));
        $has = static fn (string $ui): bool => in_array($ui, $fields, true);

        $main = array_values(array_filter([
            Hidden::make('id'), Hidden::make('_source'), Hidden::make('key_locked'),
            Grid::make(2)->schema(array_values(array_filter([
                $has('label') ? TextInput::make('label')->label(__('forms.element.label'))->required($type !== 'section' && $type !== 'hidden')->live(onBlur: true) : null,
                TextInput::make('key')->label(__('forms.element.key'))->required()->maxLength(64)
                    ->regex('/^[a-z][a-z0-9_]{0,63}$/')
                    ->disabled(fn (Get $get): bool => (bool) $get('key_locked'))->dehydrated()
                    ->helperText(fn (Get $get): ?string => $get('key_locked') ? __('forms.builder.key_locked') : null),
                $has('text') ? Textarea::make('text')->label(__('forms.element.text'))->required()->columnSpanFull() : null,
                $has('help') ? TextInput::make('help')->label(__('forms.element.help'))->columnSpanFull() : null,
                $has('placeholder') ? TextInput::make('placeholder')->label(__('forms.element.placeholder')) : null,
                $has('required') ? Toggle::make('required')->label(__('forms.element.required'))->inline(false) : null,
            ]))),
            ...self::typeSpecific($type, $has),
            $has('elements') || in_array($type, ['repeater', 'section'], true) ? self::builder('elements', $depth + 1) : null,
            $type === 'columns' ? Repeater::make('columns')->label(__('forms.element.columns'))->minItems(2)->maxItems(4)->defaultItems(2)
                ->schema([Hidden::make('_source'), self::builder('elements', $depth + 1)])->grid(2) : null,
        ]));

        return [...$main, ...self::logicSection($has), ...self::advancedSection($has)];
    }

    /**
     * @param  callable(string): bool  $has
     * @return list<Component>
     */
    private static function typeSpecific(string $type, callable $has): array
    {
        $grid = array_values(array_filter([
            $has('options') ? Repeater::make('options')->label(__('forms.element.options'))->columns(2)->columnSpanFull()
                ->reorderable()->defaultItems(2)
                ->schema([Hidden::make('_source'),
                    TextInput::make('value')->label(__('forms.element.option_value'))->required()->maxLength(100),
                    TextInput::make('label')->label(__('forms.element.option_label'))->required()]) : null,
            $has('currency') ? TextInput::make('currency')->label(__('forms.element.currency'))->length(3)->default('EUR')->required($type === 'money') : null,
            $has('scale') ? TextInput::make('scale')->label(__('forms.element.scale'))->integer()->minValue(0)->maxValue($type === 'money' ? 4 : 10) : null,
            $has('multiple') ? Toggle::make('multiple')->label(__('forms.element.multiple'))->inline(false) : null,
            $has('rows') ? TextInput::make('rows')->label(__('forms.element.rows'))->integer()->minValue(2)->maxValue(20) : null,
            $has('max') ? TextInput::make('max')->label(__('forms.element.max'))->integer()->minValue(3)->maxValue(10) : null,
            $has('signer_name_from') ? Select::make('signer_name_from')->label(__('forms.element.signer_name_from'))
                ->options(fn ($livewire): array => LogicEditor::fieldOptions($livewire)) : null,
            $has('format') ? Select::make('format')->label(__('forms.element.format'))->default('number')
                ->options(['number' => __('forms.element.format_number'), 'money' => __('forms.element.format_money'), 'text' => __('forms.element.format_text')]) : null,
            $has('display') ? Select::make('display')->label(__('forms.element.display'))->default('cards')
                ->options(['cards' => __('forms.element.display_cards'), 'table' => __('forms.element.display_table')]) : null,
            $has('min_items') ? TextInput::make('min_items')->label(__('forms.element.min_items'))->integer()->minValue(0) : null,
            $has('max_items') ? TextInput::make('max_items')->label(__('forms.element.max_items'))->integer()->minValue(1) : null,
            $has('add_label') ? TextInput::make('add_label')->label(__('forms.element.add_label')) : null,
            $has('collapsible') ? Toggle::make('collapsible')->label(__('forms.element.collapsible'))->inline(false) : null,
            $has('level') ? Select::make('level')->label(__('forms.element.level'))->options([1 => 'H1', 2 => 'H2', 3 => 'H3']) : null,
            $has('src') ? Textarea::make('src')->label(__('forms.element.src'))->required()->rows(2)->columnSpanFull() : null,
            $has('alt') ? TextInput::make('alt')->label(__('forms.element.alt')) : null,
        ]));

        $parts = $grid === [] ? [] : [Grid::make(2)->schema($grid)];
        if ($has('expression')) {
            $parts[] = CalculationEditor::make();
        }

        return $parts;
    }

    /**
     * @param  callable(string): bool  $has
     * @return list<Component>
     */
    private static function logicSection(callable $has): array
    {
        $editors = array_values(array_filter([
            $has('visible_if') ? LogicEditor::make('visible_if', __('forms.element.visible_if')) : null,
            $has('required_if') ? LogicEditor::make('required_if', __('forms.element.required_if')) : null,
            $has('read_only_if') ? LogicEditor::make('read_only_if', __('forms.element.read_only_if')) : null,
        ]));

        return $editors === [] ? [] : [Section::make(__('forms.element.logic'))->schema($editors)->collapsed()->compact()];
    }

    /**
     * @param  callable(string): bool  $has
     * @return list<Component>
     */
    private static function advancedSection(callable $has): array
    {
        $fields = array_values(array_filter([
            $has('default') ? TextInput::make('default')->label(__('forms.element.default'))->helperText('Text, or JsonLogic such as {"var": "$today"}') : null,
            $has('role') ? Select::make('role')->label(__('forms.element.role'))->options(RoleOptions::all())->searchable() : null,
            $has('v_min_length') ? TextInput::make('v_min_length')->label(__('forms.element.min_length'))->integer()->minValue(0) : null,
            $has('v_max_length') ? TextInput::make('v_max_length')->label(__('forms.element.max_length'))->integer()->minValue(1) : null,
            $has('v_pattern') ? TextInput::make('v_pattern')->label(__('forms.element.pattern')) : null,
            $has('v_min') ? TextInput::make('v_min')->label(__('forms.element.min')) : null,
            $has('v_max') ? TextInput::make('v_max')->label(__('forms.element.max')) : null,
            $has('v_step') ? TextInput::make('v_step')->label(__('forms.element.step'))->regex('/^(0|[1-9]\d*)(\.\d+)?$/') : null,
            $has('v_max_files') ? TextInput::make('v_max_files')->label(__('forms.element.max_files'))->integer()->minValue(1) : null,
            $has('v_max_size_kb') ? TextInput::make('v_max_size_kb')->label(__('forms.element.max_size_kb'))->integer()->minValue(1) : null,
            $has('v_accept') ? TagsInput::make('v_accept')->label(__('forms.element.accept'))->placeholder('image/*') : null,
            $has('pdf_hidden') ? Toggle::make('pdf_hidden')->label(__('forms.element.pdf_hidden'))->inline(false) : null,
            $has('pdf_keep_together') ? Toggle::make('pdf_keep_together')->label(__('forms.element.pdf_keep_together'))->inline(false) : null,
        ]));

        return $fields === [] ? [] : [Section::make(__('forms.element.advanced'))->schema([Grid::make(2)->schema($fields)])->collapsed()->compact()];
    }
}
