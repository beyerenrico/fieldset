<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Forms\Actions\CreateFormFromTemplate;
use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Forms\Actions\SyncDraft;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Filament\Admin\Resources\Forms\Pages\CanvasBuilder;
use Fieldset\Json\Json;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Livewire;

/* Gate fix (M7): an explicit save after an autosave of the same definition is audited against the last audited state. */

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->designer = memberOf($this->org, SystemRole::Designer);
    app(TenantContext::class)->set($this->org);
    $this->form = app(CreateFormFromTemplate::class)->handle($this->designer, 'service_report');
    $this->base = Json::checksum($this->form->draft_definition);
    clearAuditLog();
});

function editedDraft(Form $form, string $title): string
{
    $definition = Json::decode(Json::encode($form->fresh()->draft_definition));
    $definition->title = $title;

    return Json::encode($definition);
}

/** @return Collection<int, Activity> */
function formUpdates(): Collection
{
    return Activity::query()->where('event', 'form.updated')->orderBy('id')->get();
}

it('audits an explicit save of what an autosave already stored, against the last audited state', function (): void {
    $json = editedDraft($this->form, 'Autosave zuerst');
    $sync = app(SyncDraft::class)->handle($this->form, $json, $this->base, $this->designer);

    $save = app(SyncDraft::class)->handle($this->form, $json, $sync['checksum'], $this->designer, audit: true);

    $entries = formUpdates();
    expect($save['status'])->toBe('unchanged')
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]->properties['before']['checksum'])->toBe($this->base)
        ->and($entries[0]->properties['after']['checksum'])->toBe($sync['checksum'])
        ->and($entries[0]->properties['changes'])->not->toBeEmpty()
        ->and($entries[0]->properties['lint_errors'])->toBe(0);

    app(SyncDraft::class)->handle($this->form, $json, $sync['checksum'], $this->designer, audit: true);
    expect(formUpdates())->toHaveCount(1); // a second explicit save has nothing new to audit
});

it('writes no audit entry for an explicit save when nothing changed since the last audit', function (): void {
    $result = app(SyncDraft::class)->handle($this->form, Json::encode($this->form->draft_definition), $this->base, $this->designer, audit: true);

    expect($result['status'])->toBe('unchanged')->and(formUpdates())->toHaveCount(0);
});

it('takes the published state as the new baseline', function (): void {
    $first = app(SyncDraft::class)->handle($this->form, editedDraft($this->form, 'Vor dem Veröffentlichen'), $this->base, $this->designer);
    $version = app(PublishForm::class)->handle($this->form->fresh(), 'v1', $this->designer);
    $json = editedDraft($this->form, 'Nach dem Veröffentlichen');
    $second = app(SyncDraft::class)->handle($this->form, $json, $first['checksum'], $this->designer);

    app(SyncDraft::class)->handle($this->form, $json, $second['checksum'], $this->designer, audit: true);

    $entries = formUpdates();
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->properties['before']['checksum'])->toBe($version->checksum)
        ->and($entries[0]->properties['after']['checksum'])->toBe($second['checksum']);
});

it('audits the explicit canvas save that follows an autosave of the same JSON', function (): void {
    actingInAdmin($this->designer, $this->org);
    $json = editedDraft($this->form, 'Über die Seite');
    $page = Livewire::test(CanvasBuilder::class, ['record' => $this->form->getKey()]);

    $page->call('sync', $json, $this->base);
    $checksum = $page->effects['returns'][0]['checksum'];
    $page->call('save', $json, $checksum);

    expect($page->effects['returns'][0]['status'])->toBe('unchanged')
        ->and(formUpdates())->toHaveCount(1)
        ->and(formUpdates()[0]->properties['before']['checksum'])->toBe($this->base);
});
