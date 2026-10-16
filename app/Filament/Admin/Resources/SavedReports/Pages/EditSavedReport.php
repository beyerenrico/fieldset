<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SavedReports\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Reports\Models\SavedReport;
use App\Domain\Reports\Saved\SaveReport;
use App\Filament\Admin\Resources\SavedReports\SavedReportResource;
use App\Filament\Admin\Resources\SavedReports\Schemas\SavedReportForm;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** @property SavedReport $record */
class EditSavedReport extends EditRecord
{
    protected static string $resource = SavedReportResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return SavedReportForm::toForm($data);
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var SavedReport $record */
        /** @var User $actor */
        $actor = auth()->user();
        try {
            return app(SaveReport::class)->handle($actor, $record, SavedReportForm::fromForm($data));
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw new Halt($e->getMessage(), $e->getCode(), $e);
        }
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), DeleteAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return SavedReportResource::getUrl('view', ['record' => $this->record]);
    }
}
