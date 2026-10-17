<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Contracts;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Data\ProviderHealth;

interface ProviderHealthCheck
{
    public function check(IdentityProvider $p): ProviderHealth;
}
