<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms\Pages;

use App\Domain\Forms\Import\ImportReportPresenter;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Filament\Admin\Resources\Forms\Builder\ElementBlocks;
use App\Filament\Admin\Resources\Forms\Builder\LogicEditor;
use App\Filament\Admin\Support\OrganizationOptions;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use stdClass;

/** Layout of the form editor: lint panel, PDF import report, builder, settings, access, raw JSON. */
final class FormEditorSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            View::make('filament.forms.lint-panel'),
            self::importReport(),
            Hidden::make('_source'),
            Tabs::make('editor')->persistTabInQueryString()->tabs([
                Tab::make(__('forms.builder.title'))->icon('heroicon-o-squares-plus')->schema([self::pages()]),
                Tab::make(__('forms.builder.settings'))->icon('heroicon-o-cog-6-tooth')->schema(self::settings()),
                Tab::make(__('forms.preview.title'))->icon('heroicon-o-device-phone-mobile')->schema([View::make('filament.forms.preview')]),
                Tab::make(__('forms.form.access'))->icon('heroicon-o-key')->schema(self::access()),
                Tab::make(__('forms.builder.raw_json'))->icon('heroicon-o-code-bracket')->schema([View::make('filament.forms.raw-json')]),
            ]),
        ]);
    }

    /** The PDF import report stays visible until someone dismisses it (BUILD_PROMPT §8.13). */
    private static function importReport(): Callout
    {
        return Callout::make(__('forms.import.report.title'))
            ->warning()
            ->visible(fn (?Form $record): bool => $record?->import_report instanceof stdClass)
            ->description(fn (?Form $record): string => ImportReportPresenter::summary($record?->import_report))
            ->footer(fn (?Form $record): array => [UnorderedList::make(array_map(
                static fn (string $line): Text => Text::make($line),
                ImportReportPresenter::lines($record?->import_report),
            ))->columns(1)]);
    }

    private static function pages(): Repeater
    {
        return Repeater::make('pages')->label(__('forms.builder.pages'))
            ->itemLabel(fn (array $state): string => (string) ($state['title'] ?? __('forms.builder.page')))
            ->collapsible()->reorderable()->minItems(1)->defaultItems(1)
            ->addActionLabel(__('forms.builder.page').' +')
            ->schema([
                Hidden::make('_source'), Hidden::make('id'),
                Grid::make(2)->schema([
                    TextInput::make('title')->label(__('forms.builder.page_title'))->required()->live(onBlur: true),
                    TextInput::make('key')->label(__('forms.builder.page_key'))->required()->regex('/^[a-z][a-z0-9_]{0,63}$/'),
                ]),
                Section::make(__('forms.element.logic'))->schema([LogicEditor::make('visible_if', __('forms.element.visible_if'))])->collapsed()->compact(),
                ElementBlocks::builder(),
            ]);
    }

    /** @return list<Component> */
    private static function settings(): array
    {
        return [Grid::make(2)->schema([
            TextInput::make('title')->label(__('forms.form.name'))->required()->maxLength(200),
            Select::make('locale')->label(__('forms.builder.locale'))->required()
                ->options(['de-DE' => 'Deutsch (Deutschland)', 'de-AT' => 'Deutsch (Österreich)', 'de-CH' => 'Deutsch (Schweiz)', 'en-GB' => 'English (UK)', 'en-US' => 'English (US)']),
            Select::make('layout')->label(__('forms.builder.layout'))
                ->options(['scroll' => __('forms.builder.layout_scroll'), 'wizard' => __('forms.builder.layout_wizard')]),
            TextInput::make('design')->label(__('forms.builder.design'))->placeholder('default'),
            TextInput::make('numbering_format')->label(__('forms.builder.numbering_format'))->placeholder('SR-{YYYY}-{SEQ:5}'),
            Select::make('numbering_reset')->label(__('forms.builder.numbering_reset'))
                ->options(['never' => __('forms.builder.reset_never'), 'yearly' => __('forms.builder.reset_yearly'), 'monthly' => __('forms.builder.reset_monthly')]),
            Toggle::make('send_to_customer')->label(__('forms.builder.send_to_customer'))->columnSpanFull(),
        ])];
    }

    /** @return list<Component> */
    private static function access(): array
    {
        return [Section::make(__('forms.form.access'))->description(__('forms.form.access_help'))->schema([
            CheckboxList::make('access_groups')->label(__('forms.form.access_groups'))->columns(2)
                ->options(fn (): array => Group::query()->orderBy('name')->pluck('name', 'id')->all()),
            CheckboxList::make('access_roles')->label(__('forms.form.access_roles'))->columns(2)
                ->options(fn (): array => self::roleOptions()),
        ])];
    }

    /** @return array<string, string> */
    private static function roleOptions(): array
    {
        $roles = Role::query()
            ->where('organization_id', app(TenantContext::class)->require())
            ->orderBy('id')->pluck('name')->all();

        return collect($roles)->mapWithKeys(fn (string $name): array => [$name => OrganizationOptions::roleLabel($name)])->all();
    }
}
