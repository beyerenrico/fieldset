<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\MemberInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Invites a person by email: creates the user if needed, an `invited` membership with roles and
 * groups, and mails a signed acceptance link (BUILD_PROMPT §8.12).
 */
final class InviteMember
{
    public function __construct(
        private readonly AddMember $addMember,
        private readonly SyncMemberGroups $syncGroups,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<SystemRole|string>  $roles
     * @param  list<int>  $groupIds
     */
    public function handle(Organization $organization, string $email, string $name, array $roles, array $groupIds = []): Membership
    {
        $membership = DB::transaction(function () use ($organization, $email, $name, $roles, $groupIds): Membership {
            $user = User::query()->firstOrCreate(
                ['email' => Str::lower(trim($email))],
                ['name' => $name, 'password' => Str::password(40)],
            );

            $existing = Membership::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->first();
            $status = $existing?->status === MembershipStatus::Active ? MembershipStatus::Active : MembershipStatus::Invited;

            $membership = $this->addMember->handle($organization, $user, $roles, $status);
            $membership->forceFill(['invited_at' => now()])->save();
            $this->syncGroups->handle($organization, $user, $groupIds);

            return $membership;
        });

        $membership->user->notify(new MemberInvitation($membership));
        $this->audit->record('member.invited', $membership, ['email' => $membership->user->email, 'roles' => $roles]);

        return $membership;
    }
}
