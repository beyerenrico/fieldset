<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Deletes the organization's identity provider with its claim mappings and linked identities (FK cascade),
 * audited once as `sso.provider_deleted`. Refused while SSO is enforced: switch enforcement off first.
 */
final readonly class DeleteIdentityProvider
{
    public function __construct(private TenantContext $tenant, private AuditLogger $audit) {}

    /** @throws ValidationException */
    public function handle(IdentityProvider $provider, User $actor): void
    {
        $this->tenant->run($provider->organization_id, function () use ($provider, $actor): void {
            if ($provider->enforce_sso) {
                throw ValidationException::withMessages(['provider' => __('sso_admin.provider.errors.delete_enforced')]);
            }

            $provider->disableLogging()->delete();
            $provider->enableLogging();
            $this->audit->record('sso.provider_deleted', $provider, ['name' => $provider->name, 'type' => $provider->type->value], $actor);
        });
    }
}
