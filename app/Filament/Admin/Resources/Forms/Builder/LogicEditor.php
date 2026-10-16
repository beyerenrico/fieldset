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
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Livewire\Component as Livewire;

/**
 * Visual condition editor (field/operator/value rows with AND/OR) plus a raw JsonLogic view.
 * State shape is LogicCompiler's UI state; the mapper compiles it to JsonLogic.
 */
final class LogicEditor
{
    public static function make(string $name, string $label): Fieldset
    {
        $noValue = ['empty', 'not_empty', 'checked', 'unchecked'];

        return Fieldset::make($label)
            ->statePath($name)
            ->columns(1)
            ->schema([
                ToggleButtons::make('mode')->label(__('forms.logic.mode'))->inline()->live()->default('none')
                    ->options(['none' => __('forms.logic.mode_none'), 'rules' => __('forms.logic.mode_rules'), 'json' => __('forms.logic.mode_json')]),
                ToggleButtons::make('match')->label(__('forms.logic.match'))->inline()->default('all')
                    ->options(['all' => __('forms.logic.match_all'), 'any' => __('forms.logic.match_any')])
                    ->visible(fn (Get $get): bool => $get('mode') === 'rules'),
                Repeater::make('rules')->label(__('forms.logic.rules'))->defaultItems(1)->columns(3)
                    ->visible(fn (Get $get): bool => $get('mode') === 'rules')
                    ->schema([
                        Select::make('field')->label(__('forms.logic.field'))->required()->searchable()
                            ->options(fn (Livewire $livewire): array => self::fieldOptions($livewire)),
                        Select::make('operator')->label(__('forms.logic.operator'))->required()->default('eq')->live()
                            ->options(collect(LogicCompiler::OPERATORS)->mapWithKeys(
                                fn (string $op): array => [$op => __('forms.logic.operators.'.$op)],
                            )->all()),
                        TextInput::make('value')->label(__('forms.logic.value'))
                            ->hidden(fn (Get $get): bool => in_array($get('operator'), $noValue, true)),
                    ]),
                CodeEditor::make('json')->label(__('forms.logic.json'))->language(Language::Json)
                    ->visible(fn (Get $get): bool => $get('mode') === 'json')
                    ->rule('json'),
            ]);
    }

    /** @return array<string, string> */
    public static function fieldOptions(Livewire $livewire): array
    {
        return method_exists($livewire, 'fieldKeyOptions') ? $livewire->fieldKeyOptions() : [];
    }
}
