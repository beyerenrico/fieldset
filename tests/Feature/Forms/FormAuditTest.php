<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Forms\Actions\CreateFormFromTemplate;
use App\Domain\Forms\Actions\DuplicateForm;
use App\Domain\Forms\Actions\ExportDefinition;
use App\Domain\Forms\Actions\ImportDefinition;
use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Forms\Actions\RetireVersion;
use App\Domain\Forms\Actions\RollbackForm;
use App\Domain\Forms\Actions\SaveDraft;
use App\Domain\Forms\Actions\SetFormStatus;
use App\Domain\Forms\Actions\SyncFormAccess;
use App\Domain\Forms\Enums\FormStatus;
use App\Domain\Forms\Models\FormAccess;
use App\Domain\Identity\Actions\DeleteRole;
use App\Domain\Identity\Actions\SaveRole;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Filament\Admin\Resources\Forms\Pages\EditForm;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->designer = memberOf($this->org, SystemRole::Designer);
    app(TenantContext::class)->set($this->org);
    $this->form = app(CreateFormFromTemplate::class)->handle($this->designer, 'service_report');
    Activity::query()->delete();
});

function events(): array
{
    return Activity::query()->orderBy('id')->pluck('event')->all();
}

it('writes exactly one entry per form action', function (): void {
    $v1 = app(PublishForm::class)->handle($this->form, 'v1', $this->designer);
    app(RollbackForm::class)->handle($this->form->refresh(), $v1, $this->designer);
    app(RetireVersion::class)->handle($v1->refresh(), $this->designer);
    app(SetFormStatus::class)->handle($this->form->refresh(), FormStatus::Archived, $this->designer);
    $copy = app(DuplicateForm::class)->handle($this->form, $this->designer);
    $json = app(ExportDefinition::class)->handle($this->form, null, $this->designer);
    app(ImportDefinition::class)->handle($this->designer, $json, 'Imported');
    app(SyncFormAccess::class)->handle($this->form, [], ['field_worker'], $this->designer);

    expect(events())->toBe([
        'form.published', 'form.rolled_back', 'form.version_retired', 'form.status_changed',
        'form.duplicated', 'form.exported', 'form.imported', 'form.access_changed',
    ]);
});

it('audits an explicit save once with a before/after diff, and an unchanged save not at all', function (): void {
    app(SaveDraft::class)->handle($this->form, $this->form->draft_definition, $this->designer);
    expect(events())->toBe([]);

    $definition = $this->form->draft_definition;
    $definition->pages[0]->elements[0]->label = 'Auftraggeber';
    app(SaveDraft::class)->handle($this->form, $definition, $this->designer);

    $entry = Activity::query()->sole();
    expect($entry->event)->toBe('form.updated')
        ->and($entry->properties['before']['checksum'])->not->toBe($entry->properties['after']['checksum'])
        ->and($entry->properties['changes'][0])->toMatchArray(['change' => 'changed', 'key' => 'customer_name', 'properties' => ['label']]);
});

it('never audits autosaves', function (): void {
    $definition = $this->form->draft_definition;
    $definition->title = 'Neu';
    app(SaveDraft::class)->handle($this->form, $definition, $this->designer, autosave: true);

    expect(events())->toBe([]);
});

it('publishing from the builder writes only the publish entry', function (): void {
    actingInAdmin($this->designer, $this->org);
    Activity::query()->delete();

    Livewire::test(EditForm::class, ['record' => $this->form->getRouteKey()])
        ->set('data.title', 'Servicebericht 2')
        ->callAction('publish', ['changelog' => 'v1']);

    expect(events())->toBe(['form.published']);
});

it('keeps form grants in step with role renames and deletions', function (): void {
    $owner = ownerOf($this->org);
    $role = app(SaveRole::class)->handle($owner, $this->org, null, 'crew_a', []);
    app(SyncFormAccess::class)->handle($this->form, [], ['crew_a'], $owner);

    app(SaveRole::class)->handle($owner, $this->org, $role, 'crew_b', []);
    expect(FormAccess::query()->pluck('role_name')->all())->toBe(['crew_b']);

    app(DeleteRole::class)->handle($owner, $role->refresh());
    expect(FormAccess::query()->count())->toBe(0);
});

it('duplicates forms with maximum-length keys', function (): void {
    $this->form->forceFill(['key' => str_repeat('k', 64)])->save();

    $copy = app(DuplicateForm::class)->handle($this->form->refresh(), $this->designer);

    expect(strlen($copy->key))->toBeLessThanOrEqual(64)->and($copy->key)->toEndWith('_copy');
});
