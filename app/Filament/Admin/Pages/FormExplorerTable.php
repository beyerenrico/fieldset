<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Forms\Models\Form;
use App\Domain\Reports\Enums\ExportFormat;
use App\Domain\Reports\Filters\ExplorerQuery;
use App\Domain\Reports\Filters\ReportScope;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Reports\Schema\ReportField;
use App\Domain\Reports\Schema\ReportValue;
use App\Domain\Submissions\Admin\ValueFormatter;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Explorer table: fixed columns (number — first, always visible — status, submitter, submitted at,
 * version) plus one column per top-level key of all versions, formatted per type and locale (decimal
 * strings, money in the currency of the submission's version, dates, booleans, option labels of all
 * versions; repeaters as row counts). Field headers wrap; field columns after the first
 * `reports.explorer.visible_field_columns` start hidden (toggleable). Values come pivoted from
 * `submission_values` (ExplorerQuery).
 */
final class FormExplorerTable
{
    public static function configure(Table $table, FormExplorer $page): Table
    {
        $form = $page->selectedForm();
        $catalog = $page->catalog();
        $tz = FormExplorer::timezone();
        $formatter = new ValueFormatter(app()->getLocale(), $tz);

        return $table
            ->query(fn (): Builder => $form instanceof Form
                ? ExplorerQuery::withValues(app(ReportScope::class)->base($page->viewer(), $form), $catalog)->with(['creator:id,name', 'formVersion:id,version'])
                : Submission::query()->whereRaw('false'))
            ->defaultSort(fn (Builder $query): Builder => ExplorerQuery::defaultOrder($query))
            // The search term is one phrase, as in exports (ReportFilters::$search), not split into words that
            // must each match: two common words are two full scans of the values (M8-T05).
            ->splitSearchTerms(false)
            ->paginationMode(fn (FormExplorer $livewire): PaginationMode => self::paginationMode($livewire))
            ->selectCurrentPageOnly(fn (FormExplorer $livewire): bool => self::paginationMode($livewire) === PaginationMode::Simple)
            ->columns([
                ...self::baseColumns($form, $tz),
                ...array_map(
                    fn (ReportField $field, int $i): Column => self::fieldColumn($field, $formatter)->toggleable(isToggledHiddenByDefault: $i >= self::visibleFieldColumns()),
                    $catalog->topLevel(), array_keys($catalog->topLevel()),
                ),
            ])
            ->filters($form instanceof Form ? FormExplorerFilters::make($form, $catalog, $tz, $page->viewer()) : [])
            ->filtersFormColumns(3)
            ->recordUrl(fn (Submission $record): string => SubmissionResource::getUrl('view', ['record' => $record]))
            ->toolbarActions([BulkActionGroup::make([
                self::bulkExport('exportSelectedXlsx', ExportFormat::Xlsx, Heroicon::OutlinedTableCells),
                self::bulkExport('exportSelectedCsv', ExportFormat::Csv, Heroicon::OutlinedDocumentText),
                self::bulkExport('downloadPdfs', ExportFormat::PdfZip, Heroicon::OutlinedArchiveBoxArrowDown),
            ])])
            ->emptyStateHeading($form instanceof Form ? __('reports.explorer.no_rows') : __('reports.explorer.empty'));
    }

    /** @return list<Column> */
    private static function baseColumns(?Form $form, string $tz): array
    {
        return [
            TextColumn::make('number')->label(__('reports.column.number'))->placeholder('—')->fontFamily('mono')->sortable()
                ->searchable(query: function (Builder $query, string $search) use ($form): Builder {
                    if ($form instanceof Form) {
                        app(ReportScope::class)->search($query, $form, $search);
                    }

                    return $query;
                }),
            TextColumn::make('status')->label(__('reports.column.status'))->badge()
                ->formatStateUsing(fn (SubmissionStatus $state): string => $state->label())
                ->color(fn (SubmissionStatus $state): string => SubmissionResource::statusColor($state)),
            TextColumn::make('creator.name')->label(__('reports.column.submitter'))->placeholder('—'),
            TextColumn::make('submitted_at')->label(__('reports.column.submitted_at'))->isoDateTime('L LT')->timezone($tz)->sortable()->placeholder('—'),
            TextColumn::make('formVersion.version')->label(__('reports.column.version'))->prefix('v')->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    private static function fieldColumn(ReportField $field, ValueFormatter $formatter): TextColumn
    {
        $column = TextColumn::make('field_'.$field->key)->label($field->label)->wrapHeader()
            ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(ExplorerQuery::sortExpression($field), $direction === 'desc' ? 'desc' : 'asc'));

        if ($field->isRepeater()) {
            return $column->state(fn (Submission $record): int => ExplorerQuery::rowCount($record, $field->key))
                ->formatStateUsing(fn (int $state): string => trans_choice('reports.explorer.rows', $state, ['count' => $state]))->alignEnd();
        }

        return $column->state(fn (Submission $record): string => ReportValue::display($field, ExplorerQuery::values($record)[$field->key] ?? null, $formatter, $record->form_version_id))
            ->alignment($field->isNumeric() ? 'end' : 'start')->limit(80)->wrap();
    }

    /**
     * A search ("any value contains …") pages without a total (M8-T05): counting every match of a common term
     * reads all its values again (a term in a third of 100k submissions: 0.3–1 s just for the count). For the
     * same reason "select all" then selects the rows on the page (Filament would count all matches for it);
     * "Export filtered rows" still exports every match. Unsearched pages keep the total and select-all.
     */
    public static function paginationMode(FormExplorer $page): PaginationMode
    {
        return filled($page->getTableSearch()) ? PaginationMode::Simple : PaginationMode::Default;
    }

    /** Field columns shown by default; the others start hidden and can be toggled on (wide forms stay readable). */
    private static function visibleFieldColumns(): int
    {
        return max(1, (int) config('reports.explorer.visible_field_columns', 6));
    }

    private static function bulkExport(string $name, ExportFormat $format, Heroicon $icon): BulkAction
    {
        return BulkAction::make($name)->label(__('reports.export.selected.'.$format->value))->icon($icon)
            ->authorize(fn (): bool => auth()->user()?->can('create', ReportExport::class) ?? false)
            ->fetchSelectedRecords(false)
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, FormExplorer $livewire) use ($format): void {
                $livewire->startExport($format, array_values($records->map(fn (mixed $key): string => (string) $key)->all()));
            });
    }
}
