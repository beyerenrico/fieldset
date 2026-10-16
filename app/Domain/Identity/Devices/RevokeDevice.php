<?php

declare(strict_types=1);

namespace App\Domain\Identity\Devices;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\User;

/** Revokes a device and requests a remote wipe at its next contact (§9.3). */
final readonly class RevokeDevice
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Device $device, ?User $actor): void
    {
        $device->forceFill(['revoked_at' => $device->revoked_at ?? now(), 'wipe_requested_at' => $device->wipe_requested_at ?? now()])->save();
        $this->audit->record('device.revoked', $device, ['user_id' => $device->user_id], $actor);
    }
}
