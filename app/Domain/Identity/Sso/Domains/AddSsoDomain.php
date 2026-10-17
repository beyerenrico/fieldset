<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Domains;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Claims a normalized email domain for the organization (ADR 0036). Several organizations may claim the
 * same domain while it is unverified; only one can verify it (VerifySsoDomain, partial unique index).
 * Audited once as `sso.domain_added` (the model's own create entry is suppressed).
 */
final readonly class AddSsoDomain
{
    public function __construct(private TenantContext $tenant, private AuditLogger $audit) {}

    /** @throws ValidationException */
    public function handle(Organization $org, string $domain, User $actor): SsoDomain
    {
        try {
            $name = DomainName::normalize($domain);
        } catch (InvalidDomainName $e) {
            throw ValidationException::withMessages(['domain' => __('sso_admin.domains.errors.'.$e->reason)]);
        }

        return $this->tenant->run($org, function () use ($name, $actor): SsoDomain {
            if (SsoDomain::query()->where('domain', $name)->exists()) {
                throw $this->alreadyClaimed();
            }

            $claim = new SsoDomain(['domain' => $name, 'created_by' => $actor->id]);
            try {
                DB::transaction(static fn (): bool => $claim->disableLogging()->save());
            } catch (UniqueConstraintViolationException) {
                throw $this->alreadyClaimed(); // a concurrent request claimed it first
            }
            $claim->enableLogging();
            $this->audit->record('sso.domain_added', $claim, ['domain' => $name], $actor);

            return $claim;
        });
    }

    private function alreadyClaimed(): ValidationException
    {
        return ValidationException::withMessages(['domain' => __('sso_admin.domains.errors.already_claimed')]);
    }
}
