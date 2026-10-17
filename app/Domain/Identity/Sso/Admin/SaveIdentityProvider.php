<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Admin;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Sso\Support\NotImplemented;

/** Skeleton stub (M7, owner B): creates or updates the organization's provider; a null secret keeps the stored one. */
final readonly class SaveIdentityProvider
{
    /** @param  array<string, mixed>  $config */
    public function handle(Organization $org, IdentityProviderType $type, string $name, array $config, ?string $newSecret, User $actor): IdentityProvider
    {
        throw NotImplemented::exception(__METHOD__, $org, $type, $name, $config, $newSecret, $actor);
    }
}
