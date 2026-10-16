<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Forms\Actions\CreateForm;
use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Forms\Actions\RetireVersion;
use App\Domain\Forms\Actions\RollbackForm;
use App\Domain\Forms\Actions\SaveDraft;
use App\Domain\Forms\Enums\FormStatus;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Forms\Support\InvalidDefinitionException;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use FormSchema\Json\Json;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->designer = memberOf($this->org, SystemRole::Designer);
    app(TenantContext::class)->set($this->org);
});

function textElement(string $key, string $label, array $extra = []): stdClass
{
    return (object) ['id' => (string) Str::uuid7(), 'type' => 'text', 'key' => $key, 'label' => $label, ...$extra];
}

function draftWith(Form $form, stdClass ...$elements): stdClass
{
    $definition = Json::decode(Json::encode($form->draft_definition));
    $definition->pages[0]->elements = array_values($elements);

    return $definition;
}

it('creates a blank draft form with a unique key', function (): void {
    [$a] = app(CreateForm::class)->handle($this->designer, 'Prüfbericht Aufzug');
    [$b] = app(CreateForm::class)->handle($this->designer, 'Prüfbericht Aufzug');

    expect($a->key)->toBe('prufbericht_aufzug')->and($b->key)->toBe('prufbericht_aufzug_2')
        ->and($a->status)->toBe(FormStatus::Draft)
        ->and($a->draft_definition->specVersion)->toBe('1.0.0');
});

it('publishes version 1 as an immutable snapshot with checksum and data contract', function (): void {
    [$form] = app(CreateForm::class)->handle($this->designer, 'Service');
    app(SaveDraft::class)->handle($form, draftWith($form, textElement('customer', 'Kunde', ['required' => true])), $this->designer);

    $version = app(PublishForm::class)->handle($form->refresh(), 'First release', $this->designer);

    expect($version->version)->toBe(1)
        ->and($version->checksum)->toBe(Json::checksum($version->definition))
        ->and($version->data_json_schema->type)->toBe('object')
        ->and($form->refresh()->status)->toBe(FormStatus::Active)
        ->and($form->current_version_id)->toBe($version->id)
        ->and(Activity::query()->where('event', 'form.published')->count())->toBe(1);
});

it('never publishes a definition with lint errors', function (): void {
    [$form] = app(CreateForm::class)->handle($this->designer, 'Broken');
    app(SaveDraft::class)->handle($form, draftWith($form, textElement('a', 'A'), textElement('a', 'Duplicate')), $this->designer);

    expect(fn () => app(PublishForm::class)->handle($form->refresh(), 'x', $this->designer))->toThrow(InvalidDefinitionException::class)
        ->and(FormVersion::query()->count())->toBe(0);
});

it('requires a changelog note', function (): void {
    [$form] = app(CreateForm::class)->handle($this->designer, 'Form');

    expect(fn () => app(PublishForm::class)->handle($form, '  ', $this->designer))->toThrow(InvalidArgumentException::class);
});

it('refuses structurally invalid drafts', function (): void {
    [$form] = app(CreateForm::class)->handle($this->designer, 'Form');
    $bad = draftWith($form, (object) ['id' => 'nope', 'type' => 'record', 'key' => 'r', 'label' => 'R']);

    expect(fn () => app(SaveDraft::class)->handle($form, $bad, $this->designer))->toThrow(InvalidDefinitionException::class);
});

it('rolls back by publishing a copy of the old version as N+1', function (): void {
    [$form] = app(CreateForm::class)->handle($this->designer, 'Form');
    app(SaveDraft::class)->handle($form, draftWith($form, textElement('a', 'Version one')), $this->designer);
    $v1 = app(PublishForm::class)->handle($form->refresh(), 'v1', $this->designer);
    app(SaveDraft::class)->handle($form->refresh(), draftWith($form, textElement('a', 'Version two')), $this->designer);
    app(PublishForm::class)->handle($form->refresh(), 'v2', $this->designer);

    $v3 = app(RollbackForm::class)->handle($form->refresh(), $v1, $this->designer);

    expect($v3->version)->toBe(3)
        ->and($v3->checksum)->toBe($v1->checksum)
        ->and($form->refresh()->draft_definition->pages[0]->elements[0]->label)->toBe('Version one')
        ->and(FormVersion::query()->count())->toBe(3);
});

it('keeps published versions immutable in the model and in the database', function (): void {
    [$form] = app(CreateForm::class)->handle($this->designer, 'Form');
    $version = app(PublishForm::class)->handle($form, 'v1', $this->designer);

    expect(fn () => $version->update(['changelog' => 'rewritten']))->toThrow(LogicException::class)
        ->and(fn () => $version->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::table('form_versions')->where('id', $version->id)->update(['changelog' => 'sneaky']))
        ->toThrow(QueryException::class, 'immutable')
        ->and(fn () => DB::table('form_versions')->where('id', $version->id)->delete())
        ->toThrow(QueryException::class, 'immutable');
});

it('allows exactly one update: retiring', function (): void {
    [$form] = app(CreateForm::class)->handle($this->designer, 'Form');
    $version = app(PublishForm::class)->handle($form, 'v1', $this->designer);

    app(RetireVersion::class)->handle($version, $this->designer);
    expect($version->refresh()->isRetired())->toBeTrue()
        ->and(fn () => DB::table('form_versions')->where('id', $version->id)->update(['retired_at' => now()->addDay()]))
        ->toThrow(QueryException::class);
});

it('rejects renaming a published key and changing its type', function (): void {
    [$form] = app(CreateForm::class)->handle($this->designer, 'Form');
    $element = textElement('customer', 'Kunde');
    app(SaveDraft::class)->handle($form, draftWith($form, $element), $this->designer);
    app(PublishForm::class)->handle($form->refresh(), 'v1', $this->designer);

    $renamed = (object) [...(array) $element, 'key' => 'client'];
    $report = app(SaveDraft::class)->handle($form->refresh(), draftWith($form, $renamed), $this->designer);

    expect(collect($report->errors())->pluck('code')->all())->toContain('key_locked')
        ->and(fn () => app(PublishForm::class)->handle($form->refresh(), 'v2', $this->designer))->toThrow(InvalidDefinitionException::class);
});

it('serializes version numbers per form', function (): void {
    [$form] = app(CreateForm::class)->handle($this->designer, 'Form');
    app(PublishForm::class)->handle($form, 'v1', $this->designer);
    app(PublishForm::class)->handle($form->refresh(), 'v2', $this->designer);

    expect($form->versions()->pluck('version')->all())->toBe([1, 2]);
});
