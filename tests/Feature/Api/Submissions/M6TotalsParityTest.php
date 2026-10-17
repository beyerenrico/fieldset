<?php

declare(strict_types=1);

use App\Domain\Forms\Templates\TemplateCatalog;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Models\Submission;
use FormSchema\Json\Json;
use FormSchema\Json\JsonPointer;
use Tests\Support\FieldAppApi;
use Tests\Support\FieldAppScenario;

/*
 * M6-T09 totals parity (§9.2 AC): the totals the server stores for the 30-line service report equal the
 * conformance case that the JS runner (the device's evaluator) also passes.
 */

function m6TotalsCase(): stdClass
{
    $case = Json::decode((string) file_get_contents(base_path('spec/conformance/evaluate/service-report-totals-30-lines.json')));
    assert($case instanceof stdClass);

    return $case;
}

beforeEach(function (): void {
    $this->s = FieldAppScenario::make();
});

it('runs the conformance case on the bundled service report template', function (): void {
    $template = TemplateCatalog::bundled()->definition('service_report');
    unset($template->source); // lineage added on import (D12), not part of the template file

    expect(Json::canonical(m6TotalsCase()->definition))->toBe(Json::canonical($template))
        ->and(m6TotalsCase()->tests[0]->data->items)->toHaveCount(30);
});

it('stores the totals of the conformance case for a submission made through the API', function (): void {
    $case = m6TotalsCase()->tests[0];
    /** @var array<string, mixed> $data */
    $data = json_decode((string) json_encode($case->data), true);
    $signature = FieldAppScenario::uuid();
    $data['customer_signature']['attachment'] = $signature;
    $uuid = FieldAppScenario::uuid();

    FieldAppApi::put($this->s, $this->s->worker, $uuid, $data)->assertCreated();
    FieldAppApi::signature($this->s, $this->s->worker, $uuid, $signature)->assertCreated();
    $response = FieldAppApi::submit($this->s, $this->s->worker, $uuid, 1)->assertOk()->assertJsonPath('data.submission.status', 'submitted');

    $stored = app(TenantContext::class)->run($this->s->org, fn (): Submission => Submission::query()->findOrFail($uuid));
    $returned = Json::decode((string) json_encode($response->json('data.submission.data')));
    foreach (get_object_vars($case->expected->calculated) as $pointer => $expected) {
        [$found, $actual] = JsonPointer::get($stored->data, (string) $pointer);
        [, $sent] = JsonPointer::get($returned, (string) $pointer);
        expect($found)->toBeTrue("stored {$pointer}")
            ->and($actual)->toBe($expected, "stored {$pointer}")
            ->and($sent)->toBe($expected, "returned {$pointer}");
    }
    expect(get_object_vars($case->expected->calculated))->toHaveCount(33);
});
