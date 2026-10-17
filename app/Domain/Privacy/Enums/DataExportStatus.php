<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Enums;

enum DataExportStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return __('privacy.exports.status.'.$this->value);
    }
}
