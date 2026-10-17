<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Http\Middleware\Security\AdminSessionTimeout;
use Filament\Notifications\Notification;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Support\FieldAppScenario;

/*
 * ASVS 3.3.2 for the admin panel: idle (30 min) and absolute (12 h) timeouts on top of the 30-day shared
 * session that the offline Field App needs. The Field App and the API never pass through AdminSessionTimeout.
 */

const ADMIN_TIMEOUT_STATEFUL = ['Referer' => 'http://localhost', 'Origin' => 'http://localhost'];

beforeEach(function (): void {
    $this->freezeSecond();
    app()->setLocale('en');
    $this->s = FieldAppScenario::make();
    $this->dashboard = '/admin/'.$this->s->org->slug;
});

it('signs out after 30 minutes without an admin request and says why on the login page', function (): void {
    $this->actingAs($this->s->owner);
    $this->get($this->dashboard)->assertOk();

    $this->travel(29)->minutes();
    $this->get($this->dashboard)->assertOk();
    $this->travel(30)->minutes();

    $this->get($this->dashboard)->assertRedirect(url('/admin/login'));
    $this->assertGuest('web');
    expect(session()->has(AdminSessionTimeout::LAST_ACTIVITY))->toBeFalse()
        ->and(Activity::query()->where('event', 'auth.logout')->where('subject_id', $this->s->owner->id)->count())->toBe(1);
    Notification::assertNotified(__('errors.session_expired')); // shown on the login page it redirects to
    $this->get($this->dashboard)->assertRedirect(url('/admin/login'));
});

it('honours SECURITY_ADMIN_IDLE_MINUTES', function (): void {
    config(['security.admin_session.idle_minutes' => 5]);
    $this->actingAs($this->s->owner);
    $this->get($this->dashboard)->assertOk();

    $this->travel(6)->minutes();

    $this->get($this->dashboard)->assertRedirect(url('/admin/login'));
});

it('signs out 12 hours after the sign-in however active the session is', function (): void {
    $this->actingAs($this->s->owner);
    $this->get($this->dashboard)->assertOk();
    $started = session(AdminSessionTimeout::STARTED_AT);

    foreach (range(1, 28) as $ignored) {
        $this->travel(25)->minutes(); // 11 h 40 min in steps below the idle limit
        $this->get($this->dashboard)->assertOk();
    }
    expect(session(AdminSessionTimeout::STARTED_AT))->toBe($started);
    $this->travel(21)->minutes();

    $this->get($this->dashboard)->assertRedirect(url('/admin/login'));
    $this->assertGuest('web');
});

it('honours SECURITY_ADMIN_ABSOLUTE_HOURS', function (): void {
    config(['security.admin_session.absolute_hours' => 1]);
    $this->actingAs($this->s->owner)->withSession([AdminSessionTimeout::STARTED_AT => now()->subMinutes(61)->getTimestamp()]);

    $this->get($this->dashboard)->assertRedirect(url('/admin/login'));
});

it('forgets the remember-me cookie on timeout, so it cannot sign the browser back in', function (): void {
    Auth::guard('web')->login($this->s->owner, remember: true);
    $owner = $this->s->owner->fresh();
    $recaller = Auth::guard('web')->getRecallerName();
    $cookie = $owner->id.'|'.$owner->getRememberToken().'|'.$owner->getAuthPassword();
    $this->get($this->dashboard)->assertOk();

    $this->travel(31)->minutes();

    $this->withCookie($recaller, $cookie)->get($this->dashboard)->assertRedirect(url('/admin/login'))->assertCookieExpired($recaller);
    expect($this->s->owner->fresh()->getRememberToken())->not->toBe($owner->getRememberToken());

    // The old cookie no longer signs in.
    Auth::forgetGuards();
    $this->withCookie($recaller, $cookie)->get($this->dashboard)->assertRedirect(url('/admin/login'));
    $this->assertGuest('web');
});

it('counts Livewire updates of admin pages as activity', function (): void {
    expect(Livewire::getPersistentMiddleware())->toContain(AdminSessionTimeout::class);
    $this->actingAs($this->s->owner);
    $html = $this->get($this->dashboard)->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', (string) $html, $match);
    $snapshot = html_entity_decode($match[1] ?? '', ENT_QUOTES);
    expect($snapshot)->not->toBe('');

    foreach (range(1, 3) as $ignored) {
        $this->travel(20)->minutes();
        Livewire::flushState(); // a new PHP request in production; the test app keeps Livewire's per-request state
        $this->withHeaders(['X-Livewire' => '1'])->postJson(Livewire::getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'updates' => new stdClass, 'calls' => []]],
        ])->assertOk();
        expect(session(AdminSessionTimeout::LAST_ACTIVITY))->toBe(now()->getTimestamp());
    }
    $this->travel(20)->minutes(); // 80 min after the page load, 20 min after the last Livewire update

    $this->get($this->dashboard)->assertOk();
});

it('restarts both timers at every sign-in and keeps them for the session user only', function (): void {
    $this->actingAs($this->s->owner)->withSession([
        AdminSessionTimeout::STARTED_AT => now()->subHours(13)->getTimestamp(),
        AdminSessionTimeout::LAST_ACTIVITY => now()->subHour()->getTimestamp(),
    ]);

    event(new Login('web', $this->s->owner, false));

    expect(session()->has(AdminSessionTimeout::STARTED_AT))->toBeFalse()
        ->and(session()->has(AdminSessionTimeout::LAST_ACTIVITY))->toBeFalse();
    $this->get($this->dashboard)->assertOk();
    expect(session(AdminSessionTimeout::STARTED_AT))->toBe(now()->getTimestamp());
});

it('leaves the Field App session and the API alone', function (): void {
    // A Field App user whose admin timers ran out long ago (both limits exceeded).
    $this->actingAs($this->s->worker)->withSession([
        AdminSessionTimeout::STARTED_AT => now()->subDays(2)->getTimestamp(),
        AdminSessionTimeout::LAST_ACTIVITY => now()->subDay()->getTimestamp(),
    ]);

    $this->get('/app/')->assertOk();
    $this->getJson('/api/v1/me', ADMIN_TIMEOUT_STATEFUL)->assertOk()->assertJsonPath('data.user.id', $this->s->worker->id);
    $this->travel(20)->days();
    $this->get('/app/forms')->assertOk();

    expect(Auth::guard('web')->id())->toBe($this->s->worker->id);
});

it('runs only on authenticated admin panel routes', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes());
    $with = $routes->filter(fn ($route): bool => in_array(AdminSessionTimeout::class, $route->gatherMiddleware(), true));

    expect($with)->not->toBeEmpty()
        ->and($with->every(fn ($route): bool => $route->uri() === 'admin' || str_starts_with($route->uri(), 'admin/')))->toBeTrue()
        ->and($with->contains(fn ($route): bool => in_array($route->getName(), ['filament.admin.auth.login', 'filament.admin.auth.password-reset.request'], true)))->toBeFalse()
        // The admin download routes outside the panel count too.
        ->and($with->map(fn ($route): ?string => $route->getName())->intersect([
            'admin.attachments.show', 'admin.renditions.download', 'admin.exports.download', 'privacy.exports.download',
        ])->count())->toBe(4);
    $fieldApp = $routes->filter(fn ($route): bool => preg_match('#^(app|auth|api)(/|$)#', $route->uri()) === 1);
    expect($fieldApp)->not->toBeEmpty()
        ->and($fieldApp->contains(fn ($route): bool => in_array(AdminSessionTimeout::class, $route->gatherMiddleware(), true)))->toBeFalse();
});
