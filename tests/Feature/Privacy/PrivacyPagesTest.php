<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Membership;
use App\Domain\Notifications\FakeNotifier;
use App\Domain\Notifications\Notifier;
use App\Domain\Privacy\Erasure\EraseUser;
use App\Domain\Privacy\Models\DataExport;
use App\Domain\Privacy\Models\LegalHold;
use App\Domain\Privacy\Models\RetentionPolicy;
use App\Domain\Submissions\Models\Submission;
use App\Filament\Admin\Pages\Privacy\LegalHolds;
use App\Filament\Admin\Pages\Privacy\MyData;
use App\Filament\Admin\Pages\Privacy\PersonalData;
use App\Filament\Admin\Pages\Privacy\RetentionSettings;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FieldAppApi;
use Tests\Support\FieldAppScenario;

/* §11 Privacy admin UI (ADR 0039): retention per form, legal holds, member export/erasure, self-service. */

beforeEach(function (): void {
    Storage::fake('local');
    app()->instance(Notifier::class, new FakeNotifier);
    $this->s = FieldAppScenario::make();
    actingInAdmin($this->s->owner, $this->s->org);
});

it('sets retention per form and places and releases a form legal hold', function (): void {
    Livewire::test(RetentionSettings::class)
        ->assertCanSeeTableRecords([$this->s->form])
        ->assertSee(__('privacy.retention.keep'))
        ->callTableAction('retention', $this->s->form, ['months' => 130, 'action' => 'delete'])
        ->assertNotified(__('privacy.retention.saved'))
        ->assertSee(trans_choice('privacy.retention.after_months', 130, ['action' => __('privacy.retention.actions.delete'), 'count' => 130]))
        ->callTableAction('hold', $this->s->form, ['reason' => 'Invoices: statutory retention'])
        ->assertNotified(__('privacy.holds.placed'))
        ->assertTableActionHidden('hold', $this->s->form)
        ->callTableAction('retention', $this->s->form, ['months' => 12, 'action' => 'delete'])
        ->assertNotified(__('privacy.retention.below_hold_minimum', ['months' => 120]))
        ->callTableAction('release', $this->s->form)
        ->assertNotified(__('privacy.holds.released'));

    expect(RetentionPolicy::query()->sole()->months)->toBe(130)
        ->and(LegalHold::query()->sole()->released_at)->not->toBeNull();
});

it('holds a single submission found by its number', function (): void {
    $uuid = FieldAppApi::readyDraft($this->s, $this->s->worker);
    FieldAppApi::submit($this->s, $this->s->worker, $uuid, 1)->assertOk();
    actingInAdmin($this->s->owner, $this->s->org);
    $number = (string) Submission::query()->findOrFail($uuid)->number;

    Livewire::test(LegalHolds::class)
        ->callTableAction('holdSubmission', null, ['submission' => $uuid, 'reason' => 'Customer dispute'])
        ->assertNotified(__('privacy.holds.placed'))
        ->assertSee($number)
        ->assertSee('Customer dispute');

    expect(LegalHold::query()->sole()->submission_uuid)->toBe($uuid);
});

it('exports and erases members from the personal data page', function (): void {
    $membership = Membership::query()->where('organization_id', $this->s->org->id)->where('user_id', $this->s->worker->id)->sole();

    Livewire::test(PersonalData::class)
        ->callTableAction('exportData', $membership)
        ->assertNotified(__('privacy.exports.queued'))
        ->callTableAction('erase', $membership, ['confirmation' => 'wrong@example.com'])
        ->assertNotified(__('privacy.members.erase_mismatch'));
    expect(EraseUser::isErased($this->s->worker))->toBeFalse();

    Livewire::test(PersonalData::class)
        ->callTableAction('erase', $membership, ['confirmation' => $this->s->worker->email])
        ->assertNotified(__('privacy.members.erased'));

    expect(EraseUser::isErased($this->s->worker))->toBeTrue()
        ->and(DataExport::query()->where('subject_user_id', $this->s->worker->id)->exists())->toBeFalse();
});

it('lets every member export their own data and delete their account with their password', function (): void {
    $designer = memberOf($this->s->org, SystemRole::Designer);
    actingInAdmin($designer, $this->s->org);

    Livewire::test(MyData::class)
        ->callAction('exportMyData')
        ->assertNotified(__('privacy.exports.queued'));
    $export = DataExport::query()->sole();
    Livewire::test(MyData::class)->assertCanSeeTableRecords([$export])->assertTableActionVisible('download', $export);

    Livewire::test(MyData::class)
        ->callAction('deleteAccount', ['password' => 'wrong'])
        ->assertHasActionErrors(['password']);
    expect(EraseUser::isErased($designer))->toBeFalse();

    Livewire::test(MyData::class)
        ->callAction('deleteAccount', ['password' => 'password'])
        ->assertRedirect();
    expect(EraseUser::isErased($designer))->toBeTrue()->and(auth()->check())->toBeFalse();
});

it('shows the privacy pages only to the roles that may use them', function (): void {
    actingInAdmin(memberOf($this->s->org, SystemRole::Designer), $this->s->org);

    $this->get(RetentionSettings::getUrl())->assertForbidden();
    $this->get(LegalHolds::getUrl())->assertForbidden();
    $this->get(PersonalData::getUrl())->assertForbidden();
    $this->get(MyData::getUrl())->assertOk();
});
