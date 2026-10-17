<?php

declare(strict_types=1);

use App\Domain\Forms\Actions\CreateForm;
use App\Domain\Forms\Actions\CreateFormFromTemplate;
use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Forms\Actions\SaveDraft;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormAccess;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Group;
use App\Filament\Admin\Resources\Forms\FormResource;
use App\Filament\Admin\Resources\Forms\Pages\EditForm;
use App\Filament\Admin\Resources\Forms\Pages\ListForms;
use App\Filament\Admin\Resources\Forms\RelationManagers\VersionsRelationManager;
use FormSchema\Json\Json;
use Illuminate\Support\Str;
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
        ->assertHasNoActionErrors()
        ->assertDispatched(VersionsRelationManager::REFRESH_EVENT);

    expect(FormVersion::query()->where('form_id', $form->id)->value('version'))->toBe(1);
});

it('suggests a key from the label of a new element and keeps an existing key', function (): void {
    $form = app(CreateFormFromTemplate::class)->handle($this->designer, 'service_report');
    $page = Livewire::test(EditForm::class, ['record' => $form->getRouteKey()]);
    $data = $page->get('data');
    $firstPage = array_key_first($data['pages']);
    [$first, $second] = array_keys($data['pages'][$firstPage]['elements']);
    $path = fn (string $element, string $field): string => "data.pages.{$firstPage}.elements.{$element}.data.{$field}";

    $page->set($path($first, 'key'), '')->set($path($first, 'label'), 'Prüfdatum Übergabe 2.0');
    $page->set($path($second, 'label'), 'Anderer Text');

    expect($page->get($path($first, 'key')))->toBe('pruefdatum_uebergabe_2_0')
        ->and($page->get($path($second, 'key')))->toBe('customer_email');
});

it('reports publish validation errors instead of silently keeping the dialog open', function (): void {
    $form = app(CreateFormFromTemplate::class)->handle($this->designer, 'inspection_checklist');
    $page = Livewire::test(EditForm::class, ['record' => $form->getRouteKey()]);
    $data = $page->get('data');
    $firstPage = array_key_first($data['pages']);
    $first = array_key_first($data['pages'][$firstPage]['elements']);

    $page->set("data.pages.{$firstPage}.elements.{$first}.data.key", '')
        ->callAction('publish', ['changelog' => 'Erste Version'])
        ->assertNotified(__('forms.errors.fix_fields'))
        ->assertHasFormErrors(["pages.{$firstPage}.elements.{$first}.data.key"]);

    expect(FormVersion::query()->where('form_id', $form->id)->exists())->toBeFalse();
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

it('clones a block server-side with a new id and suffixed key', function (): void {
    $form = app(CreateFormFromTemplate::class)->handle($this->designer, 'service_report');
    $page = Livewire::test(EditForm::class, ['record' => $form->getRouteKey()]);
    $data = $page->get('data');
    $pageKey = array_key_first($data['pages']);
    $itemKey = array_key_first($data['pages'][$pageKey]['elements']);

    $page->callFormComponentAction("pages.{$pageKey}.elements", 'clone', arguments: ['item' => $itemKey])->call('save');

    $elements = $form->refresh()->draft_definition->pages[0]->elements;
    $keys = array_map(fn ($e) => $e->key, $elements);
    expect($keys)->toContain('customer_name', 'customer_name_copy')
        ->and(count(array_unique(array_map(fn ($e) => $e->id, $elements))))->toBe(count($elements));
});

it('halts with a notification instead of failing on a structurally invalid save', function (): void {
    [$form] = app(CreateForm::class)->handle($this->designer, 'Broken');

    Livewire::test(EditForm::class, ['record' => $form->getRouteKey()])
        ->set('data.design', str_repeat('x', 80)) // meta-schema: design maxLength 64
        ->call('save')
        ->assertNotified();

    expect($form->refresh()->draft_definition->settings->document ?? null)->toBeNull();
});

it('disables publishing while lint errors exist', function (): void {
    [$form] = app(CreateForm::class)->handle($this->designer, 'Dupes');
    $definition = $form->draft_definition;
    $definition->pages[0]->elements = [
        (object) ['id' => (string) Str::uuid7(), 'type' => 'text', 'key' => 'a', 'label' => 'A'],
        (object) ['id' => (string) Str::uuid7(), 'type' => 'text', 'key' => 'a', 'label' => 'B'],
    ];
    app(SaveDraft::class)->handle($form, $definition, $this->designer);

    Livewire::test(EditForm::class, ['record' => $form->getRouteKey()])
        ->assertActionDisabled('publish');
});

it('retires a version and shows a diff from the version history', function (): void {
    $form = app(CreateFormFromTemplate::class)->handle($this->designer, 'invoice');
    $v1 = app(PublishForm::class)->handle($form, 'v1', $this->designer);

    Livewire::test(VersionsRelationManager::class, ['ownerRecord' => $form, 'pageClass' => EditForm::class])
        ->mountTableAction('diff', $v1)
        ->assertHasNoTableActionErrors();
    Livewire::test(VersionsRelationManager::class, ['ownerRecord' => $form, 'pageClass' => EditForm::class])
        ->callTableAction('retire', $v1);

    expect($v1->refresh()->isRetired())->toBeTrue();
});

it('locks keys of published elements in the builder', function (): void {
    $form = app(CreateFormFromTemplate::class)->handle($this->designer, 'service_report');
    app(PublishForm::class)->handle($form, 'v1', $this->designer);

    $page = Livewire::test(EditForm::class, ['record' => $form->getRouteKey()]);
    $data = $page->get('data');
    $pageKey = array_key_first($data['pages']);
    $itemKey = array_key_first($data['pages'][$pageKey]['elements']);
    expect($data['pages'][$pageKey]['elements'][$itemKey]['data']['key_locked'])->toBeTrue();

    $page->set("data.pages.{$pageKey}.elements.{$itemKey}.data.key", 'renamed')->call('save');
    expect($form->refresh()->draft_definition->pages[0]->elements[0]->key)->toBe('customer_name');
});

it('accepts edits deep inside nested blocks and logic groups', function (): void {
    $form = app(CreateFormFromTemplate::class)->handle($this->designer, 'inspection_checklist');
    $page = Livewire::test(EditForm::class, ['record' => $form->getRouteKey()]);
    $data = $page->get('data');
    $pageKey = array_keys($data['pages'])[1];                         // "checks" page
    $sectionKey = array_key_first($data['pages'][$pageKey]['elements']);
    $section = $data['pages'][$pageKey]['elements'][$sectionKey]['data'];
    $noteKey = array_keys($section['elements'])[1];                   // doors_note (visibleIf rule)
    $groupKey = array_key_first($section['elements'][$noteKey]['data']['visible_if']['groups']);
    $ruleKey = array_key_first($section['elements'][$noteKey]['data']['visible_if']['groups'][$groupKey]['rules']);
    $base = "data.pages.{$pageKey}.elements.{$sectionKey}.data.elements.{$noteKey}.data.visible_if.groups.{$groupKey}.rules.{$ruleKey}";

    $page->set("{$base}.value", 'na')->call('save')->assertHasNoFormErrors();

    $note = $form->refresh()->draft_definition->pages[1]->elements[0]->elements[1];
    expect(Json::encode($note->visibleIf))->toBe('{"===":[{"var":"doors"},"na"]}');
});

it('renders the live preview with the unsaved definition', function (): void {
    $form = app(CreateFormFromTemplate::class)->handle($this->designer, 'service_report');
    $page = Livewire::test(EditForm::class, ['record' => $form->getRouteKey()])->set('data.title', 'Ungespeichert');

    expect($page->instance()->previewDefinitionJson())->toContain('"title":"Ungespeichert"');
    $page->assertSee('form-preview', false);
});

it('refuses to autosave over a draft that changed elsewhere and offers a reload', function (): void {
    $form = app(CreateFormFromTemplate::class)->handle($this->designer, 'service_report');
    $page = Livewire::test(EditForm::class, ['record' => $form->getRouteKey()]);
    $elsewhere = $form->fresh()->draft_definition;
    $elsewhere->title = 'Aus dem Canvas-Builder';
    app(SaveDraft::class)->handle($form->fresh(), $elsewhere, $this->designer, autosave: true);

    $this->travel(1)->minutes();
    $page->set('data.title', 'Veralteter Tab')->call('autosave')->assertNotified(__('forms.canvas.stale_title'));

    expect($form->fresh()->draft_definition->title)->toBe('Aus dem Canvas-Builder')
        ->and($page->get('staleNotified'))->toBeTrue();
});

it('refuses an explicit save over a draft that changed elsewhere', function (): void {
    $form = app(CreateFormFromTemplate::class)->handle($this->designer, 'service_report');
    $page = Livewire::test(EditForm::class, ['record' => $form->getRouteKey()]);
    $elsewhere = $form->fresh()->draft_definition;
    $elsewhere->title = 'Aus dem Canvas-Builder';
    app(SaveDraft::class)->handle($form->fresh(), $elsewhere, $this->designer, autosave: true);

    $page->set('data.title', 'Veralteter Tab')->call('save')->assertNotified(__('forms.canvas.stale_title'));

    expect($form->fresh()->draft_definition->title)->toBe('Aus dem Canvas-Builder');
});

it('keeps saving after its own saves', function (): void {
    [$form] = app(CreateForm::class)->handle($this->designer, 'Mehrfach');
    $page = Livewire::test(EditForm::class, ['record' => $form->getRouteKey()]);

    $page->set('data.title', 'Erster')->call('save')->assertHasNoFormErrors();
    $this->travel(1)->minutes();
    $page->set('data.title', 'Zweiter')->call('autosave');

    expect($form->fresh()->name)->toBe('Zweiter');
});

it('saves and opens the canvas builder on the same draft', function (): void {
    $form = app(CreateFormFromTemplate::class)->handle($this->designer, 'service_report');

    Livewire::test(EditForm::class, ['record' => $form->getRouteKey()])
        ->set('data.title', 'Vor dem Wechsel')
        ->callAction('canvas')
        ->assertRedirect(FormResource::getUrl('canvas', ['record' => $form]));

    expect($form->fresh()->draft_definition->title)->toBe('Vor dem Wechsel');
});

it('offers a Today preset for date defaults in the classic builder', function (): void {
    $form = app(CreateFormFromTemplate::class)->handle($this->designer, 'service_report');
    $definition = $form->draft_definition;
    unset($definition->pages[0]->elements[3]->default); // service_date
    app(SaveDraft::class)->handle($form, $definition, $this->designer);
    $page = Livewire::test(EditForm::class, ['record' => $form->getRouteKey()]);
    $data = $page->get('data');
    $pageKey = array_key_first($data['pages']);
    $dateKey = array_keys($data['pages'][$pageKey]['elements'])[3];
    $path = "pages.{$pageKey}.elements.{$dateKey}.data.default";

    $page->callFormComponentAction($path, 'today')->call('save')->assertHasNoFormErrors();

    expect(Json::encode($form->fresh()->draft_definition->pages[0]->elements[3]->default))->toBe('{"var":"$today"}');
});
