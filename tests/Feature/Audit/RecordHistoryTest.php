<?php

declare(strict_types=1);

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\History\HistoryScope;
use App\Domain\Audit\Models\Activity;
use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Forms\Actions\SetFormStatus;
use App\Domain\Forms\Enums\FormStatus;
use App\Domain\Identity\Actions\SetMembershipStatus;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Membership;
use App\Domain\Workflows\Models\Workflow;
use App\Filament\Admin\Pages\Sso\SsoSettings;
use App\Filament\Admin\Resources\AuditLogs\Support\HistoryHook;
use App\Filament\Admin\Resources\AuditLogs\Widgets\RecordHistory;
use App\Filament\Admin\Resources\Forms\FormResource;
use App\Filament\Admin\Resources\Members\MemberResource;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use App\Filament\Admin\Resources\Workflows\WorkflowResource;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FieldAppApi;
use Tests\Support\FieldAppScenario;

/* §8.11 "Records get a History tab": forms, submissions, members, workflows and the SSO provider. */

beforeEach(function (): void {
    Storage::fake('local');
    $this->s = FieldAppScenario::make();
    actingInAdmin($this->s->owner, $this->s->org);
});

it('shows a form\'s history, including its versions, on the form page', function (): void {
    app(SetFormStatus::class)->handle($this->s->form, FormStatus::Archived, $this->s->owner);
    $other = FieldAppScenario::make();

    $this->get(FormResource::getUrl('edit', ['record' => $this->s->form]))->assertOk()
        ->assertSee(__('audit.history.heading'))->assertSee(__('audit.events.form.status_changed'));

    $entries = HistoryScope::forRecord($this->s->form)->apply(Activity::query()->where('organization_id', $this->s->org->id))->pluck('event')->all();
    expect($entries)->toContain('form.published')->toContain('form.status_changed')->toContain('form.created_from_template');
    expect(HistoryScope::forRecord($other->form)->apply(Activity::query()->where('organization_id', $this->s->org->id))->count())->toBe(0);
});

it('shows a form\'s history below the canvas builder, the default form page (ADR 0044)', function (): void {
    app(AuditLogger::class)->record('form.exported', $this->s->form, [], $this->s->owner);

    $this->get(FormResource::getUrl('canvas', ['record' => $this->s->form]))->assertOk()
        ->assertSeeLivewire(HistoryHook::COMPONENT)
        ->assertSee(__('audit.events.form.exported'))->assertSee(__('audit.events.form.published'))
        // After the builder layout (palette, canvas, inspector, preview), not inside it.
        ->assertSeeInOrder(['data-builder-preview-pane', 'fi-audit-history', __('audit.history.heading')], false);
});

it('shows the history on the submission, workflow, members and SSO pages', function (): void {
    $uuid = FieldAppApi::readyDraft($this->s, $this->s->worker);
    FieldAppApi::submit($this->s, $this->s->worker, $uuid, 1)->assertOk();
    actingInAdmin($this->s->owner, $this->s->org);
    $workflow = Workflow::factory()->create(['name' => 'Notify office']);
    $membership = Membership::query()->where('organization_id', $this->s->org->id)->where('user_id', $this->s->worker->id)->sole();
    app(SetMembershipStatus::class)->handle($membership, MembershipStatus::Deactivated, $this->s->owner);

    $this->get(SubmissionResource::getUrl('view', ['record' => $uuid]))->assertOk()->assertSeeLivewire(HistoryHook::COMPONENT)
        ->assertSee(__('audit.history.heading'))->assertSee(__('audit.events.submission.created'));
    $this->get(WorkflowResource::getUrl('edit', ['record' => $workflow]))->assertOk()->assertSeeLivewire(HistoryHook::COMPONENT)->assertSee(__('audit.history.heading'));
    $this->get(MemberResource::getUrl('index'))->assertOk()->assertSeeLivewire(HistoryHook::COMPONENT)->assertSee(__('audit.events.member.status_changed'));
    $this->get(SsoSettings::getUrl())->assertOk()->assertSeeLivewire(HistoryHook::COMPONENT)->assertSee(__('audit.history.heading'));
});

it('filters the members history by member', function (): void {
    $membership = Membership::query()->where('organization_id', $this->s->org->id)->where('user_id', $this->s->worker->id)->sole();
    app(SetMembershipStatus::class)->handle($membership, MembershipStatus::Deactivated, $this->s->owner);
    $statusEntry = Activity::query()->where('event', 'member.status_changed')->sole();
    $ownerEntry = Activity::query()->where('subject_type', $this->s->owner->getMorphClass())->where('subject_id', (string) $this->s->owner->id)->first()
        ?? app(AuditLogger::class)->record('auth.mfa_enabled', $this->s->owner);

    Livewire::test(RecordHistory::class, ['scope' => HistoryScope::MEMBERS])
        ->assertCanSeeTableRecords([$statusEntry])
        ->filterTable('member', (string) $this->s->worker->id)
        ->assertCanSeeTableRecords([$statusEntry])
        ->assertCanNotSeeTableRecords([$ownerEntry]);
});

it('hides the history from members without audit.view', function (): void {
    $designer = memberOf($this->s->org, SystemRole::Designer);
    $form = $this->s->form;
    app(PublishForm::class)->handle($form, 'v2', $this->s->owner);
    actingInAdmin($designer, $this->s->org);

    $this->get(FormResource::getUrl('edit', ['record' => $form]))->assertOk()->assertDontSeeLivewire(HistoryHook::COMPONENT)->assertDontSee(__('audit.history.heading'));
    expect(RecordHistory::canView())->toBeFalse();
});
