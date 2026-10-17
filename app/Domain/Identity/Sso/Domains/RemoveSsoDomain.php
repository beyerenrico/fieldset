<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Domains;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Removes an organization's domain claim (ADR 0036), audited once as `sso.domain_removed`. The last
 * verified domain cannot be removed while SSO is enforced: enforcement requires a verified domain, so it
 * has to be switched off first.
 */
final readonly class RemoveSsoDomain
{
    public function __construct(private TenantContext $tenant, private AuditLogger $audit) {}

    /** @throws ValidationException */
    public function handle(SsoDomain $domain, User $actor): void
    {
        $this->tenant->run($domain->organization_id, function () use ($domain, $actor): void {
            if ($domain->isVerified() && $this->isLastVerifiedUnderEnforcement($domain)) {
                throw ValidationException::withMessages(['domain' => __('sso_admin.domains.errors.last_verified_enforced')]);
            }

            $wasVerified = $domain->isVerified();
            $domain->disableLogging()->delete();
            $domain->enableLogging();
            $this->audit->record('sso.domain_removed', $domain, ['domain' => $domain->domain, 'was_verified' => $wasVerified], $actor);
        });
    }

    private function isLastVerifiedUnderEnforcement(SsoDomain $domain): bool
    {
        $enforced = IdentityProvider::query()->where('enforce_sso', true)->exists();

        return $enforced && ! SsoDomain::query()->whereNotNull('verified_at')->whereKeyNot($domain->id)->exists();
    }
}
