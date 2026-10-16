<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Workflows\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Workflows\Actions\SaveWorkflow;
use App\Domain\Workflows\Models\Workflow;
use App\Filament\Admin\Resources\Workflows\Schemas\WorkflowForm;
use App\Filament\Admin\Resources\Workflows\WorkflowResource;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** @property Workflow $record */
class EditWorkflow extends EditRecord
{
    protected static string $resource = WorkflowResource::class;

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return WorkflowForm::fill($this->record);
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Workflow $record */
        /** @var User $actor */
        $actor = auth()->user();
        try {
            return app(SaveWorkflow::class)->handle($record, WorkflowForm::data($data), $actor);
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw new Halt($e->getMessage(), $e->getCode(), $e);
        }
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
