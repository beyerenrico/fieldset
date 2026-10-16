<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Workflows;

use App\Domain\Workflows\Models\Workflow;
use App\Filament\Admin\Resources\Workflows\Pages\CreateWorkflow;
use App\Filament\Admin\Resources\Workflows\Pages\EditWorkflow;
use App\Filament\Admin\Resources\Workflows\Pages\ListWorkflows;
use App\Filament\Admin\Resources\Workflows\RelationManagers\RunsRelationManager;
use App\Filament\Admin\Resources\Workflows\Schemas\WorkflowForm;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Workflows (§8.3): trigger, conditions and steps; runs with a per-step timeline (WorkflowPolicy, `workflows.manage`). */
class WorkflowResource extends Resource
{
    protected static ?string $model = Workflow::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('workflows.navigation.group');
    }

    public static function getModelLabel(): string
    {
        return __('workflows.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('workflows.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return WorkflowForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('runs'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('workflows.fields.name'))->searchable()->sortable(),
                TextColumn::make('trigger_type')->label(__('workflows.fields.trigger_type'))->badge()
                    ->formatStateUsing(fn (Workflow $record): string => $record->trigger_type->label()),
                TextColumn::make('steps')->label(__('workflows.fields.steps_count'))
                    ->state(fn (Workflow $record): int => count($record->steps)),
                IconColumn::make('active')->label(__('workflows.fields.active'))->boolean(),
                TextColumn::make('runs_count')->label(__('workflows.fields.runs_count'))->sortable(),
                TextColumn::make('updated_at')->label(__('workflows.fields.updated_at'))->since()->sortable(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getRelations(): array
    {
        return [RunsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkflows::route('/'),
            'create' => CreateWorkflow::route('/create'),
            'edit' => EditWorkflow::route('/{record}/edit'),
        ];
    }
}
