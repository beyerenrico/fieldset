<?php

declare(strict_types=1);

use App\Domain\Identity\Auth\SharedAppAuthentication;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Auth\PlatformSession;
use App\Filament\Platform\Pages\Auth\Login;
use Filament\Facades\Filament;
use Livewire\Livewire;

require_once __DIR__.'/Support.php';

it('sends guests to the platform login', function (): void {
    $this->get('/platform/organizations')->assertRedirect('/platform/login');
    $this->get('/platform/login')->assertOk();
});

it('refuses users without a platform grant, including organization owners', function (): void {
    $org = makeOrganization();

    $this->actingAs(ownerOf($org))->get('/platform/organizations')->assertForbidden();
    $this->actingAs(memberOf($org, SystemRole::Admin))->get('/platform/health')->assertForbidden();
});

it('refuses a deactivated platform admin', function (): void {
    $operator = platformOperator();
    $operator->forceFill(['status' => 'deactivated'])->save();
    actingAsPlatformOperator($operator);

    $this->get('/platform/organizations')->assertForbidden();
});

it('lets a platform admin with a platform sign-in open the panel', function (): void {
    actingAsPlatformOperator(platformOperator());

    $this->get('/platform')->assertRedirect();
    $this->get('/platform/organizations')->assertOk();
    $this->get('/platform/health')->assertOk();
});

it('asks a platform admin signed in elsewhere (e.g. organization SSO on /admin) to sign in on /platform', function (): void {
    $this->actingAs(platformOperator());

    $this->get('/platform/organizations')->assertRedirect('/platform/login');
    // The login page shows its form instead of bouncing back to the panel.
    $this->get('/platform/login')->assertOk();
});

it('expires the platform sign-in after platform.session_hours', function (): void {
    actingAsPlatformOperator(platformOperator());
    $this->get('/platform/organizations')->assertOk();

    $this->travel(13)->hours();

    $this->get('/platform/organizations')->assertRedirect('/platform/login');
});

it('requires MFA to be set up before the panel opens', function (): void {
    $operator = platformOperator();
    SharedAppAuthentication::make()->saveSecret($operator, null);
    actingAsPlatformOperator($operator->fresh());

    $this->get('/platform/organizations')->assertRedirect('/platform/multi-factor-authentication/set-up');
});

it('marks the session on a successful platform login and refuses non-operators there', function (): void {
    Filament::setCurrentPanel('platform');
    $operator = platformOperator();
    SharedAppAuthentication::make()->saveSecret($operator, null); // no challenge step in this test
    $operator->forceFill(['password' => 'correct-horse-battery'])->save();

    Livewire::test(Login::class)
        ->fillForm(['email' => $operator->email, 'password' => 'correct-horse-battery'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth()->id())->toBe($operator->id)
        ->and(app(PlatformSession::class)->isValid($operator))->toBeTrue();

    auth()->logout();
    $member = User::factory()->create(['password' => 'correct-horse-battery']);
    Livewire::test(Login::class)
        ->fillForm(['email' => $member->email, 'password' => 'correct-horse-battery'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);
    expect(auth()->check())->toBeFalse();
});

it('does not open the admin panel for an operator without an organization', function (): void {
    config(['app.allow_registration' => false]);
    actingAsPlatformOperator(platformOperator());

    $this->get('/admin')->assertForbidden();
});
