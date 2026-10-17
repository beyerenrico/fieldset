<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Contracts;

use App\Domain\Identity\Models\IdentityProvider;

/** Raises NotificationEvent::SsoMisconfigured to Owners and Admins, at most once per provider and reason per window. */
interface SsoMisconfigurationReporter
{
    public function report(IdentityProvider $p, string $reason, string $detail): void;
}
