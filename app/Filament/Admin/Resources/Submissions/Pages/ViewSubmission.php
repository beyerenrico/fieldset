<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions\Pages;

use App\Filament\Admin\Resources\Submissions\Actions\RerenderAction;
use App\Filament\Admin\Resources\Submissions\Actions\SendToCustomerAction;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/** Detail of a submission (M3-T09) with "Send to customer" and "Re-render PDF"; review actions follow in M4. */
class ViewSubmission extends ViewRecord
{
    protected static string $resource = SubmissionResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->getRecordTitle();
    }

    protected function getHeaderActions(): array
    {
        return [SendToCustomerAction::make(), RerenderAction::make()];
    }
}
