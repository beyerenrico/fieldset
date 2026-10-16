<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Workflows\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Workflows\Actions\SaveWorkflow;
use App\Filament\Admin\Resources\Workflows\Schemas\WorkflowForm;
use App\Filament\Admin\Resources\Workflows\WorkflowResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class CreateWorkflow extends CreateRecord
{
    protected static string $resource = WorkflowResource::class;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();
        try {
            return app(SaveWorkflow::class)->handle(null, WorkflowForm::data($data), $actor);
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw new Halt($e->getMessage(), $e->getCode(), $e);
        }
    }

    protected function getRedirectUrl(): string
    {
        return WorkflowResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
