<?php

declare(strict_types=1);

use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Tenancy\TenantContext;
use FormSchema\Json\Json;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FieldAppApi;
use Tests\Support\FieldAppScenario;

beforeEach(function (): void {
    Storage::fake('local');
    $this->s = FieldAppScenario::make();
});

function submitReady(?int $versionId = null): string
{
    $s = test()->s;
    $uuid = FieldAppApi::readyDraft($s, $s->worker, $versionId);

    return (string) FieldAppApi::submit($s, $s->worker, $uuid, 1)->assertOk()->json('data.submission.number');
}

function publishWithNumbering(string $format, string $reset): FormVersion
{
    $s = test()->s;
    $definition = Json::copy($s->version->definition);
    $definition->settings->numbering = (object) ['format' => $format, 'reset' => $reset];

    return app(TenantContext::class)->run($s->org, fn (): FormVersion => app(PublishForm::class)
        ->handle($s->form, "numbering {$format} {$reset}", $s->owner, $definition));
}

it('never issues an existing number when the reset rule changes between versions', function (): void {
    $year = now('Europe/Berlin')->format('Y');
    expect(submitReady())->toBe("SR-{$year}-00001");                       // v1: yearly sequence

    $v2 = publishWithNumbering('SR-{YYYY}-{SEQ:5}', 'never');               // own sequence ("all") starting at 1
    expect(submitReady($v2->id))->toBe("SR-{$year}-00002")
        ->and(submitReady($this->s->version->id))->toBe("SR-{$year}-00003")  // v1 again: skips v2's number
        ->and(submitReady($v2->id))->toBe("SR-{$year}-00004");
});

it('continues the period counter when the format changes between versions', function (): void {
    $v2 = publishWithNumbering('{SEQ:1}', 'never');
    $v3 = publishWithNumbering('{SEQ:2}', 'never');

    $numbers = [submitReady($v2->id), submitReady($v2->id), submitReady($v3->id), submitReady($v2->id)];

    expect($numbers)->toBe(['1', '2', '03', '4']);
});

it('derives the numbering period in the organization time zone', function (): void {
    $this->s->org->forceFill(['timezone' => 'Europe/Berlin'])->save();
    $this->travelTo(Carbon::parse('2026-12-31T23:30:00Z'));

    expect(submitReady())->toBe('SR-2027-00001');
});
