<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AuditLogs;

use App\Domain\Audit\AuditLabels;
use App\Domain\Audit\Models\Activity;
use App\Domain\Audit\Query\AuditLogFilters;
use App\Domain\Audit\Query\AuditLogQuery;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Privacy\Erasure\AccountScrubber;
use App\Filament\Admin\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Admin\Resources\AuditLogs\Schemas\AuditEntryInfolist;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only audit log of the current organization (BUILD_PROMPT §8.11, ADR 0038): filters by actor, event,
 * subject type, date range and impersonation, a detail view, and CSV export (ListAuditLogs). Visible to audit.view holders
 * (ActivityPolicy). Entries are never created, edited or deleted here.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    /** The activity log has no tenant relationship: the query is scoped to the organization explicitly. */
    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'audit-log';

    public static function getNavigationGroup(): ?string
    {
        return __('audit.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('audit.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('audit.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('audit.plural');
    }

    /** @return Builder<Activity> */
    public static function getEloquentQuery(): Builder
    {
        // Subjects are eager-loaded per type for their names (AuditLabels::subject), never per row.
        return AuditLogQuery::forCurrentOrganization()->with(['causer', 'subject']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AuditEntryInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns(self::columns())
            ->filters(self::filters())
            ->recordActions([ViewAction::make()->slideOver()])
            ->emptyStateHeading(__('audit.empty'));
    }

    /** @return list<TextColumn> */
    public static function columns(): array
    {
        return [
            TextColumn::make('created_at')->label(__('audit.fields.time'))->isoDateTime('L LT')->timezone(SubmissionResource::displayTimezone()),
            TextColumn::make('event')->label(__('audit.fields.event'))->badge()->color('gray')
                ->formatStateUsing(fn (?string $state): string => AuditLabels::event($state)),
            TextColumn::make('actor')->label(__('audit.fields.actor'))->state(fn (Activity $record): string => AuditLabels::actor($record))
                ->description(fn (Activity $record): ?string => AuditLabels::onBehalfOf($record)),
            TextColumn::make('subject_type')->label(__('audit.fields.subject'))->state(fn (Activity $record): string => AuditLabels::subject($record)),
            TextColumn::make('ip_address')->label(__('audit.fields.ip_address'))->placeholder('—')->toggleable(),
            TextColumn::make('request_id')->label(__('audit.fields.request_id'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    /** @return list<Filter|SelectFilter> */
    public static function filters(): array
    {
        $organizationId = app(TenantContext::class)->require();

        return [
            SelectFilter::make('actor')->label(__('audit.fields.actor'))->multiple()->searchable()->options(fn (): array => self::actorOptions($organizationId))
                ->query(fn (Builder $query, array $data): Builder => new AuditLogFilters(actors: array_values($data['values'] ?? []))->apply($query)),
            SelectFilter::make('event')->label(__('audit.fields.event'))->multiple()->searchable()
                ->options(fn (): array => self::labelled(AuditLogQuery::events($organizationId), AuditLabels::event(...)))
                ->query(fn (Builder $query, array $data): Builder => new AuditLogFilters(events: array_values($data['values'] ?? []))->apply($query)),
            SelectFilter::make('subject_type')->label(__('audit.fields.subject_type'))->multiple()
                ->options(fn (): array => self::labelled(AuditLogQuery::subjectTypes($organizationId), AuditLabels::subjectType(...)))
                ->query(fn (Builder $query, array $data): Builder => new AuditLogFilters(subjectTypes: array_values($data['values'] ?? []))->apply($query)),
            Filter::make('created_at')
                ->schema([
                    DatePicker::make('from')->label(__('audit.filters.from')),
                    DatePicker::make('until')->label(__('audit.filters.until')),
                ])
                ->query(fn (Builder $query, array $data): Builder => AuditLogFilters::fromTableFilters(['created_at' => $data], SubmissionResource::displayTimezone())->apply($query)),
            Filter::make('impersonated')->label(__('audit.filters.impersonated'))->toggle()
                ->query(fn (Builder $query): Builder => new AuditLogFilters(impersonated: true)->apply($query)),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditLogs::route('/')];
    }

    /** @return array<string, string> */
    private static function actorOptions(int $organizationId): array
    {
        $options = [AuditLogFilters::SYSTEM => (string) __('audit.actor_types.system')];
        foreach (Membership::query()->with('user:id,name,email')->where('organization_id', $organizationId)->get() as $membership) {
            if ($membership->user !== null) {
                $options['user:'.$membership->user->id] = AccountScrubber::displayName($membership->user->name).' ('.$membership->user->email.')';
            }
        }

        return $options;
    }

    /**
     * @param  list<string>  $values
     * @param  callable(string): string  $label
     * @return array<string, string>
     */
    private static function labelled(array $values, callable $label): array
    {
        $options = [];
        foreach ($values as $value) {
            $options[$value] = $label($value);
        }
        asort($options);

        return $options;
    }
}
