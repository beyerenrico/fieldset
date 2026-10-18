<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

use App\Domain\Instance\InstanceException;

/**
 * The Docker gateway was requested outside an `fsa:*` command running in a marked instance directory
 * (KTD1, KTD25): web, queue and scheduler processes never hold the socket.
 */
final class OpsContextRequired extends InstanceException
{
    public function __construct()
    {
        parent::__construct('The Docker gateway is only available to fsa commands inside a marked instance directory.', errorCode: 'ops_context_required');
    }
}
