<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sessions;

use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ends a user's signed-in state (§9.3, ADR 0035 "Backend"), shared by account and membership
 * deactivation:
 * - endSessions(): deletes the database sessions and forgets the remember-me token. The Field App
 *   signs in with `remember: true`, so without the token reset the recaller cookie would sign a
 *   deleted session straight back in.
 * - requestDeviceWipe(): every device of the user answers `wipe` at its next device-status call (and
 *   EndRevokedDeviceSession ends any session still bound to it). Already requested wipes keep their time.
 */
final class EndUserAccess
{
    public static function endSessions(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->forceFill(['remember_token' => null])->save();
    }

    public static function requestDeviceWipe(User $user): void
    {
        Device::query()->where('user_id', $user->id)->whereNull('wipe_requested_at')->update(['wipe_requested_at' => now()]);
    }
}
