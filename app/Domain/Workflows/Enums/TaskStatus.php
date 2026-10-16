<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Enums;

enum TaskStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
}
