<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms\Builder;

use App\Domain\Forms\Builder\LogicCompiler;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Livewire\Component as Livewire;

/**
 * Visual condition editor: groups of field/operator/value rules, AND/OR inside each group and between
 * groups, plus a raw JsonLogic view. State shape is LogicCompiler's UI state.
 */
final class LogicEditor
{
    private const array NO_VALUE = ['empty', 'not_empty', 'checked', 'unchecked'];

    public static function make(string $name, string $label): Fieldset
    {
        $matchOptions = ['all' => __('forms.logic.match_all'), 'any' => __('forms.logic.match_any')];

        return Fieldset::make($label)
            ->statePath($name)
            ->columns(1)
            ->schema([
                ToggleButtons::make('mode')->label(__('forms.logic.mode'))->inline()->live()->default('none')
                    ->options(['none' => __('forms.logic.mode_none'), 'rules' => __('forms.logic.mode_rules'), 'json' => __('forms.logic.mode_json')]),
                ToggleButtons::make('match')->label(__('forms.logic.match_groups'))->inline()->default('all')->options($matchOptions)
                    ->visible(fn (Get $get): bool => $get('mode') === 'rules' && count((array) $get('groups')) > 1),
                Repeater::make('groups')->label(__('forms.logic.groups'))->defaultItems(1)
                    ->visible(fn (Get $get): bool => $get('mode') === 'rules')
                    ->schema([
                        ToggleButtons::make('match')->label(__('forms.logic.match'))->inline()->default('all')->options($matchOptions),
                        Repeater::make('rules')->label(__('forms.logic.rules'))->defaultItems(1)->columns(3)->schema(self::ruleFields()),
                    ]),
                CodeEditor::make('json')->label(__('forms.logic.json'))->language(Language::Json)
                    ->visible(fn (Get $get): bool => $get('mode') === 'json')
                    ->rule('json'),
            ]);
    }

    /** @return list<Component> */
    private static function ruleFields(): array
    {
        return [
            Select::make('field')->label(__('forms.logic.field'))->required()->searchable()
                ->options(fn (Livewire $livewire): array => self::fieldOptions($livewire)),
            Select::make('operator')->label(__('forms.logic.operator'))->required()->default('eq')->live()
                ->options(collect(LogicCompiler::OPERATORS)->mapWithKeys(
                    fn (string $op): array => [$op => __('forms.logic.operators.'.$op)],
                )->all()),
            TextInput::make('value')->label(__('forms.logic.value'))
                ->hidden(fn (Get $get): bool => in_array($get('operator'), self::NO_VALUE, true)),
        ];
    }

    /** @return array<string, string> */
    public static function fieldOptions(Livewire $livewire): array
    {
        return method_exists($livewire, 'fieldKeyOptions') ? $livewire->fieldKeyOptions() : [];
    }
}
