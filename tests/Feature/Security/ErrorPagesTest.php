<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Membership;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/* Error pages: our errors::minimal layout (html lang = app locale) and a translated 410 with a way back. */

beforeEach(function (): void {
    Route::middleware('web')->get('/_probe/gone', fn () => abort(410));
    Route::middleware('web')->get('/_probe/forbidden', fn () => abort(403));
    Route::middleware('web')->get('/_probe/broken', fn () => throw new RuntimeException('boom'));
});

it('shows a 410 in the visitor\'s language, with html lang, and no way back for a guest', function (): void {
    $this->get('/_probe/gone', ['Accept-Language' => 'de'])
        ->assertStatus(410)
        ->assertSee('<html lang="de">', false)
        ->assertSee('<title>Nicht mehr verfügbar</title>', false)
        ->assertSee('Diese Seite oder Datei ist nicht mehr verfügbar.')
        ->assertDontSee('Gone')
        ->assertDontSee('Zurück zum');

    $this->get('/_probe/gone', ['Accept-Language' => 'en'])
        ->assertSee('<html lang="en">', false)
        ->assertSee('<title>No longer available</title>', false);
});

it('links signed-in admins back to the dashboard and field workers back to the app', function (): void {
    $org = makeOrganization();
    $owner = ownerOf($org);
    $owner->forceFill(['locale' => 'de'])->save();

    $this->actingAs($owner)->get('/_probe/gone')->assertStatus(410)
        ->assertSee('<html lang="de">', false)
        ->assertSee('href="'.url('/admin').'"', false)
        ->assertSee('Zurück zum Dashboard');

    $worker = memberOf($org, SystemRole::FieldWorker);
    $worker->forceFill(['locale' => 'en'])->save();
    $this->actingAs($worker)->get('/_probe/gone')->assertStatus(410)
        ->assertSee('href="'.url('/app').'"', false)
        ->assertSee('Back to the app');
});

it('answers a used invitation with the translated 410 and a link to the dashboard', function (): void {
    $org = makeOrganization();
    $member = memberOf($org, SystemRole::Admin);
    $membership = Membership::query()->where('user_id', $member->id)->sole(); // active, no longer invited

    $this->get(URL::temporarySignedRoute('invitations.show', now()->addDay(), ['membership' => $membership->id]), ['Accept-Language' => 'en'])
        ->assertStatus(410)
        ->assertSee('<title>No longer available</title>', false)
        ->assertSee('This page or file is no longer available.')
        ->assertSee('Back to the dashboard');
});

it('keeps the stock 403, 404 and 500 pages working on our layout', function (): void {
    config(['app.debug' => false]);

    $this->get('/_probe/forbidden', ['Accept-Language' => 'de'])->assertForbidden()
        ->assertSee('<html lang="de">', false)->assertSee('403');
    // No route, so no SetLocale: a fresh request renders in the default locale (reset what the last request set).
    app()->setLocale((string) config('app.locale'));
    $this->get('/_probe/does-not-exist')->assertNotFound()
        ->assertSee('<html lang="'.config('app.locale').'">', false)->assertSee('404');
    $this->get('/_probe/broken', ['Accept-Language' => 'en'])->assertServerError()
        ->assertSee('<html lang="en">', false)->assertSee('Server Error')->assertDontSee('boom');
});
