<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Health;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Contracts\ProviderHealthCheck;
use App\Domain\Identity\Sso\Data\ProviderHealth;
use LogicException;

/** Skeleton stub (M7, owner A). */
final readonly class FetchProviderHealth implements ProviderHealthCheck
{
    public function check(IdentityProvider $p): ProviderHealth
    {
        throw new LogicException('not implemented');
    }
}
