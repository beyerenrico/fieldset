<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\GrantPlatformAdmin;
use App\Domain\Platform\Impersonation\ImpersonationRefused;
use App\Domain\Platform\Impersonation\ImpersonationStore;
use App\Domain\Platform\Impersonation\StartImpersonation;
use App\Filament\Platform\Resources\Organizations\Pages\OrganizationMembers;
use Livewire\Livewire;

require_once __DIR__.'/Support.php';

const IMPERSONATION_REASON = 'Ticket 4711: the workflow does not start';

function platformMembershipOf(User $user, Organization $organization): Membership
{
    return Membership::query()->where('user_id', $user->id)->where('organization_id', $organization->id)->sole();
}

/** @return array{0: User, 1: User, 2: Organization} operator, target, organization */
function platformImpersonationScenario(): array
{
    $org = makeOrganization(name: 'Acme GmbH');
    $target = memberOf($org, SystemRole::Admin);
    $operator = platformOperator();
    actingAsPlatformOperator($operator);

    return [$operator, $target, $org];
}

function platformStartImpersonation(User $operator, User $target, Organization $org): void
{
    app(StartImpersonation::class)->handle($operator, $org, $target, IMPERSONATION_REASON);
}

it('starts from the members page: switches the session to the target and audits it in the organization', function (): void {
    [$operator, $target, $org] = platformImpersonationScenario();

    Livewire::test(OrganizationMembers::class, ['record' => $org->id])
        ->callTableAction('impersonate', platformMembershipOf($target, $org), ['reason' => IMPERSONATION_REASON])
        ->assertHasNoTableActionErrors()
        ->assertRedirect(url('/admin/'.$org->slug));

    expect(auth()->id())->toBe($target->id)
        ->and(app(ImpersonationStore::class)->current()?->operatorId)->toBe($operator->id);

    $entry = Activity::query()->where('event', 'platform.impersonation_started')->sole();
    expect((int) $entry->organization_id)->toBe($org->id)
        ->and($entry->causer_id)->toBe($operator->id)
        ->and($entry->subject_id)->toBe((string) $target->id)
        ->and($entry->properties['reason'])->toBe(IMPERSONATION_REASON)
        ->and($entry->properties['operator_email'])->toBe($operator->email)
        ->and($entry->properties['target_email'])->toBe($target->email)
        ->and($entry->properties['expires_at'])->toBe(now()->addMinutes(30)->toIso8601String());
    // Not a sign-in of the target.
    expect(Activity::query()->where('event', 'auth.login')->where('subject_id', $target->id)->count())->toBe(0);

    $this->get('/admin/'.$org->slug)->assertOk()
        ->assertSee('data-platform-impersonation-banner', false)
        ->assertSee($target->email)->assertSee($operator->email)
        ->assertSee(route('filament.platform.impersonation.exit'), false);
});

it('requires a reason of 10 to 500 characters', function (): void {
    [, $target, $org] = platformImpersonationScenario();

    Livewire::test(OrganizationMembers::class, ['record' => $org->id])
        ->callTableAction('impersonate', platformMembershipOf($target, $org), ['reason' => ''])
        ->assertHasTableActionErrors(['reason' => 'required']);
    Livewire::test(OrganizationMembers::class, ['record' => $org->id])
        ->callTableAction('impersonate', platformMembershipOf($target, $org), ['reason' => 'too short'])
        ->assertHasTableActionErrors(['reason']);

    expect(auth()->id())->not->toBe($target->id)
        ->and(Activity::query()->where('event', 'platform.impersonation_started')->count())->toBe(0);
});

it('never impersonates another platform admin, a non-member or a member without admin access', function (): void {
    [$operator, , $org] = platformImpersonationScenario();
    $otherOperator = memberOf($org, SystemRole::Admin);
    app(GrantPlatformAdmin::class)->handle($otherOperator);
    $worker = memberOf($org, SystemRole::FieldWorker);
    $stranger = memberOf(makeOrganization(name: 'Other AG'), SystemRole::Admin);

    // Hidden in the UI …
    Livewire::test(OrganizationMembers::class, ['record' => $org->id])
        ->assertTableActionHidden('impersonate', platformMembershipOf($otherOperator, $org))
        ->assertTableActionHidden('impersonate', platformMembershipOf($worker, $org));

    // … and refused on the server.
    $refusal = function (User $target, ?string $reason = null) use ($operator, $org): string {
        try {
            app(StartImpersonation::class)->handle($operator, $org, $target, $reason ?? IMPERSONATION_REASON);
        } catch (ImpersonationRefused $refused) {
            return $refused->reasonCode;
        }

        return 'started';
    };
    expect($refusal($otherOperator))->toBe('target_is_operator')
        ->and($refusal($worker))->toBe('target_not_admin_member')
        ->and($refusal($stranger))->toBe('target_not_admin_member')
        ->and($refusal(memberOf($org, SystemRole::Admin), '   short   '))->toBe('reason_invalid')
        ->and(auth()->id())->toBe($operator->id);
});

it('refuses non-operators and nested impersonations', function (): void {
    [$operator, $target, $org] = platformImpersonationScenario();
    $owner = ownerOf($org);

    expect(fn () => app(StartImpersonation::class)->handle($owner, $org, $target, IMPERSONATION_REASON))
        ->toThrow(ImpersonationRefused::class, __('platform.impersonation.refused.not_operator'));

    platformStartImpersonation($operator, $target, $org);
    expect(fn () => app(StartImpersonation::class)->handle($operator, $org, memberOf($org, SystemRole::Admin), IMPERSONATION_REASON))
        ->toThrow(ImpersonationRefused::class, __('platform.impersonation.refused.already_impersonating'));
});

it('exits: restores the operator and audits ended_by=operator', function (): void {
    [$operator, $target, $org] = platformImpersonationScenario();
    platformStartImpersonation($operator, $target, $org);

    $this->post(route('filament.platform.impersonation.exit'))->assertRedirect(url('/platform'));

    expect(auth()->id())->toBe($operator->id)
        ->and(app(ImpersonationStore::class)->active())->toBeFalse();
    $entry = Activity::query()->where('event', 'platform.impersonation_ended')->sole();
    expect($entry->properties['ended_by'])->toBe('operator')
        ->and($entry->properties['reason'])->toBe(IMPERSONATION_REASON)
        ->and((int) $entry->organization_id)->toBe($org->id)
        ->and($entry->causer_id)->toBe($operator->id);

    $this->get('/platform/organizations')->assertOk();
    $this->get('/admin/'.$org->slug)->assertDontSee('data-platform-impersonation-banner', false);
});

it('ends at the time limit on the next request (ended_by=expired)', function (): void {
    [$operator, $target, $org] = platformImpersonationScenario();
    platformStartImpersonation($operator, $target, $org);
    $this->get('/admin/'.$org->slug)->assertOk();

    $this->travel(31)->minutes();

    $this->get('/admin/'.$org->slug)->assertRedirect(url('/platform'));
    expect(auth()->id())->toBe($operator->id)
        ->and(Activity::query()->where('event', 'platform.impersonation_ended')->sole()->properties['ended_by'])->toBe('expired');
});

it('honours platform.impersonation.minutes', function (): void {
    config(['platform.impersonation.minutes' => 5]);
    [$operator, $target, $org] = platformImpersonationScenario();
    platformStartImpersonation($operator, $target, $org);

    $this->travel(6)->minutes();

    $this->get('/app/')->assertRedirect(url('/platform')); // web group
    expect(auth()->id())->toBe($operator->id);
});

it('refuses the API while impersonating', function (): void {
    [$operator, $target, $org] = platformImpersonationScenario();
    platformStartImpersonation($operator, $target, $org);

    $this->getJson('/api/v1/me', ['Referer' => 'http://localhost', 'Origin' => 'http://localhost'])
        ->assertForbidden()->assertJsonPath('error.code', 'impersonation_api_blocked');
});

it('records a sign-out of the impersonated session as ended_by=logout', function (): void {
    [$operator, $target, $org] = platformImpersonationScenario();
    platformStartImpersonation($operator, $target, $org);

    $this->post('/admin/logout')->assertRedirect();

    expect(Activity::query()->where('event', 'platform.impersonation_ended')->sole()->properties['ended_by'])->toBe('logout')
        ->and(auth()->check())->toBeFalse();
});

it('refuses the exit route without an impersonation', function (): void {
    actingAsPlatformOperator(platformOperator());
    $this->post(route('filament.platform.impersonation.exit'))->assertForbidden();

    $this->actingAs(ownerOf(makeOrganization()))->post(route('filament.platform.impersonation.exit'))->assertForbidden();
    expect(Activity::query()->where('event', 'platform.impersonation_ended')->count())->toBe(0);
});
