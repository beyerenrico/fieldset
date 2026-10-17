<?php

declare(strict_types=1);

use App\Domain\Forms\Actions\CreateFormFromTemplate;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Filament\Admin\Resources\Forms\FormResource;

/* Gate fix (M7, WCAG 2.2 AA): landmarks, focus order and keyboard affordances of the canvas page. */

beforeEach(function (): void {
    $this->withoutVite();
    $this->org = makeOrganization();
    $this->designer = memberOf($this->org, SystemRole::Designer);
    $this->form = app(TenantContext::class)->run($this->org, fn (): Form => app(CreateFormFromTemplate::class)->handle($this->designer, 'service_report'));
    actingInAdmin($this->designer, $this->org);
});

function canvasHtml(Form $form): string
{
    return (string) test()->get(FormResource::getUrl('canvas', ['record' => $form]))->assertOk()->getContent();
}

it('puts the preview after the properties pane in its own wire:ignore block', function (): void {
    $html = canvasHtml($this->form);

    $root = strpos($html, 'data-builder-root');
    $inspector = strpos($html, 'data-builder-inspector');
    $preview = strpos($html, 'data-builder-preview-pane');
    expect($root)->toBeInt()->and($inspector)->toBeGreaterThan($root)->and($preview)->toBeGreaterThan($inspector)
        ->and($html)->toMatch('/class="bv2-preview-block" wire:ignore wire:key="builder-v2-preview"/')
        ->and(substr_count($html, 'iframe'))->toBeGreaterThanOrEqual(1);
});

it('uses a labelled group for the toolbar, a palette region with a heading and distinct canvas labels', function (): void {
    $html = canvasHtml($this->form);

    expect($html)->not->toContain('role="toolbar"')
        ->and($html)->toContain('class="bv2-toolbar" role="group" aria-label="'.__('builder_v2.toolbar.label').'"')
        ->and($html)->not->toMatch('/<nav[^>]*data-builder-palette/')
        ->and($html)->toContain('<section class="bv2-palette" aria-labelledby="bv2-palette-title">')
        ->and($html)->toContain('id="bv2-palette-title">'.__('builder_v2.palette.label').'</h2>')
        ->and($html)->toContain('class="bv2-canvas" aria-label="'.__('builder_v2.canvas.section_label').'"')
        ->and($html)->toContain('role="tree" aria-label="'.__('builder_v2.canvas.label').'"')
        ->and(__('builder_v2.canvas.section_label'))->not->toBe(__('builder_v2.canvas.label'));
});

it('keeps unavailable toolbar buttons focusable and describes the publish button', function (): void {
    $html = canvasHtml($this->form);

    expect($html)->toMatch('/data-builder-toolbar="undo"[^>]*aria-disabled="true"/')
        ->and($html)->not->toMatch('/data-builder-toolbar="(undo|redo)"[^>]*\sdisabled[\s>]/')
        ->and($html)->toContain('id="bv2-publish-reason" data-builder-publish-reason');
});

it('offers "Go to properties" with Alt+P and a visible keyboard help panel', function (): void {
    $html = canvasHtml($this->form);

    expect($html)->toMatch('/data-builder-goto-inspector aria-keyshortcuts="Alt\+P"/')
        ->and($html)->toContain('data-builder-help-toggle aria-expanded="false" aria-controls="bv2-help"')
        ->and($html)->toContain('id="bv2-help" data-builder-help')
        ->and($html)->toContain('<dt>Alt+P</dt><dd>'.e(__('builder_v2.help.actions.properties')).'</dd>')
        ->and($html)->toContain(e(__('builder_v2.help.keys.menu')));
});
