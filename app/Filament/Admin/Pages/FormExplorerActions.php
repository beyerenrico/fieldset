<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Forms\Models\Form;
use App\Domain\Reports\Enums\ExportFormat;
use App\Domain\Reports\Enums\ReportVisibility;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Reports\Models\SavedReport;
use App\Domain\Reports\ReportForms;
use App\Domain\Reports\Saved\SaveReport;
use App\Filament\Admin\Resources\SavedReports\SavedReportResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/** Header actions of the explorer: switch form, export all filtered rows, save the filters as a report. */
final class FormExplorerActions
{
    /** @return list<Action|ActionGroup> */
    public static function header(FormExplorer $page): array
    {
        $hasForm = fn (): bool => $page->selectedForm() instanceof Form;

        return [
            Action::make('switchForm')->label(__('reports.explorer.switch_form'))->icon(Heroicon::OutlinedArrowsRightLeft)->color('gray')
                ->schema([Select::make('form')->label(__('reports.explorer.form'))->required()->searchable()
                    ->options(fn (): array => app(ReportForms::class)->options($page->viewer()))
                    ->default(fn (): ?int => $page->selectedForm()?->id)])
                ->action(fn (array $data) => $page->redirect(FormExplorer::getUrl(['form' => (int) $data['form']]))),
            ActionGroup::make([
                self::exportAll($page, ExportFormat::Xlsx, Heroicon::OutlinedTableCells),
                self::exportAll($page, ExportFormat::Csv, Heroicon::OutlinedDocumentText),
                self::exportAll($page, ExportFormat::PdfZip, Heroicon::OutlinedArchiveBoxArrowDown),
            ])->label(__('reports.export.action'))->icon(Heroicon::OutlinedArrowDownTray)->button()->visible($hasForm),
            Action::make('saveReport')->label(__('reports.explorer.save_report'))->icon(Heroicon::OutlinedBookmark)->color('gray')
                ->visible($hasForm)
                ->authorize(fn (): bool => $page->viewer()->can('create', SavedReport::class))
                ->schema([
                    TextInput::make('name')->label(__('reports.saved.name'))->required()->maxLength(120),
                    Select::make('visibility')->label(__('reports.saved.visibility'))->required()
                        ->options(collect(ReportVisibility::cases())->mapWithKeys(fn (ReportVisibility $v): array => [$v->value => $v->label()])->all())
                        ->default(ReportVisibility::Private->value),
                ])
                ->action(function (array $data) use ($page): void {
                    // Calendar days keep the viewer's time zone they were chosen in (review L4).
                    $report = app(SaveReport::class)->handle($page->viewer(), null, [
                        'form_id' => $page->selectedForm()?->id, 'name' => $data['name'], 'visibility' => $data['visibility'],
                        'filters' => $page->reportFilters()->inTimezone(FormExplorer::timezone())->toArray(),
                        'metric' => 'count', 'schedule' => 'none', 'recipients' => [],
                    ]);
                    $page->redirect(SavedReportResource::getUrl('edit', ['record' => $report]));
                }),
        ];
    }

    private static function exportAll(FormExplorer $page, ExportFormat $format, Heroicon $icon): Action
    {
        return Action::make('export_'.$format->value)->label(__('reports.export.all.'.$format->value))->icon($icon)
            ->authorize(fn (): bool => $page->viewer()->can('create', ReportExport::class))
            ->action(fn () => $page->startExport($format));
    }
}
