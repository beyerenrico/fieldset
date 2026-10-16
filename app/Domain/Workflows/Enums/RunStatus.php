<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Enums;

/**
 * Status of a workflow run (ADR 0032). `rejected`: a review step ended the run with "changes requested";
 * `cancelled`: the submission left review another way while the run waited for its task.
 */
enum RunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Waiting = 'waiting';
    case Completed = 'completed';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Rejected, self::Cancelled], true);
    }

    public function label(): string
    {
        return __('workflows.run_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending, self::Cancelled => 'gray',
            self::Running => 'info',
            self::Waiting, self::Rejected => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }
}
