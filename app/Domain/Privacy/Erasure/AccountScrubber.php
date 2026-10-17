<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Erasure;

use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\ExternalIdentity;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\OrganizationPermissions;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Privacy\Models\DataExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Replaces and removes an account's personal data for EraseUser (call inside a transaction). The attribute
 * changes are not written to the audit log (their old values are the personal data being erased).
 */
final readonly class AccountScrubber
{
    public const string ERASED_NAME = 'Deleted user';

    public function __construct(private TenantContext $tenant) {}

    public function scrub(User $user): void
    {
        $oldEmail = mb_strtolower($user->email);
        activity()->withoutLogging(fn () => $user->forceFill([
            'name' => self::ERASED_NAME, 'email' => 'erased-'.$user->id.'@erased.invalid', 'password' => Str::password(64),
            'remember_token' => null, 'email_verified_at' => null, 'locale' => null, 'timezone' => null,
            'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null,
            'status' => UserStatus::Deactivated, 'deactivated_at' => $user->deactivated_at ?? now(), 'erased_at' => now(),
        ])->save());

        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();
        Device::query()->where('user_id', $user->id)->update([
            'name' => null, 'platform' => null,
            'revoked_at' => DB::raw('COALESCE(revoked_at, now())'), 'wipe_requested_at' => DB::raw('COALESCE(wipe_requested_at, now())'),
        ]);
        $this->tenant->withoutTenant(fn () => ExternalIdentity::query()->withoutTenant()->where('user_id', $user->id)->delete());
        $this->removeAccess($user);
        DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->id)->delete();
        NotificationPreference::query()->where('user_id', $user->id)->delete();
        // Mail sent to the account (invitations, notifications) and pending password resets carry the address.
        DB::table('password_reset_tokens')->whereRaw('lower(email) = ?', [$oldEmail])->delete();
        DB::table('mail_messages')->whereRaw('lower(recipient_email) = ?', [$oldEmail])->delete();
        $this->deleteExports($user);
    }

    private function removeAccess(User $user): void
    {
        Membership::query()->where('user_id', $user->id)->update(['status' => MembershipStatus::Deactivated->value, 'default_group_id' => null]);
        DB::table('model_has_roles')->where('model_type', $user->getMorphClass())->where('model_id', $user->id)->delete();
        DB::table('model_has_permissions')->where('model_type', $user->getMorphClass())->where('model_id', $user->id)->delete();
        DB::table('group_user')->where('user_id', $user->id)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(OrganizationPermissions::class)->forget();
    }

    private function deleteExports(User $user): void
    {
        $exports = $this->tenant->withoutTenant(fn () => DataExport::query()->withoutTenant()
            ->where(fn ($q) => $q->where('requested_by', $user->id)->orWhere('subject_user_id', $user->id))->get());
        foreach ($exports as $export) {
            DB::afterCommit(fn () => $export->deleteFile());
            DataExport::query()->withoutTenant()->whereKey($export->id)->delete();
        }
    }
}
