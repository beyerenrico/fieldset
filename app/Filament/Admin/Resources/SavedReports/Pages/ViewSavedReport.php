<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SavedReports\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Reports\Models\SavedReport;
use App\Domain\Reports\Saved\ReportCsv;
use App\Domain\Reports\Saved\ReportResult;
use App\Domain\Reports\SavedReportQuery;
use App\Filament\Admin\Pages\FormExplorer;
use App\Filament\Admin\Resources\SavedReports\Actions\PinActions;
use App\Filament\Admin\Resources\SavedReports\SavedReportResource;
use App\Filament\Admin\Resources\SavedReports\Widgets\SavedReportChart;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A saved report as a chart (SavedReportChart) and a table, computed for the viewer (SavedReportQuery).
 *
 * @property SavedReport $record
 */
class ViewSavedReport extends ViewRecord
{
    protected static string $resource = SavedReportResource::class;

    private ?ReportResult $result = null;

    public function result(): ReportResult
    {
        return $this->result ??= app(SavedReportQuery::class)->run($this->record, null, $this->viewer());
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            View::make('reports.result-table')->columnSpanFull()->viewData(fn (): array => ['result' => $this->result()]),
        ]);
    }

    protected function getHeaderWidgets(): array
    {
        return [SavedReportChart::class];
    }

    /** The chart gets the result of this page view instead of computing the report a second time. */
    public function getWidgetData(): array
    {
        return [...parent::getWidgetData(), 'result' => $this->result()->toArray()];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadCsv')->label(__('reports.saved.download_csv'))->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->action(fn (): StreamedResponse => response()->streamDownload(
                    function (): void {
                        echo ReportCsv::make($this->result());
                    },
                    Str::slug($this->record->name ?: 'report').'.csv',
                    ['Content-Type' => 'text/csv; charset=UTF-8'],
                )),
            Action::make('explore')->label(__('reports.saved.explore'))->icon(Heroicon::OutlinedTableCells)->color('gray')
                ->url(fn (): string => FormExplorer::getUrl(['form' => $this->record->form_id])),
            PinActions::pin(),
            PinActions::unpin(),
            EditAction::make(),
        ];
    }

    private function viewer(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
