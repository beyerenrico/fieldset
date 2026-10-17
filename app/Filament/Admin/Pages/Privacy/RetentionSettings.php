<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Privacy;

use App\Domain\Forms\Models\Form;
use App\Domain\Privacy\Enums\RetentionAction;
use App\Domain\Privacy\Holds\LegalHolds;
use App\Domain\Privacy\Holds\PlaceLegalHold;
use App\Domain\Privacy\Holds\ReleaseLegalHold;
use App\Domain\Privacy\Models\LegalHold;
use App\Domain\Privacy\Models\RetentionPolicy;
use App\Domain\Privacy\Retention\ApplyRetention;
use App\Domain\Privacy\Retention\SaveRetentionPolicy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Retention per form and form legal holds (BUILD_PROMPT §11 Privacy, ADR 0039): each form with its retention
 * (keep, or delete/anonymize after N months) and whether it is under legal hold. A shorter period asks for a
 * second confirmation with the number of submissions the next nightly run removes; "Place legal hold" is
 * disabled, with the reason, while the retention is below the legal-hold minimum. Errors stay at the field.
 * Owners and Admins (organization.manage). The domain actions validate, authorize and audit.
 */
class RetentionSettings extends Page implements HasTable
{
    use InteractsWithTable;

    private const array RETENTION_FIELDS = ['months', 'action'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?int $navigationSort = 91;

    protected static ?string $slug = 'privacy/retention';

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', RetentionPolicy::class);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('privacy.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('privacy.retention.navigation');
    }

    public function getTitle(): string
    {
        return __('privacy.retention.title');
    }

    public function getSubheading(): string
    {
        return __('privacy.retention.subheading', ['months' => LegalHolds::minMonths()]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Form::query()->select('forms.*')->addSelect([
                'retention_months' => RetentionPolicy::query()->select('months')->whereColumn('retention_policies.form_id', 'forms.id'),
                'retention_action' => RetentionPolicy::query()->select('action')->whereColumn('retention_policies.form_id', 'forms.id'),
                'held_since' => LegalHold::query()->select('created_at')->whereColumn('legal_holds.form_id', 'forms.id')->whereNull('released_at'),
            ]))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('privacy.retention.columns.form'))->searchable()->sortable(),
                TextColumn::make('retention_months')->label(__('privacy.retention.columns.retention'))
                    ->state(fn (Form $record): string => $this->retentionLabel($record)),
                IconColumn::make('held_since')->label(__('privacy.retention.columns.legal_hold'))->boolean()
                    ->state(fn (Form $record): bool => $record->getAttribute('held_since') !== null),
            ])
            ->recordActions([$this->retentionAction(), $this->holdAction(), $this->releaseAction()])
            ->emptyStateHeading(__('privacy.retention.empty'));
    }

    private function retentionAction(): Action
    {
        return Action::make('retention')->label(__('privacy.retention.edit'))->icon(Heroicon::OutlinedClock)
            ->fillForm(fn (Form $record): array => ['months' => $record->getAttribute('retention_months'), 'action' => $record->getAttribute('retention_action') ?? RetentionAction::Anonymize->value])
            ->schema([
                TextInput::make('months')->label(__('privacy.retention.months'))->helperText(__('privacy.retention.months_help'))
                    ->integer()->minValue(1)->maxValue(SaveRetentionPolicy::MAX_MONTHS),
                Select::make('action')->label(__('privacy.retention.action'))->required()->selectablePlaceholder(false)
                    ->options(collect(RetentionAction::cases())->mapWithKeys(fn (RetentionAction $a): array => [$a->value => $a->label()])->all())
                    ->helperText(__('privacy.retention.action_help')),
            ])
            ->action(function (Form $record, array $data, Schema $schema): void {
                $months = is_numeric($data['months'] ?? null) ? (int) $data['months'] : null;
                $action = RetentionAction::from((string) $data['action']);
                try {
                    SaveRetentionPolicy::ensureValid($record, $months);
                } catch (ValidationException $e) {
                    throw PrivacyPage::underForm($e, $schema, self::RETENTION_FIELDS);
                }
                if ($months !== null && $this->shortens($record, $months)) {
                    $this->replaceMountedAction('confirmShorterRetention', ['form' => $record->id, 'months' => $months, 'action' => $action->value]);

                    return;
                }
                PrivacyPage::attemptInForm(
                    fn () => app(SaveRetentionPolicy::class)->handle($record, $months, $action, PrivacyPage::user()),
                    __('privacy.retention.saved'), $schema, self::RETENTION_FIELDS,
                );
            });
    }

    /** Second step when a period gets shorter (also from "keep"): how many submissions the next run removes. */
    public function confirmShorterRetentionAction(): Action
    {
        return Action::make('confirmShorterRetention')->color('danger')->icon(Heroicon::OutlinedExclamationTriangle)
            ->requiresConfirmation()->modalHeading(__('privacy.retention.shorten_heading'))
            ->modalDescription(function (array $arguments): string {
                [$form, $months, $action] = $this->shortening($arguments);

                return trans_choice('privacy.retention.shorten_description', ApplyRetention::dueQuery($form->id, $months, $action)->count(), [
                    'action' => __('privacy.retention.actions_done.'.$action->value),
                ]);
            })
            ->modalSubmitActionLabel(__('privacy.retention.shorten_submit'))
            ->action(function (array $arguments): void {
                [$form, $months, $action] = $this->shortening($arguments);
                PrivacyPage::attempt(fn () => app(SaveRetentionPolicy::class)->handle($form, $months, $action, PrivacyPage::user()), __('privacy.retention.saved'));
            });
    }

    private function holdAction(): Action
    {
        $minimum = LegalHolds::minMonths();

        return Action::make('hold')->label(__('privacy.holds.place'))->icon(Heroicon::OutlinedLockClosed)->color('warning')
            ->visible(fn (Form $record): bool => $record->getAttribute('held_since') === null)
            ->disabled(fn (Form $record): bool => $this->retentionTooShort($record))
            ->tooltip(fn (Form $record): ?string => $this->retentionTooShort($record) ? (string) __('privacy.holds.retention_too_short', ['months' => $minimum]) : null)
            ->modalDescription(__('privacy.holds.place_description', ['months' => $minimum]))
            ->schema([Textarea::make('reason')->label(__('privacy.holds.reason'))->required()->rows(3)->maxLength(1000)])
            ->action(fn (Form $record, array $data, Schema $schema): bool => PrivacyPage::attemptInForm(
                fn () => app(PlaceLegalHold::class)->forForm($record, (string) $data['reason'], PrivacyPage::user()),
                __('privacy.holds.placed'), $schema, ['reason'],
            ));
    }

    private function releaseAction(): Action
    {
        return Action::make('release')->label(__('privacy.holds.release'))->icon(Heroicon::OutlinedLockOpen)->color('gray')
            ->visible(fn (Form $record): bool => $record->getAttribute('held_since') !== null)
            ->requiresConfirmation()->modalDescription(__('privacy.holds.release_confirm'))
            ->action(fn (Form $record): bool => PrivacyPage::attempt(function () use ($record): void {
                $hold = LegalHold::query()->active()->where('form_id', $record->id)->firstOrFail();
                app(ReleaseLegalHold::class)->handle($hold, PrivacyPage::user());
            }, __('privacy.holds.released')));
    }

    /** The new period is shorter than the current one, or the form kept its submissions indefinitely so far. */
    private function shortens(Form $form, int $months): bool
    {
        $current = RetentionPolicy::query()->where('form_id', $form->id)->value('months');

        return ! is_numeric($current) || $months < (int) $current;
    }

    private function retentionTooShort(Form $record): bool
    {
        $months = $record->getAttribute('retention_months');

        return is_numeric($months) && (int) $months < LegalHolds::minMonths();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: Form, 1: int, 2: RetentionAction}
     */
    private function shortening(array $arguments): array
    {
        return [
            Form::query()->findOrFail((int) ($arguments['form'] ?? 0)),
            max(1, (int) ($arguments['months'] ?? 0)),
            RetentionAction::from((string) ($arguments['action'] ?? '')),
        ];
    }

    private function retentionLabel(Form $record): string
    {
        $months = $record->getAttribute('retention_months');
        if (! is_numeric($months)) {
            return (string) __('privacy.retention.keep');
        }
        $action = RetentionAction::tryFrom((string) $record->getAttribute('retention_action')) ?? RetentionAction::Anonymize;

        return trans_choice('privacy.retention.after_months', (int) $months, ['action' => $action->label(), 'count' => (int) $months]);
    }
}
