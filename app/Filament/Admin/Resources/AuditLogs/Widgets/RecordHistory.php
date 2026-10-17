<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AuditLogs\Widgets;

use App\Domain\Audit\AuditLabels;
use App\Domain\Audit\History\HistoryScope;
use App\Domain\Audit\Models\Activity;
use App\Domain\Audit\Query\AuditLogQuery;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Privacy\Erasure\AccountScrubber;
use App\Filament\Admin\Resources\AuditLogs\Schemas\AuditEntryInfolist;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Filament\Actions\ViewAction;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * "History" of a key record (§8.11): its audit entries (HistoryScope), newest first, with the entry detail.
 * Rendered at the end of the form, submission, workflow, members and SSO pages (HistoryHook); visible to
 * audit.view holders only.
 */
class RecordHistory extends TableWidget
{
    #[Locked]
    public string $scope = HistoryScope::RECORD;

    #[Locked]
    public ?string $subjectType = null;

    #[Locked]
    public ?string $subjectId = null;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Gate::allows('viewAny', Activity::class);
    }

    public function table(Table $table): Table
    {
        $scope = new HistoryScope($this->scope, $this->subjectType, $this->subjectId);

        return $table
            ->heading(__('audit.history.heading'))
            ->query(fn (): Builder => $scope->apply(AuditLogQuery::forCurrentOrganization()->with(['causer', 'subject'])))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('audit.fields.time'))->isoDateTime('L LT')->timezone(SubmissionResource::displayTimezone()),
                TextColumn::make('event')->label(__('audit.fields.event'))->badge()->color('gray')->formatStateUsing(fn (?string $state): string => AuditLabels::event($state)),
                TextColumn::make('actor')->label(__('audit.fields.actor'))->state(fn (Activity $record): string => AuditLabels::actor($record))
                    ->description(fn (Activity $record): ?string => AuditLabels::onBehalfOf($record)),
                TextColumn::make('subject_type')->label(__('audit.fields.subject'))->state(fn (Activity $record): string => AuditLabels::subject($record))
                    ->visible($this->scope !== HistoryScope::RECORD),
            ])
            ->filters($this->scope === HistoryScope::MEMBERS ? [$this->memberFilter()] : [])
            ->recordActions([ViewAction::make()->schema(fn (Schema $schema): Schema => AuditEntryInfolist::configure($schema))->slideOver()])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading(__('audit.history.empty'));
    }

    private function memberFilter(): SelectFilter
    {
        return SelectFilter::make('member')->label(__('audit.history.member'))->searchable()
            ->options(fn (): array => Membership::query()->with('user:id,name,email')->where('organization_id', app(TenantContext::class)->require())->get()
                ->filter(fn (Membership $m): bool => $m->user !== null)
                ->mapWithKeys(fn (Membership $m): array => [(string) $m->user_id => AccountScrubber::displayName($m->user->name).' ('.$m->user->email.')'])->all())
            ->query(fn (Builder $query, array $data): Builder => is_numeric($data['value'] ?? null)
                ? new HistoryScope(HistoryScope::MEMBERS)->apply($query, (int) $data['value'])
                : $query);
    }
}
