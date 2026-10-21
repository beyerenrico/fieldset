<?php

declare(strict_types=1);

use App\Domain\Forms\Actions\CreateFormFromTemplate;
use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Forms\Support\DefinitionChecker;
use App\Domain\Forms\Templates\TemplateCatalog;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Support\SubmissionEvaluator;
use Fieldset\Evaluation\EvaluationResult;

/* M3-T10: delivery note, timesheet and handover protocol (BUILD_PROMPT §7.6). */

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->designer = memberOf($this->org, SystemRole::Designer);
    app(TenantContext::class)->set($this->org);
});

function publishTemplate(string $key): FormVersion
{
    $form = app(CreateFormFromTemplate::class)->handle(test()->designer, $key);

    return app(PublishForm::class)->handle($form, 'from template', test()->designer);
}

/** @param  array<string, mixed>  $data */
function evaluateTemplate(FormVersion $version, array $data, User $user, Organization $org): EvaluationResult
{
    return app(SubmissionEvaluator::class)->evaluate($version, json_decode((string) json_encode($data)), $user, $org);
}

it('lists the three new templates with German and English titles', function (): void {
    $de = collect(TemplateCatalog::bundled()->all('de'))->keyBy('key');
    $en = collect(TemplateCatalog::bundled()->all('en'))->keyBy('key');

    expect($de['delivery_note']['title'])->toBe('Lieferschein')->and($en['delivery_note']['title'])->toBe('Delivery note')
        ->and($de['timesheet']['title'])->toBe('Stundenzettel')->and($en['timesheet']['title'])->toBe('Timesheet')
        ->and($de['handover_protocol']['title'])->toBe('Übergabeprotokoll')->and($en['handover_protocol']['title'])->toBe('Handover protocol');
});

it('ships them without lint warnings, with numbering and customer sending', function (string $key, string $format): void {
    $definition = TemplateCatalog::bundled()->definition($key);
    $report = app(DefinitionChecker::class)->check($definition);

    expect($report->warnings())->toBe([])
        ->and($definition->settings->numbering->format)->toBe($format)
        ->and($definition->settings->sendToCustomer)->toBeTrue()
        ->and($definition->locale)->toBe('de-DE');
})->with([
    ['delivery_note', 'LS-{YYYY}-{SEQ:5}'],
    ['timesheet', 'SZ-{YYYY}-{SEQ:5}'],
    ['handover_protocol', 'UP-{YYYY}-{SEQ:5}'],
]);

it('publishes each new template as version 1 with lineage', function (string $key): void {
    $version = publishTemplate($key);

    expect($version->version)->toBe(1)
        ->and($version->definition->source->template)->toBe($key)
        ->and($version->definition->source->registry)->toBe('urn:form-schema-app:bundled-templates');
})->with(['delivery_note', 'timesheet', 'handover_protocol']);

it('computes timesheet hours per row from start, end and break, and their sum', function (): void {
    $version = publishTemplate('timesheet');

    $result = evaluateTemplate($version, ['entries' => [
        ['work_date' => '2026-10-12', 'start_time' => '07:30', 'end_time' => '16:15', 'break_minutes' => '45'],
        ['work_date' => '2026-10-13', 'start_time' => '08:00:00', 'end_time' => '12:20'],
        ['work_date' => '2026-10-14', 'start_time' => '08:00'],
    ]], $this->designer, $this->org);

    expect($result->data->entries[0]->hours)->toBe('8.00')
        ->and($result->data->entries[1]->break_minutes)->toBe('0')
        ->and($result->data->entries[1]->hours)->toBe('4.33')
        ->and($result->data->entries[2]->hours)->toBeNull()
        ->and($result->data->total_hours)->toBe('12.33');
});

it('never lets a timesheet row or the total go negative (end before start, long break)', function (): void {
    $version = publishTemplate('timesheet');

    $result = evaluateTemplate($version, ['entries' => [
        ['work_date' => '2026-10-12', 'start_time' => '16:00', 'end_time' => '08:00'],
        ['work_date' => '2026-10-13', 'start_time' => '08:00', 'end_time' => '09:00', 'break_minutes' => '90'],
        ['work_date' => '2026-10-14', 'start_time' => '08:00', 'end_time' => '10:30'],
        ['work_date' => '2026-10-15', 'start_time' => '08:00'],
    ]], $this->designer, $this->org);

    expect($result->data->entries[0]->hours)->toBe('0.00')
        ->and($result->data->entries[1]->hours)->toBe('0.00')
        ->and($result->data->entries[2]->hours)->toBe('2.50')
        ->and($result->data->entries[3]->hours)->toBeNull()
        ->and($result->data->total_hours)->toBe('2.50');
});

it('totals delivered quantities and the open quantity per delivery-note line', function (): void {
    $version = publishTemplate('delivery_note');

    $result = evaluateTemplate($version, ['items' => [
        ['description' => 'Kabel NYM 3x1,5', 'qty_ordered' => '100', 'qty' => '80', 'unit' => 'm'],
        ['description' => 'Dübel', 'qty' => '2.5', 'unit' => 'pkg'],
    ], 'receipt_status' => 'partial'], $this->designer, $this->org);

    expect($result->data->items[0]->qty_open)->toBe('20.00')
        ->and($result->data->items[1]->qty_open)->toBeNull()
        ->and($result->data->total_qty)->toBe('82.50')
        ->and($result->data->position_count)->toBe('2')
        ->and(collect($result->errors)->pluck('pointer')->all())->toContain('/receipt_notes');
});

it('counts handover defects and keys', function (): void {
    $version = publishTemplate('handover_protocol');

    $result = evaluateTemplate($version, [
        'object_type' => 'apartment',
        'areas' => [
            ['area' => 'Küche', 'condition' => 'good'],
            ['area' => 'Bad', 'condition' => 'defect', 'defect_note' => 'Fliese gesprungen'],
            ['area' => 'Flur', 'condition' => 'defect'],
        ],
        'keys' => [['key_label' => 'Haustür', 'key_count' => '2'], ['key_label' => 'Briefkasten', 'key_count' => '1']],
    ], $this->designer, $this->org);

    expect($result->data->defect_count)->toBe('2')
        ->and($result->data->keys_total)->toBe('3')
        ->and(property_exists($result->data, 'asset_serial'))->toBeFalse()
        ->and(collect($result->errors)->pluck('pointer')->all())->toContain('/areas/2/defect_note');
});
