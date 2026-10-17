<?php

declare(strict_types=1);

namespace App\Domain\Platform\Impersonation;

use RuntimeException;

/** A refused impersonation start; `code` is stable (tests, UI), the message is translated. */
final class ImpersonationRefused extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct((string) __('platform.impersonation.refused.'.$reasonCode));
    }
}
