<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\ExternalIdentity;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Switches SSO enforcement and the break-glass Owner (ADR 0036, Enforcement). Switching on (or changing
 * the break-glass Owner while on) requires all of:
 * - a verified domain of the organization;
 * - an active provider (status active after a successful test login);
 * - a break-glass user who is an active Owner with confirmed MFA (BreakGlassOwners);
 * - an actor who is that break-glass Owner or already has a linked identity at this provider, so nobody
 *   locks themselves out.
 * Every unmet precondition is reported at once (keys `enforce_sso` and `break_glass_user_id`).
 * Switching on ends the password sessions and remember-me tokens of the enforced domains (EndPasswordSessions).
 * Switching off needs an Owner-level actor (SsoOwnerGuard) and alerts the other Owners (SsoSecurityAlerts);
 * it keeps the designated break-glass Owner. Audited as `sso.enforcement_changed` (with `sessions_ended`
 * when switched on).
 */
final readonly class SetSsoEnforcement
{
    public function __construct(
        private BreakGlassOwners $breakGlassOwners,
        private SsoOwnerGuard $owners,
        private EndPasswordSessions $sessions,
        private SsoSecurityAlerts $alerts,
        private TenantContext $tenant,
        private AuditLogger $audit,
    ) {}

    /** @throws ValidationException */
    public function handle(IdentityProvider $p, bool $on, ?User $breakGlass, User $actor): void
    {
        $disabled = $this->tenant->run($p->organization_id, fn (): bool => DB::transaction(function () use ($p, $on, $breakGlass, $actor): bool {
            $p->refresh();
            $wasOn = $p->enforce_sso;
            if ($on) {
                $this->assertPreconditions($p, $breakGlass, $actor);
            } elseif ($wasOn && ! $this->owners->isOwnerLevel($actor, $p->organization_id)) {
                throw ValidationException::withMessages(['enforce_sso' => __('sso_admin.enforcement.errors.owner_only_disable')]);
            }

            $before = ['enforce_sso' => $wasOn, 'break_glass_user_id' => $p->break_glass_user_id];
            $p->forceFill(['enforce_sso' => $on, 'break_glass_user_id' => $breakGlass->id ?? $p->break_glass_user_id]);
            if (! $p->isDirty(['enforce_sso', 'break_glass_user_id'])) {
                return false;
            }

            $p->save();
            $properties = [
                'before' => $before,
                'after' => ['enforce_sso' => $p->enforce_sso, 'break_glass_user_id' => $p->break_glass_user_id],
            ];
            if ($on && ! $wasOn) {
                $properties['sessions_ended'] = $this->sessions->handle($p);
            }
            $this->audit->record('sso.enforcement_changed', $p, $properties, $actor);

            return $wasOn && ! $on;
        }));

        if ($disabled === true) {
            $this->alerts->send(SsoSecurityAlerts::ENFORCEMENT_DISABLED, $p, $actor);
        }
    }

    /**
     * The preconditions for the given break-glass user and actor; `break_glass` is null when valid, else the
     * problem (required | not_owner | no_mfa).
     *
     * @return array{verified_domain: bool, provider_active: bool, break_glass: string|null, no_lockout: bool}
     */
    public function conditions(IdentityProvider $p, ?User $breakGlass, User $actor): array
    {
        return [
            'verified_domain' => SsoDomain::query()->where('organization_id', $p->organization_id)->whereNotNull('verified_at')->exists(),
            'provider_active' => $p->isActive(),
            'break_glass' => $breakGlass instanceof User ? $this->breakGlassOwners->problem($breakGlass, $p->organization_id) : 'required',
            'no_lockout' => $actor->is($breakGlass) || $this->hasLinkedIdentity($p, $actor),
        ];
    }

    /**
     * @return array<string, list<string>> unmet preconditions (empty when enforcement may be switched on)
     */
    public function unmet(IdentityProvider $p, ?User $breakGlass, User $actor): array
    {
        $conditions = $this->conditions($p, $breakGlass, $actor);
        $errors = [];
        if (! $conditions['verified_domain']) {
            $errors['enforce_sso'][] = (string) __('sso_admin.enforcement.errors.no_verified_domain');
        }
        if (! $conditions['provider_active']) {
            $errors['enforce_sso'][] = (string) __('sso_admin.enforcement.errors.provider_not_active');
        }
        if ($conditions['break_glass'] !== null) {
            $errors['break_glass_user_id'][] = (string) __('sso_admin.enforcement.errors.break_glass_'.$conditions['break_glass']);
        }
        if (! $conditions['no_lockout']) {
            $errors['enforce_sso'][] = (string) __('sso_admin.enforcement.errors.self_lockout');
        }

        return $errors;
    }

    private function assertPreconditions(IdentityProvider $p, ?User $breakGlass, User $actor): void
    {
        $errors = $this->unmet($p, $breakGlass, $actor);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function hasLinkedIdentity(IdentityProvider $p, User $actor): bool
    {
        return ExternalIdentity::query()->where('identity_provider_id', $p->id)->where('user_id', $actor->id)->exists();
    }
}
