<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms\Builder;

use App\Domain\Forms\Builder\CalculationCompiler;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Livewire\Component as Livewire;

/** Guided expression editor for calculated elements (sum of a column, product, …, raw JsonLogic). */
final class CalculationEditor
{
    public static function make(): Fieldset
    {
        $is = static fn (string ...$modes) => static fn (Get $get): bool => in_array($get('mode'), $modes, true);

        return Fieldset::make(__('forms.element.expression'))
            ->statePath('expression')
            ->columns(2)
            ->schema([
                Select::make('mode')->label(__('forms.logic.calc_mode'))->required()->live()->default('product')->columnSpanFull()
                    ->options(collect(CalculationCompiler::MODES)->mapWithKeys(
                        fn (string $mode): array => [$mode => __('forms.logic.calc_'.$mode)],
                    )->all()),
                Select::make('repeater')->label(__('forms.logic.calc_repeater'))->live()->visible($is('sum'))->required($is('sum'))
                    ->options(fn (Livewire $livewire): array => method_exists($livewire, 'repeaterKeyOptions') ? $livewire->repeaterKeyOptions() : []),
                Select::make('column')->label(__('forms.logic.calc_column'))->visible($is('sum'))->required($is('sum'))
                    ->options(fn (Get $get, Livewire $livewire): array => method_exists($livewire, 'rowKeyOptions')
                        ? $livewire->rowKeyOptions((string) $get('repeater')) : []),
                Select::make('fields')->label(__('forms.logic.calc_fields'))->multiple()->columnSpanFull()
                    ->visible($is('product', 'add', 'subtract'))->required($is('product', 'add', 'subtract'))
                    ->options(fn (Livewire $livewire): array => LogicEditor::fieldOptions($livewire)),
                Select::make('field')->label(__('forms.logic.calc_field'))->visible($is('percent'))->required($is('percent'))
                    ->options(fn (Livewire $livewire): array => LogicEditor::fieldOptions($livewire)),
                TextInput::make('rate')->label(__('forms.logic.calc_rate'))->visible($is('percent'))->required($is('percent'))
                    ->regex('/^-?(0|[1-9]\d*)(\.\d+)?$/'),
                CodeEditor::make('json')->label(__('forms.logic.json'))->language(Language::Json)->columnSpanFull()
                    ->visible($is('json'))->rule('json'),
            ]);
    }
}
