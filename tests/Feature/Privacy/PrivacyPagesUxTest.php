<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\AddMember;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\ExternalIdentity;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Notifications\FakeNotifier;
use App\Domain\Notifications\Notifier;
use App\Domain\Platform\Models\PlatformAdmin;
use App\Domain\Privacy\Enums\RetentionAction;
use App\Domain\Privacy\Erasure\EraseUser;
use App\Domain\Privacy\Holds\PlaceLegalHold;
use App\Domain\Privacy\Models\DataExport;
use App\Domain\Privacy\Models\RetentionPolicy;
use App\Domain\Privacy\Retention\SaveRetentionPolicy;
use App\Filament\Admin\Pages\Privacy\LegalHolds;
use App\Filament\Admin\Pages\Privacy\MyData;
use App\Filament\Admin\Pages\Privacy\PersonalData;
use App\Filament\Admin\Pages\Privacy\RetentionSettings;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Filament\Actions\Testing\TestAction;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FieldAppApi;
use Tests\Support\FieldAppScenario;

/* §9.1 / §11 privacy UI (ADR 0039): errors at the field, no dead ends, plain wording, local dates. */

beforeEach(function (): void {
    Storage::fake('local');
    app()->instance(Notifier::class, new FakeNotifier);
    $this->s = FieldAppScenario::make();
    $this->tenant = app(TenantContext::class);
    actingInAdmin($this->s->owner, $this->s->org);
});

function privacyMembership(User $user, Organization $organization): Membership
{
    return Membership::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->sole();
}

/** An error assertion on the exact message (Livewire cuts expected messages at the first colon). */
function withMessage(string $message): Closure
{
    return fn (array $rules, array $messages): bool => in_array($message, $messages, true);
}

function retentionOf(object $test, ?int $months): void
{
    $test->tenant->run($test->s->org, fn () => app(SaveRetentionPolicy::class)->handle($test->s->form, $months, RetentionAction::Delete, $test->s->owner));
}

it('disables Erase for accounts shared with another organization or held by a platform operator, with the next step', function (): void {
    $shared = memberOf($this->s->org, SystemRole::FieldWorker);
    app(AddMember::class)->handle(makeOrganization(name: 'Other GmbH'), $shared, [SystemRole::FieldWorker]);
    $operator = memberOf($this->s->org, SystemRole::Designer);
    PlatformAdmin::query()->create(['user_id' => $operator->id]);
    actingInAdmin($this->s->owner, $this->s->org);

    Livewire::test(PersonalData::class)
        ->assertTableActionDisabled('erase', privacyMembership($shared, $this->s->org))
        ->assertTableActionDisabled('erase', privacyMembership($operator, $this->s->org))
        ->assertTableActionEnabled('erase', privacyMembership($this->s->worker, $this->s->org))
        ->assertSee('This person also belongs to another organization. Ask them to delete their account under')
        ->assertSee('This account belongs to a platform operator and cannot be erased here.');
});

it('keeps the erase modal open with a refusal of the domain at the field', function (): void {
    $admin = memberOf($this->s->org, SystemRole::Admin);
    $coOwner = memberOf($this->s->org, SystemRole::Owner);
    actingInAdmin($admin, $this->s->org);

    Livewire::test(PersonalData::class)
        ->callTableAction('erase', privacyMembership($coOwner, $this->s->org), ['confirmation' => $coOwner->email])
        ->assertHasTableActionErrors(['confirmation' => withMessage(__('privacy.erasure.only_owner_erases_owner'))])
        ->assertActionMounted(TestAction::make('erase')->table(privacyMembership($coOwner, $this->s->org)));

    expect(EraseUser::isErased($coOwner))->toBeFalse();
});

it('lets an SSO-only account confirm the deletion with its email address', function (): void {
    $sso = memberOf($this->s->org, SystemRole::Designer);
    $this->tenant->run($this->s->org, fn () => ExternalIdentity::factory()->create(['user_id' => $sso->id]));
    $sso->forceFill(['password_known' => false])->save(); // as ResolveSsoUser creates it
    actingInAdmin($sso, $this->s->org);

    Livewire::test(MyData::class)
        ->callAction('deleteAccount', ['email' => 'someone@example.com'])
        ->assertHasActionErrors(['email' => withMessage(__('privacy.my_data.email_mismatch'))])
        ->assertActionMounted('deleteAccount');
    expect(EraseUser::isErased($sso))->toBeFalse();

    Livewire::test(MyData::class)
        ->callAction('deleteAccount', ['email' => '  '.strtoupper($sso->email).' '])
        ->assertRedirect();
    expect(EraseUser::isErased($sso))->toBeTrue();
});

it('asks an SSO account for its password once the person set one', function (): void {
    $sso = memberOf($this->s->org, SystemRole::Designer);
    $this->tenant->run($this->s->org, fn () => ExternalIdentity::factory()->create(['user_id' => $sso->id]));
    $sso->forceFill(['password_known' => false])->save();
    $sso->forceFill(['password' => 'a-new-password-123'])->save(); // the person sets a password
    actingInAdmin($sso, $this->s->org);

    Livewire::test(MyData::class)
        ->callAction('deleteAccount', ['email' => $sso->email, 'password' => 'wrong'])
        ->assertHasActionErrors(['password']);
    expect(EraseUser::isErased($sso))->toBeFalse();
});

it('shows the last-Owner refusal of "Delete my account" at the password field', function (): void {
    Livewire::test(MyData::class)
        ->callAction('deleteAccount', ['password' => 'password'])
        ->assertHasActionErrors(['password' => withMessage(__('privacy.erasure.last_owner', ['organization' => $this->s->org->name]))])
        ->assertActionMounted('deleteAccount');

    expect(EraseUser::isErased($this->s->owner))->toBeFalse();
});

it('explains a form legal hold and disables it while the retention is too short', function (): void {
    retentionOf($this, 24);

    Livewire::test(RetentionSettings::class)
        ->assertTableActionHasLabel('hold', __('privacy.holds.place'), $this->s->form)
        ->assertTableActionDisabled('hold', $this->s->form)
        ->assertSee('before placing a legal hold.');

    retentionOf($this, null);
    Livewire::test(RetentionSettings::class)
        ->assertTableActionEnabled('hold', $this->s->form)
        ->mountTableAction('hold', $this->s->form)
        ->assertMountedActionModalSee(__('privacy.holds.place_description', ['months' => 120]));
    Livewire::test(RetentionSettings::class)
        ->callTableAction('hold', $this->s->form, ['reason' => 'ab'])
        ->assertHasTableActionErrors(['reason' => withMessage(__('privacy.holds.reason_invalid', ['min' => 3, 'max' => 1000]))])
        ->assertActionMounted(TestAction::make('hold')->table($this->s->form));
});

it('asks to confirm a shorter retention with the number of submissions the next run removes', function (): void {
    $this->travel(-30)->months();
    $uuid = FieldAppApi::readyDraft($this->s, $this->s->worker);
    FieldAppApi::submit($this->s, $this->s->worker, $uuid, 1)->assertOk();
    $this->travelBack();
    auth()->shouldUse('web');
    retentionOf($this, 130);
    actingInAdmin($this->s->owner, $this->s->org);

    Livewire::test(RetentionSettings::class)
        ->callTableAction('retention', $this->s->form, ['months' => 140, 'action' => 'delete'])
        ->assertNotified(__('privacy.retention.saved'))
        ->assertActionNotMounted('confirmShorterRetention');

    $page = Livewire::test(RetentionSettings::class)
        ->callTableAction('retention', $this->s->form, ['months' => 24, 'action' => 'anonymize'])
        ->assertActionMounted('confirmShorterRetention')
        ->assertMountedActionModalSee(trans_choice('privacy.retention.shorten_description', 1, ['action' => __('privacy.retention.actions_done.anonymize')]));
    expect(RetentionPolicy::query()->sole()->months)->toBe(140);

    $page->callMountedAction()->assertNotified(__('privacy.retention.saved'));
    expect(RetentionPolicy::query()->sole()->months)->toBe(24)
        ->and(__('privacy.retention.months_help'))->toContain('This cannot be undone.');
});

it('shows empty states with the way forward', function (): void {
    $owner = User::factory()->create();
    $empty = makeOrganization($owner, 'Empty GmbH');
    actingInAdmin($owner, $empty);

    Livewire::test(RetentionSettings::class)->assertSee(__('privacy.retention.empty'));
    Livewire::test(LegalHolds::class)
        ->assertSee(__('privacy.holds.empty'))
        ->assertSee(e(__('privacy.holds.empty_description', ['retention' => __('privacy.retention.navigation'), 'action' => __('privacy.holds.place_submission')])), false);
});

it('labels the legal hold filter All, Active and Released', function (): void {
    $filter = Livewire::test(LegalHolds::class)->instance()->getTable()->getFilter('active');

    expect($filter)->toBeInstanceOf(TernaryFilter::class)
        ->and($filter->getPlaceholder())->toBe(__('privacy.holds.filter_all'))
        ->and($filter->getTrueLabel())->toBe(__('privacy.holds.active'))
        ->and($filter->getFalseLabel())->toBe(__('privacy.holds.released_filter'));
});

it('shows dates in the local order and erased accounts by their translated name', function (): void {
    Carbon::setTestNow('2026-10-17 13:45:12');
    retentionOf($this, null);
    $hold = $this->tenant->run($this->s->org, fn () => app(PlaceLegalHold::class)->forForm($this->s->form, 'Tax audit', $this->s->owner));
    $export = $this->tenant->run($this->s->org, fn () => DataExport::factory()->create(['requested_by' => $this->s->owner->id]));
    $this->tenant->run($this->s->org, fn () => app(EraseUser::class)->byAdmin($this->s->owner, $this->s->worker));
    app()->setLocale('de');
    $local = fn (Carbon $at): string => $at->copy()->timezone(SubmissionResource::displayTimezone())->locale('de')->isoFormat('L LT');

    Livewire::test(LegalHolds::class)->assertSee($local($hold->created_at))->assertDontSee('Okt 17, 2026');
    Livewire::test(MyData::class)->assertSee($local($export->created_at));
    Livewire::test(PersonalData::class)->assertSee('Gelöschte Person')->assertDontSee('Deleted user');

    expect($local($hold->created_at))->toBe('17.10.2026 15:45');
});
