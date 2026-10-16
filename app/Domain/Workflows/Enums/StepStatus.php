<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Enums;

/** Status of one step of a run. */
enum StepStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Waiting = 'waiting';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('workflows.step_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending, self::Cancelled => 'gray',
            self::Running => 'info',
            self::Waiting => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }
}
