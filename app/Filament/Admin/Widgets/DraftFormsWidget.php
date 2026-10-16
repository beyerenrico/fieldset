<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Forms\FormResource;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use App\Filament\Admin\Widgets\Concerns\InteractsWithDashboard;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Draft forms (§8.1 Designer): forms that were never published, last edited first. */
class DraftFormsWidget extends TableWidget
{
    use InteractsWithDashboard;

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('dashboard.draft_forms.heading'))
            ->description(fn (): ?string => $this->freshness('draft_forms'))
            ->records(fn (): array => self::keyed($this->dashboardData()->draftForms($this->dashboardContext()), 'id'))
            ->paginated(false)
            ->emptyStateHeading(__('dashboard.draft_forms.empty'))
            ->recordUrl(fn (array $record): string => FormResource::getUrl('edit', ['record' => $record['id']]))
            ->columns([
                TextColumn::make('name')->label(__('dashboard.columns.form'))
                    ->description(fn (array $record): ?string => $record['category']),
                TextColumn::make('updated_at')->label(__('dashboard.columns.updated_at'))->isoDateTime('L LT')
                    ->timezone(SubmissionResource::displayTimezone()),
            ]);
    }
}
