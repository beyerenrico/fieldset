<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;

it('logs in through the headless Fortify endpoint and reaches the API with the session', function (): void {
    $org = makeOrganization();
    $user = ownerOf($org);
    $user->forceFill(['password' => 'field-pass-123'])->save();
    $spa = ['Referer' => 'http://localhost', 'Origin' => 'http://localhost'];

    $this->withHeaders($spa)->postJson('/auth/login', ['email' => $user->email, 'password' => 'field-pass-123'])
        ->assertOk()->assertJson(['two_factor' => false]);

    $this->withHeaders($spa)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.user.email', $user->email);
});

it('rejects deactivated users at login', function (): void {
    $user = User::factory()->deactivated()->create(['password' => 'field-pass-123']);

    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'field-pass-123'])->assertUnprocessable();
    $this->assertGuest();
});

it('rate-limits login attempts', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 5) as $_) {
        $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
    }

    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertTooManyRequests();
});

it('uses a long rolling session lifetime for field work', function (): void {
    expect((int) config('session.lifetime'))->toBeGreaterThanOrEqual(60 * 24 * 7)
        ->and(config('session.expire_on_close'))->toBeFalse();
});
