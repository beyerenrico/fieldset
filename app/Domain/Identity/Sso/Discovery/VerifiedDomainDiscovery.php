<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Discovery;

use App\Domain\Identity\Sso\Contracts\HomeRealmDiscovery;
use App\Domain\Identity\Sso\Data\DiscoveryResult;
use App\Domain\Identity\Sso\Enums\SsoClient;

/** Skeleton stub (M7, owner A): always password mode until implemented. */
final readonly class VerifiedDomainDiscovery implements HomeRealmDiscovery
{
    public function discover(string $email, SsoClient $client): DiscoveryResult
    {
        return DiscoveryResult::password();
    }
}
