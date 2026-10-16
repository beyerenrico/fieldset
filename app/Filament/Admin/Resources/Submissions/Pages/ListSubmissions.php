<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions\Pages;

use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Filament\Resources\Pages\ListRecords;

class ListSubmissions extends ListRecords
{
    protected static string $resource = SubmissionResource::class;
}
