<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Designs\Pages;

use App\Domain\Documents\Designs\DesignSettings;
use App\Domain\Documents\Rendering\TokenReplacer;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** The design editor form: every DesignSettings option (BUILD_PROMPT §8.5). */
final class DesignForm
{
    private const array RICH_TOOLBAR = [['bold', 'italic', 'underline'], ['alignStart', 'alignCenter', 'alignEnd'], ['mergeTags'], ['undo', 'redo']];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('documents.design_form.general'))->schema([
                TextInput::make('name')->label(__('documents.designs.name'))->required()->maxLength(120),
                TextInput::make('key')->label(__('documents.designs.key'))->disabled()->dehydrated(false)
                    ->helperText(__('documents.design_form.key_help')),
            ])->columns(2),
            Section::make(__('documents.design_form.page'))->schema([
                self::select('paper', DesignSettings::PAPERS),
                self::select('orientation', DesignSettings::ORIENTATIONS),
                ...array_map(fn (string $side): TextInput => TextInput::make("settings.margins.{$side}")
                    ->label(__("documents.design_form.margin_{$side}"))->numeric()->integer()->suffix('mm')->required()
                    ->minValue(DesignSettings::MARGIN_MIN)->maxValue(DesignSettings::MARGIN_MAX), DesignSettings::MARGIN_SIDES),
            ])->columns(3),
            Section::make(__('documents.design_form.header_footer'))->description(__('documents.design_form.tokens_help'))->schema([
                self::richText('header'),
                self::richText('footer'),
                Toggle::make('settings.show_logo')->label(__('documents.design_form.show_logo')),
            ]),
            Section::make(__('documents.design_form.content'))->schema([
                self::select('layout', DesignSettings::LAYOUTS),
                self::select('repeaters', DesignSettings::REPEATER_STYLES),
                Select::make('settings.photo_columns')->label(__('documents.design_form.photo_columns'))->required()
                    ->options([1 => '1', 2 => '2', 3 => '3', 4 => '4']),
                self::select('signature_placement', DesignSettings::SIGNATURE_PLACEMENTS),
                self::select('watermark', DesignSettings::WATERMARKS),
                Toggle::make('settings.show_empty')->label(__('documents.design_form.show_empty'))->inline(false),
            ])->columns(3),
            Section::make(__('documents.design_form.locale'))->schema([
                Select::make('settings.locale')->label(__('documents.design_form.locale_override'))
                    ->placeholder(__('documents.design_form.locale_from_form'))
                    ->options(['de-DE' => 'Deutsch (Deutschland)', 'de-AT' => 'Deutsch (Österreich)', 'de-CH' => 'Deutsch (Schweiz)', 'en-GB' => 'English (UK)', 'en-US' => 'English (US)']),
                self::select('date_style', DesignSettings::DATE_STYLES),
            ])->columns(2),
        ]);
    }

    /** @param  list<string>  $values */
    private static function select(string $setting, array $values): Select
    {
        return Select::make("settings.{$setting}")->label(__("documents.design_form.{$setting}"))->required()->native(false)
            ->options(array_combine($values, array_map(static fn (string $v): string => (string) __("documents.design_form.options.{$setting}.{$v}"), $values)));
    }

    private static function richText(string $setting): RichEditor
    {
        return RichEditor::make("settings.{$setting}")->label(__("documents.design_form.{$setting}"))
            ->toolbarButtons(self::RICH_TOOLBAR)
            ->mergeTags(array_combine(TokenReplacer::TOKENS, array_map(static fn (string $t): string => (string) __('documents.tokens.'.str_replace('.', '_', $t)), TokenReplacer::TOKENS)))
            ->maxLength(DesignSettings::HTML_MAX);
    }
}
