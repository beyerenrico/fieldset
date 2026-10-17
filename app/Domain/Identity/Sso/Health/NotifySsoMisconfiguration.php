<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Health;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Contracts\SsoMisconfigurationReporter;

/** Skeleton stub (M7, owner B): no-op until implemented. */
final readonly class NotifySsoMisconfiguration implements SsoMisconfigurationReporter
{
    public function report(IdentityProvider $p, string $reason, string $detail): void {}
}
