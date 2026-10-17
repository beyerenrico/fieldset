<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Domains;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Contracts\SsoMisconfigurationReporter;
use App\Domain\Identity\Sso\Contracts\TxtRecordResolver;
use App\Domain\Identity\Sso\Enums\DomainVerification;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Checks the domain's TXT record `_form-schema-app-verification.<domain>` = `form-schema-app-verification=<token>`
 * (ADR 0036). A null actor is the scheduled re-check.
 *
 * - Found: the domain becomes verified, unless another organization already holds it verified (refused with
 *   a ValidationException; the partial unique index backs the race).
 * - Missing or mismatching on a verified domain: counts a miss; after `sso.recheck.failures_before_unverify`
 *   misses in a row the domain is unverified, audited and reported as sso_misconfigured. A failed lookup
 *   (our resolver, SERVFAIL) is recorded but never counts as a miss, so a resolver outage cannot unverify
 *   every domain at once.
 *
 * Events: sso.domain_verified, sso.domain_verification_failed, sso.domain_unverified.
 */
final readonly class VerifySsoDomain
{
    public function __construct(
        private TxtRecordResolver $resolver,
        private AuditLogger $audit,
        private SsoMisconfigurationReporter $reporter,
        private TenantContext $tenant,
    ) {}

    /** @throws ValidationException when another organization holds the domain verified */
    public function handle(SsoDomain $domain, ?User $actor = null): DomainVerification
    {
        $result = $this->lookup($domain);

        return $this->tenant->run($domain->organization_id, fn (): DomainVerification => $result === DomainVerification::Verified
            ? $this->passed($domain, $actor)
            : $this->missed($domain, $result, $actor));
    }

    private function lookup(SsoDomain $domain): DomainVerification
    {
        try {
            $values = $this->resolver->txt($domain->txtName());
        } catch (TxtLookupFailed) {
            return DomainVerification::Error;
        } catch (Throwable $e) {
            report($e);

            return DomainVerification::Error;
        }

        foreach ($values as $value) {
            if (hash_equals($domain->txtValue(), trim($value, " \t\""))) {
                return DomainVerification::Verified;
            }
        }

        return $values === [] ? DomainVerification::Missing : DomainVerification::Mismatch;
    }

    private function passed(SsoDomain $domain, ?User $actor): DomainVerification
    {
        $wasVerified = $domain->isVerified();
        $domain->forceFill([
            'verified_at' => $domain->verified_at ?? now(),
            'last_checked_at' => now(),
            'last_check_result' => DomainVerification::Verified,
            'failed_checks' => 0,
        ]);

        if (! $wasVerified && $this->verifiedElsewhere($domain)) {
            $this->refuse($domain, $actor);
        }
        try {
            DB::transaction(static fn (): bool => $domain->save());
        } catch (UniqueConstraintViolationException) {
            $this->refuse($domain, $actor); // another organization verified it concurrently
        }

        if (! $wasVerified) {
            $this->audit->record('sso.domain_verified', $domain, ['domain' => $domain->domain], $actor);
        }

        return DomainVerification::Verified;
    }

    private function verifiedElsewhere(SsoDomain $domain): bool
    {
        $owner = SsoDomain::verifiedOwnerOf($domain->domain);

        return $owner instanceof SsoDomain && $owner->id !== $domain->id;
    }

    /** Another organization holds the domain verified: record the check, audit, refuse. */
    private function refuse(SsoDomain $domain, ?User $actor): never
    {
        $domain->forceFill(['verified_at' => null, 'last_check_result' => DomainVerification::Verified])->save();
        $this->audit->record('sso.domain_verification_failed', $domain, [
            'domain' => $domain->domain, 'result' => 'verified_elsewhere', 'scheduled' => ! $actor instanceof User,
        ], $actor);

        throw ValidationException::withMessages(['domain' => __('sso_admin.domains.errors.verified_elsewhere')]);
    }

    private function missed(SsoDomain $domain, DomainVerification $result, ?User $actor): DomainVerification
    {
        $domain->forceFill(['last_checked_at' => now(), 'last_check_result' => $result]);
        $countsAsMiss = $domain->isVerified() && $result !== DomainVerification::Error;
        if ($countsAsMiss) {
            $domain->failed_checks++;
        }

        if ($countsAsMiss && $domain->failed_checks >= $this->threshold()) {
            $domain->forceFill(['verified_at' => null])->save();
            $this->audit->record('sso.domain_unverified', $domain, [
                'domain' => $domain->domain, 'result' => $result->value, 'failed_checks' => $domain->failed_checks,
            ], $actor);
            $this->reportUnverified($domain);

            return $result;
        }

        $domain->save();
        $this->audit->record('sso.domain_verification_failed', $domain, [
            'domain' => $domain->domain, 'result' => $result->value, 'failed_checks' => $domain->failed_checks,
            'scheduled' => ! $actor instanceof User,
        ], $actor);

        return $result;
    }

    private function reportUnverified(SsoDomain $domain): void
    {
        $provider = IdentityProvider::query()->first();
        if ($provider instanceof IdentityProvider) {
            $this->reporter->report($provider, 'domain_unverified', $domain->domain);
        }
    }

    private function threshold(): int
    {
        return max(1, (int) config('sso.recheck.failures_before_unverify', 3));
    }
}
