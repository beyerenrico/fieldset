<?php

declare(strict_types=1);

namespace App\Domain\Platform\Impersonation;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Platform\PlatformAdmins;
use Carbon\CarbonImmutable;

/**
 * An operator signs in as an admin-panel member of one organization (BUILD_PROMPT §8.12, ADR 0041).
 * Every rule is checked here on the server, whatever the UI showed: the operator is an active platform
 * admin and not already impersonating; the reason is 10–500 characters; the target is an active member
 * with admin access to that (not archived) organization and never a platform admin.
 * The start is audited (inside the organization, so its own audit log shows it) before the session switches.
 */
final readonly class StartImpersonation
{
    public const int REASON_MIN = 10;

    public const int REASON_MAX = 500;

    public function __construct(
        private PlatformAdmins $admins,
        private ImpersonationStore $store,
        private SessionLogin $login,
        private AuditLogger $audit,
        private TenantContext $tenant,
    ) {}

    /** @throws ImpersonationRefused */
    public function handle(User $operator, Organization $organization, User $target, string $reason): Impersonation
    {
        $reason = trim($reason);
        $this->guard($operator, $organization, $target, $reason);

        $now = CarbonImmutable::now();
        $impersonation = new Impersonation($operator->id, $target->id, $organization->id, $reason, $now, $now->addMinutes(self::minutes()));

        $this->tenant->run($organization, fn (): mixed => $this->audit->record(
            'platform.impersonation_started', $target, $impersonation->auditProperties($operator, $target), $operator,
        ));
        $this->login->switchTo($target);
        $this->store->put($impersonation);

        return $impersonation;
    }

    /** Lifetime of one impersonation (platform.impersonation.minutes, 1–240, default 30). */
    public static function minutes(): int
    {
        return min(240, max(1, (int) config('platform.impersonation.minutes', 30)));
    }

    private function guard(User $operator, Organization $organization, User $target, string $reason): void
    {
        $refusal = match (true) {
            ! $this->admins->check($operator) => 'not_operator',
            $this->store->active() => 'already_impersonating',
            mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > self::REASON_MAX => 'reason_invalid',
            $organization->trashed() => 'organization_unavailable',
            $this->admins->granted($target) => 'target_is_operator',
            ! $target->canAccessTenant($organization) => 'target_not_admin_member',
            default => null,
        };

        if ($refusal !== null) {
            throw new ImpersonationRefused($refusal);
        }
    }
}
