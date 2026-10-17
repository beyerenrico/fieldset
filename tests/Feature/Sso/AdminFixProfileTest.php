<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\ExternalIdentity;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Domains\ManagedEmailAddress;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Filament\Admin\Pages\Auth\EditProfile;
use App\Filament\Admin\Pages\Auth\EmailChangeVerificationController;
use Filament\Auth\Http\Controllers\EmailChangeVerificationController as FilamentEmailChangeVerificationController;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/* Gate review M7, FS-admin item 3: no leaving an enforced domain by changing one's own email address. */

function adminFixVerificationUrl(User $user, string $newEmail): string
{
    $url = Filament::getVerifyEmailChangeUrl($user, $newEmail);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    cache()->put('filament:auth:email_change:verification:'.$query['signature'], true, now()->addHour());

    return $url;
}

beforeEach(function (): void {
    Notification::fake();
    $this->org = makeOrganization();
    $this->tenant = app(TenantContext::class);
    $this->user = memberOf($this->org, SystemRole::Designer);
    $this->user->forceFill(['email' => 'jane@acme.example', 'password' => 'password'])->save();
    $this->other = makeOrganization(name: 'Other');
});

it('decides which addresses single sign-on manages', function (): void {
    $managed = app(ManagedEmailAddress::class);
    expect($managed->isManaged($this->user))->toBeFalse(); // no verified domain, no identity

    $claim = $this->tenant->run($this->org, fn () => SsoDomain::factory()->create(['domain' => 'acme.example']));
    expect($managed->isManaged($this->user))->toBeFalse(); // claimed, not verified

    $claim->forceFill(['verified_at' => now()])->save();
    expect($managed->isManaged($this->user))->toBeTrue(); // verified by an organization the user belongs to

    $outsider = memberOf($this->other, SystemRole::Designer);
    $outsider->forceFill(['email' => 'freelancer@acme.example'])->save();
    expect($managed->isManaged($outsider))->toBeFalse(); // another organization's verified domain, not enforced
    $this->tenant->run($this->org, fn () => IdentityProvider::factory()->active()->create(['enforce_sso' => true]));
    expect($managed->isManaged($outsider))->toBeTrue(); // enforcement follows the domain into every organization

    $linked = memberOf($this->other, SystemRole::Viewer);
    $linked->forceFill(['email' => 'linked@elsewhere.example'])->save();
    $provider = $this->tenant->run($this->other, fn () => IdentityProvider::factory()->create());
    $this->tenant->run($this->other, fn () => ExternalIdentity::factory()->create(['identity_provider_id' => $provider->id, 'user_id' => $linked->id]));
    expect($managed->isManaged($linked))->toBeTrue(); // linked to an identity provider
});

it('locks the email field of the profile for a managed address and refuses a changed one', function (): void {
    $this->tenant->run($this->org, fn () => SsoDomain::factory()->verified()->create(['domain' => 'acme.example']));
    actingInAdmin($this->user, $this->org);
    $this->get(Filament::getProfileUrl())->assertOk()->assertSee(__('sso_admin.profile.email_managed')); // the panel's profile page

    Livewire::test(EditProfile::class)
        ->assertFormFieldDisabled('email')
        ->assertSee(__('sso_admin.profile.email_managed'))
        ->fillForm(['email' => 'jane@private.example', 'currentPassword' => 'password'])
        ->call('save');

    expect($this->user->fresh()->email)->toBe('jane@acme.example');
    Notification::assertNothingSent(); // no verification mail to the new address either

    $save = new ReflectionMethod(EditProfile::class, 'handleRecordUpdate'); // a forged email in the saved data
    expect(fn () => $save->invoke(new EditProfile, $this->user, ['email' => 'jane@private.example']))
        ->toThrow(ValidationException::class, __('sso_admin.profile.email_managed'));
});

it('keeps the email change open for addresses outside single sign-on', function (): void {
    $this->user->forceFill(['email' => 'jane@private.example'])->save();
    actingInAdmin($this->user, $this->org);

    Livewire::test(EditProfile::class)
        ->assertFormFieldEnabled('email')
        ->fillForm(['email' => 'jane@new.example', 'currentPassword' => 'password'])
        ->call('save')
        ->assertHasNoErrors();

    Notification::assertCount(2); // Filament's verification to the new address and the notice to the old one
});

it('refuses an email-change link once the address became managed', function (): void {
    actingInAdmin($this->user, $this->org);
    $url = adminFixVerificationUrl($this->user, 'jane@private.example');
    $this->tenant->run($this->org, fn () => SsoDomain::factory()->verified()->create(['domain' => 'acme.example']));

    $this->get($url)->assertRedirect();
    expect($this->user->fresh()->email)->toBe('jane@acme.example');

    $this->tenant->run($this->org, fn () => SsoDomain::query()->update(['verified_at' => null]));
    $this->get(adminFixVerificationUrl($this->user, 'jane@private.example'))->assertRedirect();
    expect($this->user->fresh()->email)->toBe('jane@private.example'); // the link works for unmanaged addresses
});

it('uses the guarded controller for the panel route', function (): void {
    expect(URL::isValidUrl(adminFixVerificationUrl($this->user, 'x@y.example')))->toBeTrue()
        ->and(app(FilamentEmailChangeVerificationController::class))->toBeInstanceOf(EmailChangeVerificationController::class);
});
