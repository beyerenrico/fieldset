<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deactivating a user account (platform level, §9.3) revokes all sessions and API tokens and asks every
 * device to wipe its local data at the next contact.
 */
final readonly class DeactivateUser
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(User $user, ?User $actor): void
    {
        DB::transaction(function () use ($user): void {
            $user->forceFill(['status' => UserStatus::Deactivated, 'deactivated_at' => now(), 'remember_token' => null])->save();
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            Device::query()->where('user_id', $user->id)->whereNull('wipe_requested_at')->update(['wipe_requested_at' => now()]);
        });

        $this->audit->record('user.deactivated', $user, [], $actor);
    }
}
