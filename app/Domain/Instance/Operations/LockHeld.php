<?php

declare(strict_types=1);

namespace App\Domain\Instance\Operations;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;

/** Another live operation holds the instance lock. Nothing was changed (R69: exit 9), so it can be retried. */
final class LockHeld extends InstanceException
{
    public function __construct(public readonly LockRecord $holder)
    {
        parent::__construct(
            'Another fieldset operation holds the instance lock: '.$holder->describe().'.',
            ExitCode::AbortedUnchanged,
            'instance_locked',
            'Wait for it to finish (`fieldset status` shows its progress), then run the command again.',
            true,
            ['holder' => $holder->toArray()],
        );
    }
}
