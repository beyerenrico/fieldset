<?php

declare(strict_types=1);

namespace App\Domain\Reports\Enums;

enum ExportStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
