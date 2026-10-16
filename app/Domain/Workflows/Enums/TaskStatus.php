<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Enums;

/** `cancelled`: the submission left review another way (ADR 0032); completing it answers 409 task_closed. */
enum TaskStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
