<?php

declare(strict_types=1);

namespace App\Domain\Identity\Devices;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Registers a Field App install (§9.3): returns a random secret once; only its hash is stored.
 * Re-registering the same device uuid rotates the secret and lifts nothing that an admin revoked.
 */
final readonly class RegisterDevice
{
    public function __construct(private AuditLogger $audit) {}

    /** @return array{0: Device, 1: string} device and plain secret */
    public function handle(User $user, string $deviceUuid, ?string $name, ?string $platform): array
    {
        $secret = Str::random(64);
        $device = Device::query()->firstOrNew(['user_id' => $user->id, 'device_uuid' => strtolower($deviceUuid)]);
        $isNew = ! $device->exists;
        $device->forceFill([
            'secret_hash' => Hash::make($secret),
            'name' => $name !== null ? mb_substr($name, 0, 120) : $device->name,
            'platform' => $platform !== null ? mb_substr($platform, 0, 60) : $device->platform,
            'last_seen_at' => now(),
        ])->save();

        if ($isNew) {
            $this->audit->record('device.registered', $device, ['name' => $device->name, 'platform' => $device->platform], $user);
        }

        return [$device, $secret];
    }
}
