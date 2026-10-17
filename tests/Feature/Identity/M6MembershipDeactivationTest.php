<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\AddMember;
use App\Domain\Identity\Actions\SetMembershipStatus;
use App\Domain\Identity\Devices\RevokeDevice;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * M6 (ADR 0035 "Backend", §9.3): deactivating a membership ends the member's sessions; when no other
 * active membership remains, the member's devices are asked to wipe at their next contact.
 */

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->owner = ownerOf($this->org);
    $this->member = memberOf($this->org, SystemRole::FieldWorker);
});

function m6Deactivate(User $user, Organization $organization, User $actor): void
{
    $membership = Membership::query()->where('user_id', $user->id)->where('organization_id', $organization->id)->sole();
    app(SetMembershipStatus::class)->handle($membership, MembershipStatus::Deactivated, $actor);
}

function m6Session(User $user, string $id): void
{
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
}

/** @return array{0: Device, 1: string} */
function m6Device(User $user): array
{
    $secret = Str::random(64);
    $device = Device::query()->create([
        'user_id' => $user->id, 'device_uuid' => (string) Str::uuid(), 'secret_hash' => Hash::make($secret, ['rounds' => 4]),
    ]);

    return [$device, $secret];
}

function m6DeviceStatus(Device $device, string $secret): TestResponse
{
    auth()->forgetGuards();

    return test()->withHeaders(['X-Device-Id' => $device->device_uuid, 'X-Device-Secret' => $secret])->postJson('/api/v1/auth/device-status');
}

it('ends every session of the member and forgets the remember-me token', function (): void {
    m6Session($this->member, 'member-session-1');
    m6Session($this->member, 'member-session-2');
    m6Session($this->owner, 'owner-session');
    $this->member->forceFill(['remember_token' => Str::random(60)])->save();

    m6Deactivate($this->member, $this->org, $this->owner);

    expect(DB::table('sessions')->where('user_id', $this->member->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $this->owner->id)->count())->toBe(1)
        ->and($this->member->refresh()->remember_token)->toBeNull();
});

it('asks the devices to wipe when no other active membership remains', function (): void {
    [$device, $secret] = m6Device($this->member);
    m6DeviceStatus($device, $secret)->assertOk()->assertJsonPath('data.status', 'reauth');

    m6Deactivate($this->member, $this->org, $this->owner);

    expect($device->refresh()->wipe_requested_at)->not->toBeNull();
    m6DeviceStatus($device, $secret)->assertOk()->assertJsonPath('data.status', 'wipe');
});

it('keeps the devices when the member is still active in another organization', function (): void {
    $other = makeOrganization(name: 'Other GmbH');
    app(AddMember::class)->handle($other, $this->member, [SystemRole::FieldWorker]);
    [$device, $secret] = m6Device($this->member);
    m6Session($this->member, 'member-session');

    m6Deactivate($this->member, $this->org, $this->owner);

    expect($device->refresh()->wipe_requested_at)->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $this->member->id)->count())->toBe(0);
    m6DeviceStatus($device, $secret)->assertOk()->assertJsonPath('data.status', 'reauth');
});

it('requests the wipe when the last active membership goes, even if an older one was deactivated first', function (): void {
    $other = makeOrganization(name: 'Other GmbH');
    app(AddMember::class)->handle($other, $this->member, [SystemRole::FieldWorker]);
    [$device] = m6Device($this->member);

    m6Deactivate($this->member, $other, ownerOf($other));
    expect($device->refresh()->wipe_requested_at)->toBeNull();

    m6Deactivate($this->member, $this->org, $this->owner);
    expect($device->refresh()->wipe_requested_at)->not->toBeNull();
});

it('does not touch devices or sessions when a membership is reactivated', function (): void {
    m6Deactivate($this->member, $this->org, $this->owner);
    [$device] = m6Device($this->member);
    m6Session($this->member, 'fresh-session');
    $membership = Membership::query()->where('user_id', $this->member->id)->sole();

    app(SetMembershipStatus::class)->handle($membership, MembershipStatus::Active, $this->owner);

    expect($device->refresh()->wipe_requested_at)->toBeNull()
        ->and(DB::table('sessions')->where('id', 'fresh-session')->exists())->toBeTrue();
});

it('answers organization_forbidden for a deactivated membership that still holds a valid session', function (): void {
    m6Deactivate($this->member, $this->org, $this->owner);

    $this->withHeaders(apiHeaders($this->member, $this->org))->getJson('/api/v1/forms')
        ->assertForbidden()->assertJsonPath('error.code', 'organization_forbidden');
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.organizations', []);
});

it('ends every session and the remember-me token when an admin revokes a device (M6 review)', function (): void {
    // A session re-created from the recaller cookie is not bound to the device, so only ending all
    // sessions makes the revoked device reach device-status (and wipe) at its next request.
    [$device] = m6Device($this->member);
    m6Session($this->member, 'phone-session');
    $this->member->forceFill(['remember_token' => 'recaller'])->save();

    app(RevokeDevice::class)->handle($device, $this->owner);

    expect(DB::table('sessions')->where('user_id', $this->member->id)->count())->toBe(0)
        ->and($this->member->fresh()->remember_token)->toBeNull()
        ->and($device->fresh()->wipe_requested_at)->not->toBeNull();
});
