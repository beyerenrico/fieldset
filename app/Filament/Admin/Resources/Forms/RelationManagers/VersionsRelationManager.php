<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms\RelationManagers;

use App\Domain\Forms\Actions\RetireVersion;
use App\Domain\Forms\Actions\RollbackForm;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Forms\Schema\DefinitionDiff;
use App\Domain\Forms\Schema\DiffEntry;
use App\Domain\Identity\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\View;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Version history timeline with diff, rollback and retire (BUILD_PROMPT §8.10). */
class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('forms.versions.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('version', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('publisher'))
            ->columns([
                TextColumn::make('version')->label(__('forms.versions.version'))->prefix('v')->sortable(),
                TextColumn::make('published_at')->label(__('forms.versions.published_at'))->dateTime()->sortable(),
                TextColumn::make('publisher.name')->label(__('forms.versions.published_by')),
                TextColumn::make('changelog')->label(__('forms.versions.changelog'))->wrap()->limit(120),
                IconColumn::make('retired_at')->label(__('forms.versions.retired'))->boolean()
                    ->state(fn (FormVersion $record): bool => $record->isRetired()),
                TextColumn::make('checksum')->label('SHA-256')->limit(12)->fontFamily('mono')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([$this->diffAction(), $this->rollbackAction(), $this->retireAction()]);
    }

    private function diffAction(): Action
    {
        return Action::make('diff')->label(__('forms.actions.diff'))->icon('heroicon-o-arrows-right-left')->color('gray')
            ->modalSubmitAction(false)
            ->schema(fn (FormVersion $record): array => [
                Select::make('against')->label(__('forms.versions.diff_to'))->live()->default('draft')
                    ->options(['draft' => __('forms.versions.draft')] + $this->ownerForm()->versions()->pluck('version', 'id')
                        ->mapWithKeys(fn ($v, $id): array => [(string) $id => 'v'.$v])->all()),
                View::make('filament.forms.version-diff')->viewData(fn ($get): array => [
                    'entries' => $this->diffEntries($record, (string) ($get('against') ?? 'draft')),
                ]),
            ]);
    }

    /** @return list<DiffEntry> */
    private function diffEntries(FormVersion $from, string $against): array
    {
        $to = $against === 'draft'
            ? $this->ownerForm()->draft_definition
            : FormVersion::query()->where('form_id', $this->ownerForm()->id)->findOrFail((int) $against)->definition;

        return DefinitionDiff::compare($from->definition, $to);
    }

    private function rollbackAction(): Action
    {
        return Action::make('rollback')->label(__('forms.actions.rollback'))->icon('heroicon-o-arrow-uturn-left')->color('warning')
            ->requiresConfirmation()
            ->authorize(fn (): bool => auth()->user()?->can('publish', $this->ownerForm()) ?? false)
            ->schema([Textarea::make('changelog')->label(__('forms.versions.changelog'))->rows(2)])
            ->action(function (FormVersion $record, array $data): void {
                $version = app(RollbackForm::class)->handle($this->ownerForm(), $record, $this->actor(), $data['changelog'] ?? null);
                Notification::make()->success()->title(__('forms.versions.published', ['version' => $version->version]))->send();
                $this->redirect(request()->header('Referer') ?? url()->current());
            });
    }

    private function retireAction(): Action
    {
        return Action::make('retire')->label(__('forms.actions.retire'))->icon('heroicon-o-no-symbol')->color('danger')
            ->requiresConfirmation()
            ->visible(fn (FormVersion $record): bool => ! $record->isRetired())
            ->authorize(fn (FormVersion $record): bool => auth()->user()?->can('retire', $record) ?? false)
            ->action(fn (FormVersion $record) => app(RetireVersion::class)->handle($record, $this->actor()));
    }

    private function ownerForm(): Form
    {
        /** @var Form $form */
        $form = $this->getOwnerRecord();

        return $form;
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
