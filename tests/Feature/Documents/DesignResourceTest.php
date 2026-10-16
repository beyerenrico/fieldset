<?php

declare(strict_types=1);

use App\Domain\Documents\Designs\Actions\CreateDesign;
use App\Domain\Documents\Designs\Actions\PublishDesign;
use App\Domain\Documents\Models\Design;
use App\Domain\Documents\Models\DesignVersion;
use App\Domain\Forms\Actions\CreateFormFromTemplate;
use App\Domain\Identity\Enums\SystemRole;
use App\Filament\Admin\Resources\Designs\DesignResource;
use App\Filament\Admin\Resources\Designs\Pages\EditDesign;
use App\Filament\Admin\Resources\Designs\Pages\ListDesigns;
use App\Filament\Admin\Resources\Designs\RelationManagers\DesignVersionsRelationManager;
use App\Filament\Admin\Resources\Forms\Pages\EditForm;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Documents\DocumentFixtures;

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    DocumentFixtures::fakeGotenberg();
    $this->org = makeOrganization();
    $this->designer = memberOf($this->org, SystemRole::Designer);
    actingInAdmin($this->designer, $this->org);
});

it('lists designs and creates one (version 1 published)', function (): void {
    Livewire::test(ListDesigns::class)->assertOk()
        ->callAction('createDesign', ['name' => 'Rechnung'])
        ->assertHasNoActionErrors();

    $design = Design::query()->sole();
    expect($design->key)->toBe('rechnung')->and($design->is_default)->toBeTrue()
        ->and($design->currentVersion?->version)->toBe(1);
});

it('edits the draft, publishes with a changelog and previews the unsaved settings', function (): void {
    $design = app(CreateDesign::class)->handle('Standard', $this->designer);

    $page = Livewire::test(EditDesign::class, ['record' => $design->getRouteKey()])->assertOk()
        ->assertSet('data.settings.paper', 'a4')
        ->fillForm(['settings.paper' => 'letter', 'settings.layout' => 'compact', 'settings.header' => '<p>Kopf {{org.name}}</p>'])
        ->call('save')->assertHasNoFormErrors();
    expect($design->refresh()->settings['layout'])->toBe('compact')->and($design->currentVersion?->version)->toBe(1);

    $page->callAction('publish', ['changelog' => 'Compact layout'])->assertHasNoActionErrors();
    expect($design->refresh()->currentVersion?->version)->toBe(2)
        ->and($design->currentVersion?->settings['paper'])->toBe('letter');

    $page->fillForm(['settings.orientation' => 'landscape'])->callAction('previewPdf')->assertHasNoActionErrors()->assertFileDownloaded('standard.preview.pdf');
    expect(DocumentFixtures::sentParts()['landscape'] ?? null)->toBe('true')
        ->and(DocumentFixtures::sentParts()['header.html'])->toContain('Kopf Acme GmbH')
        ->and($design->refresh()->settings['orientation'])->toBe('portrait');   // preview stores nothing
});

it('rolls back and retires versions from the history', function (): void {
    $design = app(CreateDesign::class)->handle('Standard', $this->designer);
    $v1 = $design->currentVersion;
    assert($v1 instanceof DesignVersion);
    app(PublishDesign::class)->handle($design, 'Letter', $this->designer, [...$design->settings, 'paper' => 'letter']);

    Livewire::test(DesignVersionsRelationManager::class, ['ownerRecord' => $design->refresh(), 'pageClass' => EditDesign::class])
        ->assertOk()
        ->callTableAction('diff', $v1)->assertHasNoTableActionErrors()
        ->callTableAction('rollback', $v1, ['changelog' => 'Back to A4'])->assertHasNoTableActionErrors()
        ->callTableAction('retire', $v1)->assertHasNoTableActionErrors();

    expect($design->refresh()->currentVersion?->version)->toBe(3)
        ->and($design->currentVersion?->settings['paper'])->toBe('a4')
        ->and($v1->refresh()->isRetired())->toBeTrue();
});

it('denies the design resource without forms.manage', function (): void {
    actingInAdmin(memberOf($this->org, SystemRole::FieldWorker), $this->org);

    expect(DesignResource::canViewAny())->toBeFalse();
});

it('previews the builder\'s unsaved definition as a PDF with sample data and the selected design', function (): void {
    $form = app(CreateFormFromTemplate::class)->handle($this->designer, 'service_report');
    app(CreateDesign::class)->handle('Service', $this->designer, ['header' => '<p>SERVICE-DESIGN</p>'], 'service');

    $page = Livewire::test(EditForm::class, ['record' => $form->getRouteKey()])->assertOk();
    $data = $page->get('data');
    $data['design'] = 'service';
    $page->set('data', $data)->callAction('previewPdf')->assertHasNoActionErrors()->assertFileDownloaded($form->key.'.preview.pdf');

    $parts = DocumentFixtures::sentParts();
    expect($parts['header.html'])->toContain('SERVICE-DESIGN')
        ->and($parts['index.html'])->toContain('Servicebericht')
        ->and($parts['index.html'])->toContain('<thead>')                       // sample repeater rows
        ->and($parts['index.html'])->toMatch('/\d+,\d{2}\x{a0}€/u')              // calculated totals
        ->and($parts['index.html'])->toContain('class="watermark"')
        ->and($form->refresh()->draft_definition->settings->document->design)->toBe('default');  // nothing saved
    Http::assertSentCount(1);
});
