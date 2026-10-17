<?php

declare(strict_types=1);

use App\Domain\Forms\Actions\CreateFormFromTemplate;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Filament\Admin\Resources\Forms\FormResource;
use App\Filament\Admin\Resources\Forms\Pages\ListForms;
use App\Filament\Admin\Widgets\DraftFormsWidget;
use Livewire\Livewire;

/* ADR 0044: the canvas builder (v2) is the default edit target; the classic builder stays reachable. */

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->designer = memberOf($this->org, SystemRole::Designer);
    actingInAdmin($this->designer, $this->org);
});

function templateForm(object $test): Form
{
    return app(TenantContext::class)->run($test->org, fn (): Form => app(CreateFormFromTemplate::class)->handle($test->designer, 'service_report'));
}

it('links rows to the canvas builder', function (): void {
    $form = templateForm($this);

    Livewire::test(ListForms::class)->assertTableActionHasUrl('edit', FormResource::getUrl('canvas', ['record' => $form]), $form);

    expect(FormResource::builderUrl($form))->toBe(FormResource::getUrl('canvas', ['record' => $form]))
        ->and(FormResource::getGlobalSearchResultUrl($form))->toBe(FormResource::getUrl('canvas', ['record' => $form]));
});

it('opens a PDF import draft in the classic builder while its import report is shown', function (): void {
    $form = templateForm($this);
    $form->forceFill(['import_report' => (object) ['source' => 'pdf', 'pages' => 1, 'items' => []]])->save();

    Livewire::test(ListForms::class)->assertTableActionHasUrl('edit', FormResource::getUrl('edit', ['record' => $form]), $form->fresh());
    expect(FormResource::builderUrl($form->fresh()))->toBe(FormResource::getUrl('edit', ['record' => $form]));
});

it('opens new forms in the canvas builder', function (string $action, array $data): void {
    $page = Livewire::test(ListForms::class)->callAction($action, $data)->assertHasNoActionErrors();

    $page->assertRedirect(FormResource::getUrl('canvas', ['record' => Form::query()->sole()]));
})->with([
    'blank' => ['createBlank', ['name' => 'Übergabeprotokoll']],
    'template' => ['createFromTemplate', ['template' => 'invoice']],
    'JSON import' => ['importJson', ['json' => (string) file_get_contents(dirname(__DIR__, 3).'/spec/templates/service_report.json')]],
]);

it('hides both builders from members who cannot edit forms', function (): void {
    $form = templateForm($this);
    actingInAdmin(memberOf($this->org, SystemRole::Reviewer), $this->org);

    expect(FormResource::getGlobalSearchResultUrl($form))->toBeNull();
});

it('links the dashboard draft list to the default builder', function (): void {
    $canvas = templateForm($this);
    $import = templateForm($this);
    $import->forceFill(['import_report' => (object) ['source' => 'pdf', 'pages' => 1, 'items' => []]])->save();

    Livewire::test(DraftFormsWidget::class)
        ->assertSeeHtml(FormResource::getUrl('canvas', ['record' => $canvas]))
        ->assertSeeHtml(FormResource::getUrl('edit', ['record' => $import]))
        ->assertDontSeeHtml(FormResource::getUrl('edit', ['record' => $canvas]).'"');
});
