<?php

declare(strict_types=1);

namespace App\Domain\Instance\Operations;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;

/**
 * An earlier operation stopped half-way (R54). Nothing new was started (exit 9); the operator resumes it by
 * running the same command again with the same flags (as `fsa status` says).
 */
final class OperationUnfinished extends InstanceException
{
    public function __construct(public readonly JournalRecord $record)
    {
        $operation = $record->operation();
        $phase = $record->lastStartedPhase();
        parent::__construct(
            "An earlier `fsa {$operation}` did not finish (last phase: ".($phase ?? 'none').').',
            ExitCode::AbortedUnchanged,
            'operation_unfinished',
            "Run `fsa {$operation}` again with the same flags to resume it.".($operation === 'install' ? ' `fsa uninstall` removes a half-finished install.' : ''),
            false,
            ['operation' => $operation, 'last_phase' => $phase, 'started_at' => $record->startedAt(), 'journal' => $record->id()],
        );
    }
}
