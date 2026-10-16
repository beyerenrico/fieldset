<?php

declare(strict_types=1);

namespace App\Domain\Identity\Devices;

use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Answers `POST /auth/device-status` (§9.3) from the device secret, independent of the session:
 * `wipe` when the device was revoked, a wipe was requested or the user is deactivated; `ok` when the
 * session belongs to the device's user; otherwise `reauth`. Unknown or wrong credentials → null.
 */
final class DeviceStatus
{
    public const string OK = 'ok';

    public const string REAUTH = 'reauth';

    public const string WIPE = 'wipe';

    public function resolve(string $deviceUuid, string $secret, ?User $sessionUser): ?string
    {
        $device = Device::query()->with('user')->where('device_uuid', strtolower($deviceUuid))->get()
            ->first(fn (Device $d): bool => Hash::check($secret, $d->secret_hash));

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
