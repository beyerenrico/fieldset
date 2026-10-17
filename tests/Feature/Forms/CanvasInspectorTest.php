<?php

declare(strict_types=1);

use App\Domain\Forms\Actions\CreateFormFromTemplate;
use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Forms\Actions\SaveDraft;
use App\Domain\Forms\Builder\KeyTypes;
use App\Domain\Forms\Builder\NodeMapper;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Schema\DefinitionTools;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Filament\Admin\Resources\Forms\Pages\CanvasBuilder;
use Filament\Actions\Testing\TestAction;
use FormSchema\Json\Json;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/* Builder v2 inspector (ADR 0037): inspect() loads one node, commitInspector() validates and dispatches it. */

beforeEach(function (): void {
    $this->withoutVite();
    $this->org = makeOrganization();
    $this->designer = memberOf($this->org, SystemRole::Designer);
    actingInAdmin($this->designer, $this->org);
    $this->form = app(CreateFormFromTemplate::class)->handle($this->designer, 'service_report');
});

function contractDefinition(): stdClass
{
    return Json::decode((string) file_get_contents(base_path('tests/Fixtures/builder-v2/contract/definition.json')));
}

function useContractDraft(Form $form, mixed $actor): stdClass
{
    app(SaveDraft::class)->handle($form, contractDefinition(), $actor);

    return $form->refresh()->draft_definition;
}

/** InspectorScope JSON for a definition: all key types, repeater rows, the given scope keys. */
function inspectorScope(stdClass $definition, array $scopeKeys = [], bool $autoKey = false): string
{
    $rowKeys = [];
    foreach (DefinitionTools::elements($definition) as $element) {
        if (($element->type ?? null) === 'repeater') {
            $rowKeys[$element->key] = array_map(fn (stdClass $e): string => $e->key, $element->elements);
        }
    }

    return json_encode(['keyTypes' => KeyTypes::fromDefinition($definition), 'rowKeys' => $rowKeys ?: (object) [],
        'scopeKeys' => $scopeKeys, 'autoKey' => $autoKey]);
}

function canvasPage(Form $form): Testable
{
    return Livewire::test(CanvasBuilder::class, ['record' => $form->getKey()]);
}

/** @return array{kind: string, id: ?string, node: stdClass} the last node-changed event */
function nodeChanged(Testable $page): array
{
    $event = collect($page->effects['dispatches'] ?? [])->firstWhere('name', CanvasBuilder::NODE_CHANGED);
    expect($event)->not->toBeNull('no '.CanvasBuilder::NODE_CHANGED.' event');

    return ['kind' => $event['params']['kind'], 'id' => $event['params']['id'], 'node' => Json::decode($event['params']['node'])];
}

function findElement(stdClass $definition, string $key): stdClass
{
    return collect(DefinitionTools::elements($definition))->firstOrFail(fn (stdClass $e): bool => $e->key === $key);
}

it('commits every node of the service report unchanged through the real fields', function (): void {
    $definition = $this->form->draft_definition;
    $scope = inspectorScope($definition);
    $page = canvasPage($this->form);
    $nodes = [['settings', null, $definition], ...array_map(fn (stdClass $p): array => ['page', $p->id, $p], $definition->pages),
        ...array_map(fn (stdClass $e): array => ['element', $e->id, $e], DefinitionTools::elements($definition))];

    foreach ($nodes as [$kind, $id, $node]) {
        $page->call('inspect', $kind, $id, Json::encode($node), $scope)->call('commitInspector', $scope)->assertHasNoErrors();
        $event = nodeChanged($page);

        expect($event['kind'])->toBe($kind)->and($event['id'])->toBe($id)
            ->and(Json::canonical($event['node']))->toBe(Json::canonical(NodeMapper::withoutChildren($kind, $node)), "{$kind} ".($node->key ?? ''));
    }
});

it('applies an edited label and keeps x- properties (contract fixture)', function (): void {
    $definition = useContractDraft($this->form, $this->designer);
    $customer = findElement($definition, 'customer');
    $scope = inspectorScope($definition, ['customer', 'day', 'items']);
    $expected = json_decode((string) file_get_contents(base_path('tests/Fixtures/builder-v2/contract/node-changed.json')), true);

    $page = canvasPage($this->form)->call('inspect', 'element', $customer->id, Json::encode($customer), $scope)
        ->assertSet('inspectorData.label', 'Kunde')
        ->set('inspectorData.label', 'Kunde (Firma)')
        ->call('commitInspector', $scope)
        ->assertHasNoErrors();

    $event = nodeChanged($page);
    expect($event['kind'])->toBe($expected['kind'])->and($event['id'])->toBe($expected['id'])
        ->and(Json::canonical($event['node']))->toBe(Json::canonical(Json::decode($expected['node'])));
});

it('renders field errors and dispatches nothing while the node is invalid', function (): void {
    $definition = useContractDraft($this->form, $this->designer);
    $customer = findElement($definition, 'customer');
    $scope = inspectorScope($definition);

    canvasPage($this->form)->call('inspect', 'element', $customer->id, Json::encode($customer), $scope)
        ->set('inspectorData.label', '')
        ->set('inspectorData.key', 'Not A Key')
        ->call('commitInspector', $scope)
        ->assertHasErrors(['inspectorData.label', 'inspectorData.key'])
        ->assertNotDispatched(CanvasBuilder::NODE_CHANGED)
        ->assertSee(__('forms.element.label'));
});

it('keeps the key of a published element locked', function (): void {
    $definition = useContractDraft($this->form, $this->designer);
    app(PublishForm::class)->handle($this->form->refresh(), 'v1', $this->designer);
    $customer = findElement($definition, 'customer');
    $scope = inspectorScope($definition);

    $page = canvasPage($this->form)->call('inspect', 'element', $customer->id, Json::encode($customer), $scope)
        ->assertSet('inspectorData.key_locked', true)
        ->set('inspectorData.key', 'renamed')
        ->call('commitInspector', $scope);

    expect(nodeChanged($page)['node']->key)->toBe('customer');
});

const CANVAS_NEW_NODE_JSON = '{"id":"0192f1c4-0000-7000-8000-00000000c001","type":"text","key":"text","label":"Text"}';

/*
 * Key suggestions never write the key field (a request in flight would replace what the user types
 * meanwhile): the page dispatches KEY_SUGGESTED {id, key, from} and the client applies the key only
 * while the field still shows `from`. `->set('inspectorData.key', …)` below plays the client's part.
 */
it('lets the key of a new palette node follow its label until the key is edited', function (): void {
    $definition = useContractDraft($this->form, $this->designer);
    $node = Json::decode(CANVAS_NEW_NODE_JSON);
    $scope = inspectorScope($definition, ['customer', 'day', 'items', 'text'], autoKey: true);

    $page = canvasPage($this->form)->call('inspect', 'element', $node->id, Json::encode($node), $scope)
        ->set('inspectorData.label', 'Customer')->assertSet('inspectorData.key', 'text')
        ->assertDispatched(CanvasBuilder::KEY_SUGGESTED, id: $node->id, key: 'customer_2', from: 'text')
        ->set('inspectorData.key', 'customer_2')
        ->set('inspectorData.label', 'Prüfdatum')->assertSet('inspectorData.key', 'customer_2')
        ->assertDispatched(CanvasBuilder::KEY_SUGGESTED, id: $node->id, key: 'pruefdatum', from: 'customer_2')
        ->set('inspectorData.key', 'pruefdatum')
        ->set('inspectorData.key', 'eigener_key')
        ->set('inspectorData.label', 'Anders')->assertSet('inspectorData.key', 'eigener_key')
        ->assertNotDispatched(CanvasBuilder::KEY_SUGGESTED);

    $page->call('commitInspector', $scope);
    expect(nodeChanged($page)['node']->key)->toBe('eigener_key')
        ->and(nodeChanged($page)['node']->label)->toBe('Anders');
});

it('suggests a key only for a blank key when the node is not new', function (): void {
    $definition = useContractDraft($this->form, $this->designer);
    $customer = findElement($definition, 'customer');
    $scope = inspectorScope($definition, ['customer', 'day', 'items']);

    canvasPage($this->form)->call('inspect', 'element', $customer->id, Json::encode($customer), $scope)
        ->set('inspectorData.label', 'Auftraggeber')->assertSet('inspectorData.key', 'customer')
        ->assertNotDispatched(CanvasBuilder::KEY_SUGGESTED)
        ->set('inspectorData.key', '')
        ->set('inspectorData.label', 'Day')->assertSet('inspectorData.key', '')
        ->assertDispatched(CanvasBuilder::KEY_SUGGESTED, id: $customer->id, key: 'day_2', from: '');
});

it('stops suggesting once the key differs from the last suggestion (typed while the label request ran)', function (): void {
    $definition = useContractDraft($this->form, $this->designer);
    $node = Json::decode(CANVAS_NEW_NODE_JSON);
    $scope = inspectorScope($definition, ['customer', 'day', 'items', 'text'], autoKey: true);

    canvasPage($this->form)->call('inspect', 'element', $node->id, Json::encode($node), $scope)
        ->set('inspectorData.label', 'Kunde')
        ->assertDispatched(CanvasBuilder::KEY_SUGGESTED, id: $node->id, key: 'kunde', from: 'text')
        ->set('inspectorData.key', 'kundennummer') // the client kept what the user typed: no 'kunde'
        ->set('inspectorData.label', 'Kunde (Firma)')->assertSet('inspectorData.key', 'kundennummer')
        ->assertNotDispatched(CanvasBuilder::KEY_SUGGESTED);
});

it('stops following when the client reports an edited key (scope autoKey false)', function (): void {
    $definition = useContractDraft($this->form, $this->designer);
    $node = Json::decode(CANVAS_NEW_NODE_JSON);
    $keys = ['customer', 'day', 'items', 'text'];

    canvasPage($this->form)->call('inspect', 'element', $node->id, Json::encode($node), inspectorScope($definition, $keys, autoKey: true))
        ->call('commitInspector', inspectorScope($definition, $keys, autoKey: false))
        ->set('inspectorData.label', 'Kunde')->assertSet('inspectorData.key', 'text')
        ->assertNotDispatched(CanvasBuilder::KEY_SUGGESTED);
});

it('compiles visual rules on number fields to decimal operators', function (): void {
    $definition = useContractDraft($this->form, $this->designer);
    $description = findElement($definition, 'description');
    $scope = (string) file_get_contents(base_path('tests/Fixtures/builder-v2/contract/scope.json'));

    $page = canvasPage($this->form)->call('inspect', 'element', $description->id, Json::encode($description), $scope)
        ->set('inspectorData.visible_if', ['mode' => 'rules', 'match' => 'all', 'json' => '', 'groups' => [
            ['match' => 'all', 'rules' => [['field' => 'qty', 'operator' => 'gt', 'value' => '5']]],
        ]])
        ->call('commitInspector', $scope)
        ->assertHasNoErrors();

    expect(Json::encode(nodeChanged($page)['node']->visibleIf))->toBe('{"dec.gt":[{"var":"qty"},"5"]}');
});

it('edits the form settings and a page without their children', function (): void {
    $definition = useContractDraft($this->form, $this->designer);
    $scope = inspectorScope($definition);

    $settings = canvasPage($this->form)->call('inspect', 'settings', null, Json::encode($definition), $scope)
        ->set('inspectorData.title', 'Neuer Titel')->call('commitInspector', $scope)->assertHasNoErrors();
    $page = canvasPage($this->form)->call('inspect', 'page', $definition->pages[1]->id, Json::encode($definition->pages[1]), $scope)
        ->set('inspectorData.title', 'Artikel')->call('commitInspector', $scope)->assertHasNoErrors();

    $settingsNode = nodeChanged($settings);
    $pageNode = nodeChanged($page);
    expect($settingsNode['id'])->toBeNull()->and($settingsNode['node']->title)->toBe('Neuer Titel')
        ->and($settingsNode['node']->settings->numbering->format)->toBe('CF-{YYYY}-{SEQ:5}')
        ->and(property_exists($settingsNode['node'], 'pages'))->toBeFalse()
        ->and($pageNode['id'])->toBe($definition->pages[1]->id)->and($pageNode['node']->title)->toBe('Artikel')
        ->and(property_exists($pageNode['node'], 'elements'))->toBeFalse();
});

it('offers a Today preset for date defaults', function (): void {
    $definition = useContractDraft($this->form, $this->designer);
    $day = findElement($definition, 'day');
    $scope = inspectorScope($definition);

    $page = canvasPage($this->form)->call('inspect', 'element', $day->id, Json::encode($day), $scope)
        ->callAction(TestAction::make('today')->schemaComponent('default', schema: 'inspector'))
        ->assertSet('inspectorData.default', '{"var":"$today"}')
        ->call('commitInspector', $scope);

    expect(Json::encode(nodeChanged($page)['node']->default))->toBe('{"var":"$today"}');
});

it('refuses malformed nodes and unknown kinds', function (string $kind, string $node): void {
    canvasPage($this->form)->call('inspect', $kind, null, $node, '{}')->assertHasErrors(['inspector']);
})->with(['bad json' => ['element', '{'], 'list' => ['element', '[]'], 'unknown kind' => ['column', '{}'],
    'unknown type' => ['element', '{"id":"0192f1c4-0000-7000-8000-00000000c001","type":"record","key":"r"}']]);

it('clones a node with fresh ids and unique keys', function (): void {
    $definition = useContractDraft($this->form, $this->designer);
    $details = findElement($definition, 'details');

    $page = canvasPage($this->form)->call('cloneNode', Json::encode($details), json_encode(['customer', 'details', 'cols', 'day', 'items']));
    $clone = Json::decode($page->effects['returns'][0]);

    expect($clone->key)->toBe('details_copy')->and($clone->id)->not->toBe($details->id)
        ->and($clone->elements[0]->key)->toBe('cols_copy')
        ->and($clone->elements[0]->columns[0]->elements[0]->key)->toBe('day_copy');

    $page->call('cloneNode', '{', '[]');
    expect($page->effects['returns'][0])->toBeNull()
        ->and($page->effects['returnsMeta'][0]['errors'])->toHaveKey('node');
});

it('keeps the inspector state small and never in the page config', function (): void {
    app(TenantContext::class)->set($this->org);
    $definition = $this->form->draft_definition;
    $element = findElement($definition, 'items');

    $page = canvasPage($this->form)->call('inspect', 'element', $element->id, Json::encode($element), inspectorScope($definition));

    expect($page->get('inspectorData'))->not->toHaveKey('elements')
        ->and(strlen(json_encode($page->get('inspectorData'))))->toBeLessThan(4000);
});

it('marks the inspector pane with the node its fields belong to', function (): void {
    $definition = useContractDraft($this->form, $this->designer);
    $customer = findElement($definition, 'customer');
    $scope = inspectorScope($definition);

    canvasPage($this->form)->assertSeeHtml('data-builder-inspected=""')
        ->call('inspect', 'element', $customer->id, Json::encode($customer), $scope)
        ->assertSeeHtml('data-builder-inspected="'.$customer->id.'"')
        ->call('inspect', 'page', $definition->pages[1]->id, Json::encode($definition->pages[1]), $scope)
        ->assertSeeHtml('data-builder-inspected="'.$definition->pages[1]->id.'"')
        ->call('inspect', 'settings', null, Json::encode($definition), $scope)
        ->assertSeeHtml('data-builder-inspected="settings"');
});
