<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Admin;

use App\Domain\Identity\Models\IdentityProviderMapping;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Deletes a claim mapping. Roles and groups it granted stay until the user's next SSO login re-syncs the
 * IdP-managed set (ADR 0036). Audited by the mapping model.
 */
final readonly class DeleteClaimMapping
{
    public function __construct(private TenantContext $tenant, private CauserResolver $causer) {}

    public function handle(IdentityProviderMapping $mapping, User $actor): void
    {
        $this->tenant->run($mapping->organization_id, fn (): mixed => $this->causer->withCauser($actor, fn (): ?bool => $mapping->delete()));
    }
}
