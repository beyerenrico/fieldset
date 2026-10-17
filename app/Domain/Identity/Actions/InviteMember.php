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
use App\Domain\Identity\Support\RoleAssignmentGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invites a person by email: creates the user if needed, an `invited` membership with roles and
 * groups, and mails a signed acceptance link (BUILD_PROMPT §8.12).
 */
final readonly class InviteMember
{
    public function __construct(
        private RoleAssignmentGuard $guard,
        private AddMember $addMember,
        private SyncMemberGroups $syncGroups,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  list<SystemRole|string>  $roles
     * @param  list<int>  $groupIds
     */
    public function handle(User $actor, Organization $organization, string $email, string $name, array $roles, array $groupIds = []): Membership
    {
        $roleNames = array_map(static fn (SystemRole|string $r): string => $r instanceof SystemRole ? $r->value : $r, $roles);
        $this->guard->authorizeRoleChange($actor, $organization, null, [], $roleNames);

        $membership = DB::transaction(function () use ($organization, $email, $name, $roles, $groupIds): Membership {
            // member.invited (with the email) is the one audit entry of an invitation, also for a new account.
            /** @var User $user */
            $user = activity()->withoutLogging(fn (): User => User::query()->firstOrCreate(
                ['email' => Str::lower(trim($email))],
                ['name' => $name, 'password' => Str::password(40)],
            ));
            if ($user->wasRecentlyCreated) {
                $user->forceFill(['password_known' => false])->saveQuietly(); // set on acceptance (ADR 0039)
            }

            $existing = Membership::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->first();
            if ($existing !== null && $existing->status !== MembershipStatus::Invited) {
                throw ValidationException::withMessages(['email' => __('identity.member.already_member')]);
            }

            $membership = $this->addMember->handle($organization, $user, $roles, MembershipStatus::Invited);
            $membership->forceFill(['invited_at' => now()])->save();
            $this->syncGroups->handle($organization, $user, $groupIds);

            return $membership;
        });

        $membership->user->notify(new MemberInvitation($membership));
        $this->audit->record('member.invited', $membership, ['email' => $membership->user->email, 'roles' => $roleNames], $actor);

        return $membership;
    }
}
