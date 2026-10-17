<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Privacy;

use App\Domain\Privacy\Erasure\AccountScrubber;
use App\Domain\Privacy\Holds\PlaceLegalHold;
use App\Domain\Privacy\Holds\ReleaseLegalHold;
use App\Domain\Privacy\Models\LegalHold;
use App\Domain\Submissions\Models\Submission;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Legal holds of the organization (ADR 0039): form holds (placed on the Retention page) and submission holds,
 * active and released, with "Hold a submission" and "Release". Owners and Admins (organization.manage).
 */
class LegalHolds extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static ?int $navigationSort = 92;

    protected static ?string $slug = 'privacy/legal-holds';

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', LegalHold::class);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('privacy.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('privacy.holds.navigation');
    }

    public function getTitle(): string
    {
        return __('privacy.holds.title');
    }

    public function getSubheading(): string
    {
        return __('privacy.holds.subheading');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $tz = SubmissionResource::displayTimezone();

        return $table
            ->query(fn (): Builder => LegalHold::query()->with(['form:id,name', 'submission.form:id,name', 'placedBy:id,name']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('target')->label(__('privacy.holds.columns.target'))->state(fn (LegalHold $record): string => $this->target($record)),
                TextColumn::make('reason')->label(__('privacy.holds.reason'))->limit(80)->wrap(),
                TextColumn::make('placedBy.name')->label(__('privacy.holds.columns.placed_by'))->placeholder('—')
                    ->formatStateUsing(fn (string $state): string => AccountScrubber::displayName($state)),
                TextColumn::make('created_at')->label(__('privacy.holds.columns.placed_at'))->isoDateTime('L LT')->timezone($tz),
                TextColumn::make('released_at')->label(__('privacy.holds.columns.released_at'))->isoDateTime('L LT')->timezone($tz)
                    ->placeholder(__('privacy.holds.active')),
            ])
            ->filters([
                TernaryFilter::make('active')->label(__('privacy.holds.status'))->default(true)
                    ->placeholder(__('privacy.holds.filter_all'))->trueLabel(__('privacy.holds.active'))->falseLabel(__('privacy.holds.released_filter'))
                    ->queries(true: fn (Builder $q) => $q->whereNull('released_at'), false: fn (Builder $q) => $q->whereNotNull('released_at'), blank: fn (Builder $q): Builder => $q),
            ])
            ->emptyStateHeading(__('privacy.holds.empty'))
            ->emptyStateDescription(__('privacy.holds.empty_description', [
                'retention' => __('privacy.retention.navigation'), 'action' => __('privacy.holds.place_submission'),
            ]))
            ->headerActions([$this->holdSubmissionAction()])
            ->recordActions([
                Action::make('release')->label(__('privacy.holds.release'))->icon(Heroicon::OutlinedLockOpen)->color('gray')
                    ->visible(fn (LegalHold $record): bool => Gate::allows('update', $record))
                    ->requiresConfirmation()->modalDescription(__('privacy.holds.release_confirm'))
                    ->action(fn (LegalHold $record): bool => PrivacyPage::attempt(
                        fn () => app(ReleaseLegalHold::class)->handle($record, PrivacyPage::user()),
                        __('privacy.holds.released'),
                    )),
            ]);
    }

    private function holdSubmissionAction(): Action
    {
        return Action::make('holdSubmission')->label(__('privacy.holds.place_submission'))->icon(Heroicon::OutlinedLockClosed)
            ->visible(fn (): bool => Gate::allows('create', LegalHold::class))
            ->schema([
                Select::make('submission')->label(__('privacy.holds.submission'))->required()->searchable()
                    ->helperText(__('privacy.holds.submission_help'))
                    ->getSearchResultsUsing(fn (string $search): array => $this->searchSubmissions($search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => is_string($value) ? $this->submissionLabel(Submission::query()->with('form:id,name')->find($value)) : null),
                Textarea::make('reason')->label(__('privacy.holds.reason'))->required()->rows(3)->maxLength(1000),
            ])
            ->action(fn (array $data, Schema $schema): bool => PrivacyPage::attemptInForm(function () use ($data): void {
                $submission = Submission::query()->findOrFail((string) $data['submission']);
                app(PlaceLegalHold::class)->forSubmission($submission, (string) $data['reason'], PrivacyPage::user());
            }, __('privacy.holds.placed'), $schema, ['reason', 'submission']));
    }

    /** @return array<string, string> submissions by number (or uuid prefix) */
    private function searchSubmissions(string $search): array
    {
        $term = trim($search);
        if ($term === '') {
            return [];
        }

        return Submission::query()->with('form:id,name')->whereNotNull('number')
            ->where(fn (Builder $q) => $q->where('number', 'ilike', '%'.addcslashes($term, '%_\\').'%')->orWhereRaw('uuid::text LIKE ?', [addcslashes(strtolower($term), '%_\\').'%']))
            ->orderByDesc('submitted_at')->limit(20)->get()
            ->mapWithKeys(fn (Submission $s): array => [$s->uuid => (string) $this->submissionLabel($s)])->all();
    }

    private function submissionLabel(?Submission $submission): ?string
    {
        return $submission instanceof Submission ? ($submission->number ?? $submission->uuid).' · '.$submission->form->name : null;
    }

    private function target(LegalHold $hold): string
    {
        if ($hold->form_id !== null) {
            return (string) __('privacy.holds.target_form', ['form' => $hold->form->name ?? '#'.$hold->form_id]);
        }

        return (string) __('privacy.holds.target_submission', ['submission' => $this->submissionLabel($hold->submission) ?? (string) $hold->submission_uuid]);
    }
}
