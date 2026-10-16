<?php

declare(strict_types=1);

namespace App\Domain\Identity\Devices;

use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Answers `POST /auth/device-status` (§9.3) from the device secret, independent of the session:
 * `wipe` when the device was revoked, a wipe was requested or the user is deactivated; `ok` when the
 * session belongs to the device's user; otherwise `reauth`. Unknown or wrong credentials → null; an
 * unknown device uuid still costs one hash comparison (no timing oracle for registered uuids).
 */
final class DeviceStatus
{
    public const string OK = 'ok';

    public const string REAUTH = 'reauth';

    public const string WIPE = 'wipe';

    /** Bcrypt hash of a random value, compared on a miss so unknown devices cost as much as known ones. */
    private static ?string $dummyHash = null;

    public function resolve(string $deviceUuid, string $secret, ?User $sessionUser): ?string
    {
        $candidates = Device::query()->with('user')->where('device_uuid', strtolower($deviceUuid))->get();
        if ($candidates->isEmpty()) {
            Hash::check($secret, self::$dummyHash ??= Hash::make(Str::random(40)));

            return null;
        }

        $device = $candidates->first(fn (Device $d): bool => Hash::check($secret, $d->secret_hash));
        if ($device === null) {
            return null;
        }

        $device->forceFill(['last_seen_at' => now()])->save();

        if ($device->mustWipe()) {
            return self::WIPE;
        }

        return $sessionUser !== null && $sessionUser->is($device->user) ? self::OK : self::REAUTH;
    }
}
