<?php

declare(strict_types=1);

use App\Domain\Identity\Devices\DeviceStatus;
use App\Domain\Identity\Models\Device;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\FieldAppScenario;

beforeEach(function (): void {
    $this->s = FieldAppScenario::make();
});

it('spends a hash check on unknown devices too (no timing oracle)', function (): void {
    Hash::shouldReceive('make')->zeroOrMoreTimes()->andReturn(password_hash('dummy', PASSWORD_BCRYPT, ['cost' => 4]));
    Hash::shouldReceive('check')->once()->andReturnFalse();

    expect(app(DeviceStatus::class)->resolve((string) Str::uuid(), 'some-secret', null))->toBeNull();
});

it('rotates the device secret on re-registration and refuses the old one', function (): void {
    $deviceUuid = (string) Str::uuid();
    $register = fn () => $this->withHeaders(apiHeaders($this->s->worker, $this->s->org))
        ->postJson('/api/v1/devices', ['device_uuid' => $deviceUuid, 'name' => 'Pixel'])->assertCreated()->json('data.secret');
    $old = $register();
    $new = $register();
    $status = fn (string $secret) => $this->withHeaders(['X-Device-Id' => $deviceUuid, 'X-Device-Secret' => $secret])
        ->postJson('/api/v1/auth/device-status');

    expect($new)->not->toBe($old)->and(Device::query()->where('device_uuid', $deviceUuid)->count())->toBe(1);
    $status($old)->assertUnauthorized()->assertJsonPath('error.code', 'device_unknown');
    $status($new)->assertOk()->assertJsonPath('data.status', 'ok');
});
