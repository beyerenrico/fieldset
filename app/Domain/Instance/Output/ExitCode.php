<?php

declare(strict_types=1);

namespace App\Domain\Instance\Output;

/**
 * `fieldset` exit codes: BUILD_PROMPT_V2 §7 plus 8 and 9 (plan KTD3) and 1 for unexpected internal errors (ADR 0066).
 * Change only with an ADR; agents branch on these numbers.
 */
enum ExitCode: int
{
    case Success = 0;
    case InternalError = 1;
    case InvalidInput = 2;
    case PreflightFailed = 3;
    case HealthCheckFailed = 4;
    case RolledBack = 5;
    case ConfirmationRequired = 6;
    case Incompatible = 7;
    case RollbackFailed = 8;
    case AbortedUnchanged = 9;
    case DockerUnavailable = 10;

    /** The envelope status that goes with this exit code. */
    public function status(): ResultStatus
    {
        return match ($this) {
            self::Success => ResultStatus::Ok,
            self::RolledBack => ResultStatus::RolledBack,
            self::RollbackFailed => ResultStatus::RollbackFailed,
            self::AbortedUnchanged, self::ConfirmationRequired => ResultStatus::Aborted,
            default => ResultStatus::Failed,
        };
    }

    /** Whether a failure with this code is known to have happened before anything changed. */
    public function isBeforeChange(): bool
    {
        return in_array($this, [self::InvalidInput, self::PreflightFailed, self::ConfirmationRequired, self::Incompatible, self::AbortedUnchanged, self::DockerUnavailable], true);
    }
}
