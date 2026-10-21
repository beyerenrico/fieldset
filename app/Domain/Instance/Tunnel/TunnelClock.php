<?php

declare(strict_types=1);

namespace App\Domain\Instance\Tunnel;

use Carbon\CarbonImmutable;

/**
 * How long a quick tunnel lives (E11, R20): 4 hours from `fieldset tunnel start` or the last `fieldset tunnel renew`. The
 * release-harness journeys shorten it (HarnessTunnel); production images always use 4 hours.
 */
final class TunnelClock
{
    public const int LIFETIME_SECONDS = 4 * 3600;

    public static function lifetime(): int
    {
        return HarnessTunnel::ttl() ?? self::LIFETIME_SECONDS;
    }

    public static function expiry(?CarbonImmutable $from = null): CarbonImmutable
    {
        return ($from ?? CarbonImmutable::now('UTC'))->addSeconds(self::lifetime())->utc();
    }
}
