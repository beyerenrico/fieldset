<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\User;

final readonly class AcceptInvitation
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Membership $membership, ?string $password = null): Membership
    {
        if ($membership->status !== MembershipStatus::Invited) {
            return $membership;
        }

        $user = $membership->user;
        if ($password !== null) {
            User::withoutPasswordAudit(fn () => $user->forceFill(['password' => $password])->save());
        }
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $membership->forceFill(['status' => MembershipStatus::Active, 'joined_at' => now()])->save();
        $this->audit->record('member.joined', $membership, ['password_set' => $password !== null], $user);

        return $membership;
    }
}
