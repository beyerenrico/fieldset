<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Designs\RelationManagers;

use App\Domain\Documents\Designs\Actions\RetireDesignVersion;
use App\Domain\Documents\Designs\Actions\RollbackDesign;
use App\Domain\Documents\Designs\DesignDiff;
use App\Domain\Documents\Models\Design;
use App\Domain\Documents\Models\DesignVersion;
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

/** Design version history with diff, rollback (new version N+1) and retire (BUILD_PROMPT §8.10). */
class DesignVersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    /** Re-authorize on every Livewire request: permissions may change while a tab stays open. */
    public function hydrate(): void
    {
        abort_unless(auth()->user()?->can('view', $this->ownerDesign()) ?? false, 403);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('documents.versions.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('documents.versions.version'))
            ->pluralModelLabel(__('documents.versions.title'))
            ->defaultSort('version', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('publisher'))
            ->columns([
                TextColumn::make('version')->label(__('documents.versions.version'))->prefix('v')->sortable(),
                TextColumn::make('published_at')->label(__('documents.versions.published_at'))->dateTime()->sortable(),
                TextColumn::make('publisher.name')->label(__('documents.versions.published_by'))->placeholder('—'),
                TextColumn::make('changelog')->label(__('documents.versions.changelog'))->wrap()->limit(120),
                IconColumn::make('retired_at')->label(__('documents.versions.retired'))->boolean()
                    ->state(fn (DesignVersion $record): bool => $record->isRetired()),
            ])
            ->recordActions([$this->diffAction(), $this->rollbackAction(), $this->retireAction()]);
    }

    private function diffAction(): Action
    {
        return Action::make('diff')->label(__('documents.actions.diff'))->icon('heroicon-o-arrows-right-left')->color('gray')
            ->authorize(fn (): bool => auth()->user()?->can('view', $this->ownerDesign()) ?? false)
            ->modalSubmitAction(false)
            ->schema(fn (DesignVersion $record): array => [
                Select::make('against')->label(__('documents.versions.diff_to'))->live()->default('draft')
                    ->options(['draft' => __('documents.versions.draft')] + $this->ownerDesign()->versions()->pluck('version', 'id')
                        ->mapWithKeys(fn ($v, $id): array => [(string) $id => 'v'.$v])->all()),
                View::make('documents.admin.design-diff')->viewData(fn ($get): array => [
                    'changes' => $this->changes($record, (string) ($get('against') ?? 'draft')),
                ]),
            ]);
    }

    /** @return list<array{setting: string, before: string, after: string}> */
    private function changes(DesignVersion $from, string $against): array
    {
        $to = $against === 'draft'
            ? $this->ownerDesign()->settings
            : DesignVersion::query()->where('design_id', $this->ownerDesign()->id)->findOrFail((int) $against)->settings;

        return DesignDiff::compare($from->settings, $to);
    }

    private function rollbackAction(): Action
    {
        return Action::make('rollback')->label(__('documents.actions.rollback'))->icon('heroicon-o-arrow-uturn-left')->color('warning')
            ->requiresConfirmation()
            ->authorize(fn (): bool => auth()->user()?->can('publish', $this->ownerDesign()) ?? false)
            ->schema([Textarea::make('changelog')->label(__('documents.versions.changelog'))->rows(2)])
            ->action(function (DesignVersion $record, array $data): void {
                $version = app(RollbackDesign::class)->handle($this->ownerDesign(), $record, $this->actor(), $data['changelog'] ?? null);
                Notification::make()->success()->title(__('documents.versions.published', ['version' => $version->version]))->send();
                $this->redirect(request()->header('Referer') ?? url()->current());
            });
    }

    private function retireAction(): Action
    {
        return Action::make('retire')->label(__('documents.actions.retire'))->icon('heroicon-o-no-symbol')->color('danger')
            ->requiresConfirmation()
            ->visible(fn (DesignVersion $record): bool => ! $record->isRetired())
            ->authorize(fn (DesignVersion $record): bool => auth()->user()?->can('retire', $record) ?? false)
            ->action(fn (DesignVersion $record) => app(RetireDesignVersion::class)->handle($record, $this->actor()));
    }

    private function ownerDesign(): Design
    {
        /** @var Design $design */
        $design = $this->getOwnerRecord();

        return $design;
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
