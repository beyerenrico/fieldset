<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MailMessages\Pages;

use App\Filament\Admin\Resources\MailMessages\MailMessageResource;
use Filament\Resources\Pages\ListRecords;

class ListMailMessages extends ListRecords
{
    protected static string $resource = MailMessageResource::class;
}
