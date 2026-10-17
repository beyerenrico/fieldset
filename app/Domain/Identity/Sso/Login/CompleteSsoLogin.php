<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Login;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Data\ExternalProfile;
use App\Domain\Identity\Sso\Support\NotImplemented;

/**
 * Skeleton stub (M7, owner A): identity resolution and JIT for OIDC and SAML (ADR 0036). Refusals throw
 * SsoLoginRefused; add `@throws SsoLoginRefused` to handle() with the implementation (PHPStan rejects it
 * on a stub that cannot throw it).
 */
final readonly class CompleteSsoLogin
{
    public function handle(IdentityProvider $p, ExternalProfile $profile): User
    {
        throw NotImplemented::exception(__METHOD__, $p, $profile);
    }
}
