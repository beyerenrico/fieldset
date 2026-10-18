<?php

declare(strict_types=1);

namespace App\Domain\Instance\Output;

/** `status` of the result envelope (plan "Result envelope"); also the outcome of a finished operation journal. */
enum ResultStatus: string
{
    case Ok = 'ok';
    case Failed = 'failed';
    case Aborted = 'aborted';
    case RolledBack = 'rolled_back';
    case RollbackFailed = 'rollback_failed';

    /**
     * Outcomes after which nothing is left to resume once something changed: the journal is archived. An aborted
     * or failed run that changed something keeps it open (a run that changed nothing is always archived).
     */
    public function closesOperation(): bool
    {
        return in_array($this, [self::Ok, self::RolledBack], true);
    }
}
