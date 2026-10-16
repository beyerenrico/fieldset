<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SavedReports\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Reports\Saved\SaveReport;
use App\Filament\Admin\Resources\SavedReports\SavedReportResource;
use App\Filament\Admin\Resources\SavedReports\Schemas\SavedReportForm;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class CreateSavedReport extends CreateRecord
{
    protected static string $resource = SavedReportResource::class;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();
        try {
            return app(SaveReport::class)->handle($actor, null, SavedReportForm::fromForm($data));
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw new Halt($e->getMessage(), $e->getCode(), $e);
        }
    }

    protected function getRedirectUrl(): string
    {
        return SavedReportResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
