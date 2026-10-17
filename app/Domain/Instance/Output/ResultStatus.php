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

    /** Outcomes after which nothing is left to resume: the journal is archived. */
    public function closesOperation(): bool
    {
        return in_array($this, [self::Ok, self::Aborted, self::RolledBack], true);
    }
}
