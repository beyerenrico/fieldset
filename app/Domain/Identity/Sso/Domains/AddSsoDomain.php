<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Domains;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Support\NotImplemented;

/** Skeleton stub (M7, owner B): claims a (normalized) email domain for the organization. */
final readonly class AddSsoDomain
{
    public function handle(Organization $org, string $domain, User $actor): SsoDomain
    {
        throw NotImplemented::exception(__METHOD__, $org, $domain, $actor);
    }
}
