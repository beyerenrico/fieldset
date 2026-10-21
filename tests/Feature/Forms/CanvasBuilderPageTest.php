<?php

declare(strict_types=1);

use App\Domain\Forms\Actions\CreateFormFromTemplate;
use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Forms\Actions\SaveDraft;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Forms\Schema\DefinitionTools;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Filament\Admin\Resources\Forms\FormResource;
use App\Filament\Admin\Resources\Forms\Pages\CanvasBuilder;
use App\Filament\Admin\Resources\Forms\Pages\ListForms;
use Fieldset\Json\Json;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Feature\Documents\DocumentFixtures;

/* Builder v2 page (ADR 0037): config, authorization, guarded actions, page weight. */

beforeEach(function (): void {
    $this->withoutVite();
    $this->org = makeOrganization();
    $this->designer = memberOf($this->org, SystemRole::Designer);
    $this->form = app(TenantContext::class)->run($this->org, fn (): Form => app(CreateFormFromTemplate::class)->handle($this->designer, 'service_report'));
});

/** @return array<string, mixed> the contract fixture config */
function configFixture(): array
{
    return json_decode((string) file_get_contents(base_path('tests/Fixtures/builder-v2/contract/config.json')), true);
}

it('embeds the draft and its checksum for the client', function (): void {
    actingInAdmin($this->designer, $this->org);

    $config = Livewire::test(CanvasBuilder::class, ['record' => $this->form->getKey()])
        ->assertOk()
        ->assertSee('data-builder-root', false)
        ->instance()->builderConfig();

    expect($config['formId'])->toBe($this->form->getKey())
        ->and($config['checksum'])->toBe(Json::checksum($this->form->fresh()->draft_definition))
        ->and($config['maxContainerDepth'])->toBe(3);
});

it('answers with the shape of the contract fixture', function (): void {
    actingInAdmin($this->designer, $this->org);
    $fixture = configFixture();

    $config = json_decode(json_encode(Livewire::test(CanvasBuilder::class, ['record' => $this->form->getKey()])->instance()->builderConfig()), true);

    expect(array_keys($config))->toBe(array_keys($fixture))
        ->and(array_keys($config['urls']))->toBe(array_keys($fixture['urls']))
        ->and(array_keys($config['palette'][0]))->toBe(array_keys($fixture['palette'][0]))
        ->and(array_keys($config['palette'][0]['items'][0]))->toBe(array_keys($fixture['palette'][0]['items'][0]))
        ->and($config['definition'])->toBe(json_decode(Json::encode($this->form->fresh()->draft_definition), true))
        ->and($config['lockedIds'])->toBe([])
        ->and($config['urls']['classic'])->toBe(FormResource::getUrl('edit', ['record' => $this->form]))
        ->and($config['urls']['preview'])->toEndWith('/form-preview');
});

it('lists the ids of published elements as locked', function (): void {
    actingInAdmin($this->designer, $this->org);
    app(PublishForm::class)->handle($this->form, 'v1', $this->designer);
    $ids = array_map(fn (stdClass $e): string => $e->id, DefinitionTools::elements($this->form->fresh()->draft_definition));

    $config = Livewire::test(CanvasBuilder::class, ['record' => $this->form->getKey()])->instance()->builderConfig();

    expect($config['lockedIds'])->toBe($ids);
});

it('is closed to members who cannot edit forms', function (): void {
    $viewer = memberOf($this->org, SystemRole::Viewer);
    actingInAdmin($viewer, $this->org);

    Livewire::test(CanvasBuilder::class, ['record' => $this->form->getKey()])->assertForbidden();
});

it('does not open a form of another organization', function (): void {
    $other = makeOrganization(name: 'Other GmbH');
    $stranger = memberOf($other, SystemRole::Designer);
    actingInAdmin($stranger, $other);

    $this->get(FormResource::getUrl('canvas', ['record' => $this->form], tenant: $other))->assertNotFound();
});

it('keeps the service report canvas page under 500 kB of HTML', function (): void {
    actingInAdmin($this->designer, $this->org);

    $html = $this->get(FormResource::getUrl('canvas', ['record' => $this->form]))->assertOk()->getContent();

    expect(strlen((string) $html))->toBeLessThan(500_000)
        ->and($html)->toContain('data-builder-config');
});

it('publishes the stored draft and tells the client the new locked ids', function (): void {
    actingInAdmin($this->designer, $this->org);
    $checksum = Json::checksum($this->form->draft_definition);
    $ids = array_map(fn (stdClass $e): string => $e->id, DefinitionTools::elements($this->form->draft_definition));

    Livewire::test(CanvasBuilder::class, ['record' => $this->form->getKey()])
        ->callAction(TestAction::make('publish')->arguments(['checksum' => $checksum]), ['changelog' => 'Erste Version'])
        ->assertHasNoActionErrors()
        ->assertNotified(__('forms.versions.published', ['version' => 1]))
        ->assertDispatched(CanvasBuilder::PUBLISHED, version: 1, lockedIds: $ids);

    expect(FormVersion::query()->where('form_id', $this->form->id)->value('version'))->toBe(1);
});

it('refuses actions for a checksum other than the stored draft', function (string $action): void {
    actingInAdmin($this->designer, $this->org);
    DocumentFixtures::fakeDisk();
    DocumentFixtures::fakeGotenberg();

    Livewire::test(CanvasBuilder::class, ['record' => $this->form->getKey()])
        ->callAction(TestAction::make($action)->arguments(['checksum' => 'stale']), $action === 'publish' ? ['changelog' => 'x'] : [])
        ->assertNotified(__('forms.canvas.stale_title'))
        ->assertNoFileDownloaded()
        ->assertNotDispatched(CanvasBuilder::PUBLISHED);

    expect(FormVersion::query()->where('form_id', $this->form->id)->exists())->toBeFalse();
})->with(['publish', 'previewPdf', 'exportDraft']);

it('refuses to publish a draft with lint errors', function (): void {
    actingInAdmin($this->designer, $this->org);
    $definition = $this->form->draft_definition;
    $definition->pages[0]->elements[1]->key = $definition->pages[0]->elements[0]->key;
    app(SaveDraft::class)->handle($this->form, $definition, $this->designer);

    Livewire::test(CanvasBuilder::class, ['record' => $this->form->getKey()])
        ->callAction(TestAction::make('publish')->arguments(['checksum' => Json::checksum($this->form->fresh()->draft_definition)]), ['changelog' => 'x'])
        ->assertNotified(__('forms.errors.invalid_definition'))
        ->assertNotDispatched(CanvasBuilder::PUBLISHED);

    expect(FormVersion::query()->where('form_id', $this->form->id)->exists())->toBeFalse();
});

it('previews and exports the stored draft', function (): void {
    actingInAdmin($this->designer, $this->org);
    DocumentFixtures::fakeDisk();
    DocumentFixtures::fakeGotenberg();
    $checksum = Json::checksum($this->form->draft_definition);

    Livewire::test(CanvasBuilder::class, ['record' => $this->form->getKey()])
        ->callAction(TestAction::make('previewPdf')->arguments(['checksum' => $checksum]))
        ->assertHasNoActionErrors()
        ->assertFileDownloaded($this->form->key.'.preview.pdf');
    Livewire::test(CanvasBuilder::class, ['record' => $this->form->getKey()])
        ->callAction(TestAction::make('exportDraft')->arguments(['checksum' => $checksum]))
        ->assertFileDownloaded($this->form->key.'.draft.form.json');

    expect(DocumentFixtures::sentParts()['index.html'])->toContain('Servicebericht');
});

it('opens the canvas builder from the row and keeps the classic builder as a row action (ADR 0044)', function (): void {
    actingInAdmin($this->designer, $this->org);

    Livewire::test(ListForms::class)
        ->assertActionHasUrl(TestAction::make('edit')->table($this->form), FormResource::getUrl('canvas', ['record' => $this->form]))
        ->assertActionVisible(TestAction::make('classic')->table($this->form))
        ->assertActionHasUrl(TestAction::make('classic')->table($this->form), FormResource::getUrl('edit', ['record' => $this->form]));
});
