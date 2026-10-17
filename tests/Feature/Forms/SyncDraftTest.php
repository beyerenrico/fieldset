<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Forms\Actions\CreateFormFromTemplate;
use App\Domain\Forms\Actions\SyncDraft;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Filament\Admin\Resources\Forms\Pages\CanvasBuilder;
use FormSchema\Json\Json;
use Livewire\Livewire;

/* Builder v2 sync/save (ADR 0037): whole definitions, checksum guard, invalid definitions refused. */

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->designer = memberOf($this->org, SystemRole::Designer);
    app(TenantContext::class)->set($this->org);
    $this->form = app(CreateFormFromTemplate::class)->handle($this->designer, 'service_report');
    $this->base = Json::checksum($this->form->draft_definition);
    Activity::query()->delete();
});

function syncedDefinition(Form $form, ?callable $edit = null): stdClass
{
    $definition = Json::decode(Json::encode($form->draft_definition));
    if ($edit !== null) {
        $edit($definition);
    }

    return $definition;
}

function contractShape(): array
{
    return json_decode((string) file_get_contents(base_path('tests/Fixtures/builder-v2/contract/sync-result.json')), true);
}

it('saves a changed definition and answers in the contract shape', function (): void {
    $definition = syncedDefinition($this->form, fn (stdClass $d) => $d->pages[0]->elements[0]->label = 'Auftraggeber');

    $result = app(SyncDraft::class)->handle($this->form, Json::encode($definition), $this->base, $this->designer);

    $stored = $this->form->fresh()->draft_definition;
    expect(array_keys($result))->toBe(array_keys(contractShape()))
        ->and(array_keys($result['lint']))->toBe(array_keys(contractShape()['lint']))
        ->and($result['status'])->toBe('saved')
        ->and($stored->pages[0]->elements[0]->label)->toBe('Auftraggeber')
        ->and($result['checksum'])->toBe(Json::checksum($stored))
        ->and($result['savedAt'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/')
        ->and(Activity::query()->where('event', 'form.updated')->count())->toBe(0); // autosaves are not audited
});

it('reports unchanged when the canonical JSON is equal', function (): void {
    $this->travel(5)->minutes();
    $pretty = json_encode(Json::decode(Json::encode($this->form->draft_definition)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $before = $this->form->updated_at;

    $result = app(SyncDraft::class)->handle($this->form, (string) $pretty, $this->base, $this->designer);

    expect($result['status'])->toBe('unchanged')
        ->and($result['checksum'])->toBe($this->base)
        ->and($result['lint']['schema_errors'])->toBe([])
        ->and($this->form->fresh()->updated_at->equalTo($before))->toBeTrue();
});

it('refuses structurally invalid definitions without storing them', function (): void {
    $definition = syncedDefinition($this->form, function (stdClass $d): void {
        unset($d->title);
    });

    $result = app(SyncDraft::class)->handle($this->form, Json::encode($definition), $this->base, $this->designer);

    expect($result['status'])->toBe('invalid')
        ->and($result['lint']['schema_errors'])->not->toBeEmpty()
        ->and($result['checksum'])->toBe($this->base)
        ->and($result['savedAt'])->toBeNull()
        ->and(Json::checksum($this->form->fresh()->draft_definition))->toBe($this->base);
});

it('refuses malformed JSON and JSON that is not an object', function (string $payload): void {
    $result = app(SyncDraft::class)->handle($this->form, $payload, $this->base, $this->designer);

    expect($result['status'])->toBe('invalid')
        ->and($result['lint']['schema_errors'][0]['path'])->toBe('')
        ->and(Json::checksum($this->form->fresh()->draft_definition))->toBe($this->base);
})->with(['malformed' => ['{"pages": ['], 'list' => ['[1, 2]'], 'string' => ['"form"']]);

it('stores lint findings with the draft and returns them', function (): void {
    $definition = syncedDefinition($this->form, fn (stdClass $d) => $d->pages[0]->elements[1]->key = $d->pages[0]->elements[0]->key);

    $result = app(SyncDraft::class)->handle($this->form, Json::encode($definition), $this->base, $this->designer);

    expect($result['status'])->toBe('saved')
        ->and(collect($result['lint']['findings'])->where('code', 'duplicate_key')->first())
        ->toBe(['severity' => 'error', 'code' => 'duplicate_key', 'path' => '/pages/0/elements/1'])
        ->and($this->form->fresh()->draft_definition->pages[0]->elements[1]->key)->toBe($definition->pages[0]->elements[0]->key);
});

it('reports a conflict when the stored draft changed since the base checksum, unless forced', function (): void {
    $definition = syncedDefinition($this->form, fn (stdClass $d) => $d->title = 'Mein Stand');

    $conflict = app(SyncDraft::class)->handle($this->form, Json::encode($definition), 'stale-checksum', $this->designer);
    expect($conflict['status'])->toBe('conflict')
        ->and($conflict['checksum'])->toBe($this->base)
        ->and($conflict['savedAt'])->toBeNull()
        ->and($this->form->fresh()->draft_definition->title)->not->toBe('Mein Stand');

    $forced = app(SyncDraft::class)->handle($this->form, Json::encode($definition), 'stale-checksum', $this->designer, force: true);
    expect($forced['status'])->toBe('saved')
        ->and($this->form->fresh()->draft_definition->title)->toBe('Mein Stand')
        ->and($this->form->fresh()->name)->toBe('Mein Stand');
});

it('audits explicit saves but not autosaves', function (): void {
    $first = syncedDefinition($this->form, fn (stdClass $d) => $d->title = 'Autosave');
    $sync = app(SyncDraft::class)->handle($this->form, Json::encode($first), $this->base, $this->designer);
    $second = syncedDefinition($this->form->fresh(), fn (stdClass $d) => $d->title = 'Explizit');

    app(SyncDraft::class)->handle($this->form, Json::encode($second), $sync['checksum'], $this->designer, audit: true);

    $entries = Activity::query()->where('event', 'form.updated')->get();
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->properties['before']['checksum'])->toBe($sync['checksum']);
});

it('syncs and saves through the canvas page', function (): void {
    actingInAdmin($this->designer, $this->org);
    $definition = syncedDefinition($this->form, fn (stdClass $d) => $d->title = 'Über die Seite');
    $page = Livewire::test(CanvasBuilder::class, ['record' => $this->form->getKey()]);

    $page->call('sync', Json::encode($definition), $this->base);
    $synced = $page->effects['returns'][0];
    $page->call('save', Json::encode(syncedDefinition($this->form->fresh(), fn (stdClass $d) => $d->title = 'Gespeichert')), $synced['checksum']);
    $saved = $page->effects['returns'][0];
    $page->call('sync', Json::encode($definition), 'stale');

    expect($synced['status'])->toBe('saved')
        ->and($saved['status'])->toBe('saved')
        ->and($page->effects['returns'][0]['status'])->toBe('conflict')
        ->and($this->form->fresh()->draft_definition->title)->toBe('Gespeichert')
        ->and(Activity::query()->where('event', 'form.updated')->count())->toBe(1);
});
