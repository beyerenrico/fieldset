<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Contracts\PasswordAuthGate;
use App\Domain\Identity\Sso\Exceptions\SsoRequiredException;

/**
 * Activates an invited membership (from the invitation link, or from an SSO login: `via: sso`). Setting a
 * password is refused for an email whose domain enforces SSO (ADR 0036): such people accept through SSO.
 */
final readonly class AcceptInvitation
{
    public function __construct(private AuditLogger $audit, private PasswordAuthGate $gate) {}

    /** @throws SsoRequiredException */
    public function handle(Membership $membership, ?string $password = null, string $via = 'invitation'): Membership
    {
        if ($membership->status !== MembershipStatus::Invited) {
            return $membership;
        }

        $user = $membership->user;
        if ($password !== null) {
            $this->gate->assertPasswordLogin($user->email);
            User::withoutPasswordAudit(fn () => $user->forceFill(['password' => $password])->save());
        }
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $membership->forceFill(['status' => MembershipStatus::Active, 'joined_at' => now()])->save();
        $this->audit->record('member.joined', $membership, ['password_set' => $password !== null, 'via' => $via], $user);

        return $membership;
    }
}
