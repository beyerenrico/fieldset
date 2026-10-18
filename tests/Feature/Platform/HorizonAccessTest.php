<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\IssueLoginLink;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

require_once __DIR__.'/Support.php';

/*
 * Horizon is behind the operator check plus an active /platform sign-in (ADR 0068, amending ADR 0041; plan U6).
 * PLATFORM_ADMIN_EMAILS no longer opens it; neither does an /admin session or a one-time sign-in link.
 */

it('refuses an email listed in PLATFORM_ADMIN_EMAILS without an operator /platform session', function (): void {
    config(['app.platform_admins' => ['ops@example.com']]);
    $listed = User::factory()->create(['email' => 'ops@example.com']);

    expect(Gate::forUser($listed)->allows('viewHorizon'))->toBeFalse();
    $this->actingAs($listed)->get('/horizon')->assertForbidden();
});

it('refuses an operator whose session did not sign in on /platform', function (): void {
    $operator = platformOperator();

    $this->actingAs($operator)->get('/horizon')->assertForbidden();
    expect(Gate::forUser(null)->allows('viewHorizon'))->toBeFalse();
});

it('opens for an operator with a /platform session', function (): void {
    $operator = platformOperator();
    actingAsPlatformOperator($operator);

    expect(Gate::forUser($operator)->allows('viewHorizon'))->toBeTrue();
    $this->get('/horizon')->assertOk();
});

it('closes again when the /platform session expires, the grant is revoked or the account is deactivated', function (string $change): void {
    $operator = platformOperator();
    actingAsPlatformOperator($operator);
    match ($change) {
        'expired' => $this->travel(13)->hours(),
        'revoked' => $this->artisan('app:platform-admin', ['email' => $operator->email, '--revoke' => true]),
        'deactivated' => $operator->forceFill(['status' => 'deactivated'])->save(),
    };

    $this->get('/horizon')->assertForbidden();
})->with(['expired', 'revoked', 'deactivated']);

it('stays closed for an operator who signed in with a one-time sign-in link', function (): void {
    $org = makeOrganization();
    $operator = platformOperatorGrant(memberOf($org, SystemRole::Admin));
    $operator->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save(); // no MFA: signed in right away
    $token = Str::after(app(IssueLoginLink::class)->handle($operator)->url, '#');

    $this->post('/login-link', ['token' => $token])->assertRedirect(url('/admin'));

    $this->assertAuthenticatedAs($operator);
    $this->get('/horizon')->assertForbidden();
});
