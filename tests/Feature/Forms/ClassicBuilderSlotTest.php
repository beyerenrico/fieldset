<?php

declare(strict_types=1);

use App\Domain\Forms\Actions\CreateFormFromTemplate;
use App\Domain\Identity\Enums\SystemRole;
use App\Filament\Admin\Resources\Forms\FormResource;
use App\Filament\Admin\Resources\Forms\Pages\ClassicBuilderSlot;
use App\Filament\Admin\Resources\Forms\Pages\EditForm;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/*
 * One classic-builder render at a time (ADR 0083 amendment 2026-10-22): one render takes most of a small instance's app
 * memory, so a second one waits for the first instead of both pushing FrankenPHP over its memory limit.
 */

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->designer = memberOf($this->org, SystemRole::Designer);
    actingInAdmin($this->designer, $this->org);
    $this->form = app(CreateFormFromTemplate::class)->handle($this->designer, 'service_report');
});

it('takes the slot for each Livewire request of the classic builder too', function (): void {
    $page = Livewire::test(EditForm::class, ['record' => $this->form->getRouteKey()])->assertOk();
    $other = Cache::lock(ClassicBuilderSlot::LOCK, 60);
    expect($other->get())->toBeTrue(); // the page's render freed it when its request ended

    config(['forms.classic_builder.render_wait' => 0]);
    $page->call('autosave')->assertStatus(503);
});

it('frees the slot when the page request ends', function (): void {
    $this->get(FormResource::getUrl('edit', ['record' => $this->form]))->assertOk();

    expect(Cache::lock(ClassicBuilderSlot::LOCK, 1)->get())->toBeTrue();
});

it('answers 503 with Retry-After when another render keeps the slot past the wait', function (): void {
    config(['forms.classic_builder.render_wait' => 0]);
    Cache::lock(ClassicBuilderSlot::LOCK, 60)->get();

    $this->get(FormResource::getUrl('edit', ['record' => $this->form]))->assertStatus(503)->assertHeader('Retry-After', '5');
});

it('leaves the canvas builder free while a classic render holds the slot', function (): void {
    config(['forms.classic_builder.render_wait' => 0]);
    Cache::lock(ClassicBuilderSlot::LOCK, 60)->get();

    $this->get(FormResource::getUrl('canvas', ['record' => $this->form]))->assertOk();
});
