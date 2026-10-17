<?php

declare(strict_types=1);

use App\Domain\Forms\Actions\CreateFormFromTemplate;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Filament\Admin\Resources\Forms\Pages\CanvasBuilder;
use FormSchema\Json\Json;
use Livewire\Livewire;

/* Builder v2 skeleton (ADR 0037): the canvas page opens the same draft for designers only. */

beforeEach(function (): void {
    $this->withoutVite();
    $this->org = makeOrganization();
    $this->designer = memberOf($this->org, SystemRole::Designer);
    $this->form = app(TenantContext::class)->run($this->org, fn (): Form => app(CreateFormFromTemplate::class)->handle($this->designer, 'service_report'));
});

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

it('is closed to members who cannot edit forms', function (): void {
    $viewer = memberOf($this->org, SystemRole::Viewer);
    actingInAdmin($viewer, $this->org);

    Livewire::test(CanvasBuilder::class, ['record' => $this->form->getKey()])->assertForbidden();
});
