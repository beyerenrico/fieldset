<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\PlatformAdmin;
use App\Domain\Platform\PlatformAdmins;

it('seeds no platform admin', function (): void {
    expect(PlatformAdmin::query()->count())->toBe(0);
});

it('grants the platform role once, audited', function (): void {
    $user = User::factory()->create(['email' => 'ops@example.com']);

    $this->artisan('app:platform-admin', ['email' => 'OPS@example.com'])->assertSuccessful()
        ->expectsOutputToContain('granted');
    $this->artisan('app:platform-admin', ['email' => 'ops@example.com'])->assertSuccessful()
        ->expectsOutputToContain('already');

    expect(app(PlatformAdmins::class)->check($user))->toBeTrue()
        ->and(Activity::query()->where('event', 'platform.admin_granted')->where('subject_id', $user->id)->count())->toBe(1);
});

it('revokes the platform role, audited', function (): void {
    $user = User::factory()->create(['email' => 'ops@example.com']);
    $this->artisan('app:platform-admin', ['email' => 'ops@example.com'])->assertSuccessful();

    $this->artisan('app:platform-admin', ['email' => 'ops@example.com', '--revoke' => true])->assertSuccessful()
        ->expectsOutputToContain('revoked');

    expect(app(PlatformAdmins::class)->check($user))->toBeFalse()
        ->and(Activity::query()->where('event', 'platform.admin_revoked')->where('subject_id', $user->id)->count())->toBe(1);
});

it('fails for unknown or deactivated users', function (): void {
    $this->artisan('app:platform-admin', ['email' => 'nobody@example.com'])->assertFailed();

    $user = User::factory()->deactivated()->create(['email' => 'gone@example.com']);
    $this->artisan('app:platform-admin', ['email' => 'gone@example.com'])->assertFailed();

    expect(app(PlatformAdmins::class)->granted($user))->toBeFalse();
});
