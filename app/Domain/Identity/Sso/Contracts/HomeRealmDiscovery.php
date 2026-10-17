<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Contracts;

use App\Domain\Identity\Sso\Data\DiscoveryResult;
use App\Domain\Identity\Sso\Enums\SsoClient;

/** Domain-level home-realm discovery: verified domain → organization → provider → redirect URL (ADR 0036). */
interface HomeRealmDiscovery
{
    public function discover(string $email, SsoClient $client): DiscoveryResult;
}
