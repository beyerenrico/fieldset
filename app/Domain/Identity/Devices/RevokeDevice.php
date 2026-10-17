<?php

declare(strict_types=1);

namespace App\Domain\Identity\Devices;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sessions\EndUserAccess;

/**
 * Revokes a device and requests a remote wipe at its next contact (§9.3). It also ends the user's sessions
 * and remember-me token: a session re-created from the recaller cookie is not bound to the device, so
 * EndRevokedDeviceSession alone would never send the revoked device to device-status (M6 review).
 */
final readonly class RevokeDevice
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Device $device, ?User $actor): void
    {
        $device->forceFill(['revoked_at' => $device->revoked_at ?? now(), 'wipe_requested_at' => $device->wipe_requested_at ?? now()])->save();
        $user = User::query()->find($device->user_id);
        if ($user instanceof User) {
            EndUserAccess::endSessions($user);
        }
        $this->audit->record('device.revoked', $device, ['user_id' => $device->user_id], $actor);
    }
}
