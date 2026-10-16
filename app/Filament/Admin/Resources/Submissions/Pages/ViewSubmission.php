<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions\Pages;

use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/** Read-only detail of a submission (M3-T09); review actions follow in M4. */
class ViewSubmission extends ViewRecord
{
    protected static string $resource = SubmissionResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->getRecordTitle();
    }
}
