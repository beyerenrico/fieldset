<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Domains;

use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Enums\DomainVerification;
use App\Domain\Identity\Sso\Support\NotImplemented;

/** Skeleton stub (M7, owner B): checks the TXT record; a null actor is the scheduled re-check. */
final readonly class VerifySsoDomain
{
    public function handle(SsoDomain $domain, ?User $actor = null): DomainVerification
    {
        throw NotImplemented::exception(__METHOD__, $domain, $actor);
    }
}
