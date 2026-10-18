<?php

declare(strict_types=1);

namespace App\Domain\Instance\Operations;

/** A held instance lock; `replaced` is the stale lock it took over, which the caller journals. */
final readonly class LockHandle
{
    public function __construct(
        public LockRecord $record,
        public ?LockRecord $replaced = null,
    ) {}
}
