<?php

declare(strict_types=1);

use App\Domain\Identity\Tenancy\TenantContext;
use App\Filament\Admin\Pages\Privacy\MyData;
use Filament\Facades\Filament;
use Filament\Livewire\Topbar;
use Livewire\Livewire;
use Tests\Support\FieldAppScenario;

/*
 * The panel's own profile page (/admin/profile, user menu "Profile") has no organization in its URL, but its full
 * layout needs one: the topbar's organization menu and the user menu's "My data" link. It takes the user's default
 * organization. These tests sign in without actingInAdmin(), whose Filament::setTenant() in the test process would
 * hide a missing tenant from the request.
 */

beforeEach(function (): void {
    app()->setLocale('en');
    $this->s = FieldAppScenario::make();
});

/** A new PHP request in production: no tenant left over from the previous request of the test. */
function adminProfileFreshRequest(): void
{
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
    Livewire::flushState();
}

it('opens the profile page from a fresh request with the default organization in the layout', function (): void {
    $this->actingAs($this->s->owner);

    $this->get('/admin/profile')
        ->assertOk()
        ->assertSee($this->s->org->name)
        ->assertSee(MyData::getUrl(panel: 'admin', tenant: $this->s->org), false);
});

it('keeps the organization for Livewire requests of the page', function (): void {
    $this->actingAs($this->s->owner);
    $html = (string) $this->get('/admin/profile')->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
    $topbar = collect($matches[1])->map(fn (string $s): string => html_entity_decode($s, ENT_QUOTES))
        ->first(fn (string $s): bool => (json_decode($s, true)['memo']['name'] ?? null) === Topbar::class);
    expect($topbar)->not->toBeNull();

    adminProfileFreshRequest();
    $this->withHeaders(['X-Livewire' => '1'])->postJson(Livewire::getUpdateUri(), [
        'components' => [['snapshot' => $topbar, 'updates' => new stdClass, 'calls' => []]],
    ])->assertOk();

    expect(Filament::getTenant()?->getKey())->toBe($this->s->org->getKey());
});

it('keeps the organization of the URL on organization pages', function (): void {
    $other = makeOrganization($this->s->owner, 'Zweite GmbH'); // a second organization of the same owner
    $this->actingAs($this->s->owner);

    $this->get('/admin/'.$other->slug)->assertOk();

    expect(Filament::getTenant()?->getKey())->toBe($other->getKey())
        ->and(app(TenantContext::class)->id())->toBe($other->getKey());
});
