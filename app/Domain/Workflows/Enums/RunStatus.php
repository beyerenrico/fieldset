<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Enums;

/** Status of a workflow run (ADR 0032). `rejected`: a review step ended the run with "changes requested". */
enum RunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Waiting = 'waiting';
    case Completed = 'completed';
    case Failed = 'failed';
    case Rejected = 'rejected';

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Rejected], true);
    }

    public function label(): string
    {
        return __('workflows.run_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Running => 'info',
            self::Waiting, self::Rejected => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }
}
