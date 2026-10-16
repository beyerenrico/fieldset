<?php

declare(strict_types=1);

use App\Domain\Forms\Actions\CreateForm;
use App\Domain\Forms\Actions\CreateFormFromTemplate;
use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormAccess;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Group;
use App\Filament\Admin\Resources\Forms\Pages\EditForm;
use App\Filament\Admin\Resources\Forms\Pages\ListForms;
use App\Filament\Admin\Resources\Forms\RelationManagers\VersionsRelationManager;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->designer = memberOf($this->org, SystemRole::Designer);
    actingInAdmin($this->designer, $this->org);
});

it('lists forms and creates a blank one', function (): void {
    Livewire::test(ListForms::class)
        ->callAction('createBlank', ['name' => 'Übergabeprotokoll'])
        ->assertHasNoActionErrors();

    expect(Form::query()->where('key', 'ubergabeprotokoll')->exists())->toBeTrue();
});

it('creates a form from a template through the gallery action', function (): void {
    Livewire::test(ListForms::class)
        ->callAction('createFromTemplate', ['template' => 'invoice'])
        ->assertHasNoActionErrors();

    expect(Form::query()->sole()->draft_definition->source->template)->toBe('invoice');
});

it('imports a pasted JSON definition', function (): void {
    $json = file_get_contents(base_path('spec/templates/service_report.json'));

    Livewire::test(ListForms::class)->callAction('importJson', ['json' => $json])->assertHasNoActionErrors();

    expect(Form::query()->sole()->name)->toBe('Servicebericht');
});

it('loads the builder, saves an edited label and lints', function (): void {
    $form = app(CreateFormFromTemplate::class)->handle($this->designer, 'service_report');

    $page = Livewire::test(EditForm::class, ['record' => $form->getRouteKey()]);
    $page->assertOk()->assertSet('lint.findings', []);

    $data = $page->get('data');
    $firstPage = array_key_first($data['pages']);
    $firstElement = array_key_first($data['pages'][$firstPage]['elements']);
    $page->set("data.pages.{$firstPage}.elements.{$firstElement}.data.label", 'Auftraggeber')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($form->refresh()->draft_definition->pages[0]->elements[0]->label)->toBe('Auftraggeber')
        ->and($form->draft_definition->pages[0]->elements[0]->role)->toBe('customer.name');
});

it('publishes from the builder with a changelog', function (): void {
    $form = app(CreateFormFromTemplate::class)->handle($this->designer, 'inspection_checklist');

    Livewire::test(EditForm::class, ['record' => $form->getRouteKey()])
        ->callAction('publish', ['changelog' => 'Erste Version'])
        ->assertHasNoActionErrors();

    expect(FormVersion::query()->where('form_id', $form->id)->value('version'))->toBe(1);
});

it('saves form access grants', function (): void {
    [$form] = app(CreateForm::class)->handle($this->designer, 'Access');
    $crew = Group::query()->create(['name' => 'Crew']);

    Livewire::test(EditForm::class, ['record' => $form->getRouteKey()])
        ->set('data.access_groups', [$crew->id])
        ->set('data.access_roles', ['field_worker'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(FormAccess::query()->where('form_id', $form->id)->count())->toBe(2);
});

it('autosaves only when the state changed', function (): void {
    [$form] = app(CreateForm::class)->handle($this->designer, 'Auto');
    $before = $form->updated_at;

    $page = Livewire::test(EditForm::class, ['record' => $form->getRouteKey()]);
    $page->call('autosave');
    expect($form->refresh()->updated_at->equalTo($before))->toBeTrue();

    $this->travel(1)->minutes();
    $page->set('data.title', 'Auto 2')->call('autosave');
    expect($form->refresh()->name)->toBe('Auto 2');
});

it('shows versions, diffs and rolls back', function (): void {
    $form = app(CreateFormFromTemplate::class)->handle($this->designer, 'invoice');
    $v1 = app(PublishForm::class)->handle($form, 'v1', $this->designer);

    Livewire::test(VersionsRelationManager::class, ['ownerRecord' => $form, 'pageClass' => EditForm::class])
        ->assertCanSeeTableRecords([$v1])
        ->callTableAction('rollback', $v1, ['changelog' => 'back to v1']);

    expect($form->versions()->count())->toBe(2);
});

it('forbids the builder to roles without forms.manage', function (SystemRole $role): void {
    $form = Form::factory()->create();
    actingInAdmin(memberOf($this->org, $role), $this->org);

    $this->get("/admin/{$this->org->slug}/forms")->assertForbidden();
    $this->get("/admin/{$this->org->slug}/forms/{$form->id}/edit")->assertForbidden();
})->with([SystemRole::Reviewer, SystemRole::Viewer]);
