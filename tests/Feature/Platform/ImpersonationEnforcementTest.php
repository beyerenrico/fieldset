<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Documents\Models\Rendition;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Platform\Actions\RevokePlatformAdmin;
use App\Domain\Platform\Impersonation\ImpersonationStore;
use App\Http\Middleware\Security\AdminSessionTimeout;
use Tests\Feature\Documents\DocumentFixtures;
use Tests\Support\FieldAppScenario;

require_once __DIR__.'/Support.php';

/*
 * ADR 0041 enforcement on every request: an operator who loses the platform grant (or is deactivated) ends
 * the impersonation at once; the signed PDF download stays usable while impersonating.
 */

/** Sanctum treats requests from the SPA's own origin as stateful: the session (and the impersonation) applies. */
const IMPERSONATION_STATEFUL = ['Referer' => 'http://localhost', 'Origin' => 'http://localhost'];

dataset('operator losses', [
    'grant revoked' => [fn (User $operator) => app(RevokePlatformAdmin::class)->handle($operator)],
    'deactivated' => [fn (User $operator) => $operator->forceFill(['status' => UserStatus::Deactivated, 'deactivated_at' => now()])->save()],
    'deleted' => [fn (User $operator) => $operator->forceDelete()],
]);

function impersonationEndedBy(): ?string
{
    $entry = Activity::query()->where('event', 'platform.impersonation_ended')->sole();

    return $entry->properties['ended_by'] ?? null;
}

it('ends the impersonation on the next panel request once the operator loses access', function (Closure $lose): void {
    [$operator, $target, $org] = platformImpersonationScenario();
    platformStartImpersonation($operator, $target, $org);
    $this->get('/admin/'.$org->slug)->assertOk();

    $lose($operator);

    $this->get('/admin/'.$org->slug)->assertRedirect(url('/admin/login'));
    expect(auth()->check())->toBeFalse()
        ->and(app(ImpersonationStore::class)->active())->toBeFalse()
        ->and(impersonationEndedBy())->toBe('operator_revoked');

    // Signed out for good: neither the target's panel nor /platform opens any more.
    $this->get('/admin/'.$org->slug)->assertRedirect(url('/admin/login'));
    $this->get('/platform/organizations')->assertRedirect(url('/platform/login'));
})->with('operator losses');

it('ends the impersonation on API requests once the operator is revoked', function (): void {
    [$operator, $target, $org] = platformImpersonationScenario();
    platformStartImpersonation($operator, $target, $org);
    app(RevokePlatformAdmin::class)->handle($operator);

    $this->getJson('/api/v1/me', IMPERSONATION_STATEFUL)
        ->assertUnauthorized()->assertJsonPath('error.code', 'impersonation_ended');

    // The web guard (the session), not the sanctum guard that cached the request's user (LEARNINGS #61).
    expect(auth()->guard('web')->check())->toBeFalse()->and(impersonationEndedBy())->toBe('operator_revoked');
});

it('ends a revoked operator\'s impersonation on the web group too', function (): void {
    [$operator, $target, $org] = platformImpersonationScenario();
    platformStartImpersonation($operator, $target, $org);
    app(RevokePlatformAdmin::class)->handle($operator);

    $this->get('/app/')->assertRedirect(url('/admin/login'));

    expect(auth()->check())->toBeFalse()->and(impersonationEndedBy())->toBe('operator_revoked');
});

it('never restores a revoked operator through the exit button', function (): void {
    [$operator, $target, $org] = platformImpersonationScenario();
    platformStartImpersonation($operator, $target, $org);
    app(RevokePlatformAdmin::class)->handle($operator);

    $this->post(route('filament.platform.impersonation.exit'));

    expect(auth()->check())->toBeFalse()->and(Activity::query()->where('event', 'platform.impersonation_ended')->count())->toBe(1);
});

it('lets the admin "Download" of a PDF through while impersonating (signed download)', function (): void {
    DocumentFixtures::fakeDisk();
    DocumentFixtures::fakeGotenberg();
    $s = FieldAppScenario::make();
    $submission = DocumentFixtures::submit($s, DocumentFixtures::draft($s));
    $rendition = app(TenantContext::class)->run($s->org, fn () => Rendition::query()->where('submission_uuid', $submission->uuid)->sole());
    $operator = platformOperator();
    actingAsPlatformOperator($operator);
    platformStartImpersonation($operator, $s->owner, $s->org);

    $signed = $this->get(route('admin.renditions.download', ['organization' => $s->org->slug, 'rendition' => $rendition->uuid]))
        ->assertRedirect()->headers->get('Location');
    expect($signed)->toContain('/api/v1/documents/'.$rendition->uuid);

    $this->get((string) $signed, IMPERSONATION_STATEFUL)->assertOk()->assertHeader('Content-Type', 'application/pdf');

    // Still impersonating, and the rest of the API stays closed.
    expect(app(ImpersonationStore::class)->active())->toBeTrue();
    $this->getJson('/api/v1/me', IMPERSONATION_STATEFUL)->assertForbidden()->assertJsonPath('error.code', 'impersonation_api_blocked');
    // A tampered signature is no way around the API block.
    $this->getJson($signed.'0', IMPERSONATION_STATEFUL)->assertForbidden()->assertJsonPath('error.code', 'impersonation_api_blocked');
});

it('starts the admin session timers afresh for the impersonated target', function (): void {
    $this->freezeSecond();
    [$operator, $target, $org] = platformImpersonationScenario();
    // The operator last used /admin an hour ago (beyond the idle limit), then worked on /platform.
    session([AdminSessionTimeout::STARTED_AT => now()->subHours(2)->getTimestamp(), AdminSessionTimeout::LAST_ACTIVITY => now()->subHour()->getTimestamp()]);

    platformStartImpersonation($operator, $target, $org);

    $this->get('/admin/'.$org->slug)->assertOk();
    expect(auth()->id())->toBe($target->id)
        ->and(session(AdminSessionTimeout::STARTED_AT))->toBe(now()->getTimestamp());
});
