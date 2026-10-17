<?php

declare(strict_types=1);

use App\Domain\Forms\Actions\CreateFormFromTemplate;
use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Forms\Actions\RetireVersion;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Testing\TestResponse;
use Tests\Support\FieldAppScenario;

/*
 * M6 (ADR 0035 "Catalog cache"): retiring a version changes no form row, so the incremental manifest
 * lists retired versions explicitly in `retired_version_ids`.
 */

beforeEach(function (): void {
    $this->s = FieldAppScenario::make();
});

function m6Manifest(?string $since = null): TestResponse
{
    $s = test()->s;
    $query = $since === null ? '' : '?since='.urlencode($since);

    return test()->withHeaders(apiHeaders($s->worker, $s->org))->getJson('/api/v1/sync/manifest'.$query)->assertOk();
}

function m6PublishV2(): FormVersion
{
    $s = test()->s;

    return app(TenantContext::class)->run($s->org, fn (): FormVersion => app(PublishForm::class)->handle($s->form->refresh(), 'v2', $s->owner));
}

function m6Retire(FormVersion $version): void
{
    $s = test()->s;
    app(TenantContext::class)->run($s->org, fn () => app(RetireVersion::class)->handle($version, $s->owner));
}

it('reports an older version retired after the cursor', function (): void {
    $v2 = m6PublishV2();
    $this->travel(5)->seconds();
    $first = m6Manifest();
    expect($first->json('data.retired_version_ids'))->toBe([]);

    $this->travel(5)->seconds();
    m6Retire($this->s->version);

    $next = m6Manifest($first->json('data.cursor'));
    expect($next->json('data.retired_version_ids'))->toBe([$this->s->version->id])
        ->and($next->json('data.fillable_form_ids'))->toBe([$this->s->form->id])
        ->and($next->json('data.forms'))->toBe([])
        ->and($first->json('data.forms.0.version.id'))->toBe($v2->id);
});

it('lists every retired version of a fillable form in the full manifest and none from before the cursor', function (): void {
    m6PublishV2();
    m6Retire($this->s->version);
    $this->travel(5)->seconds();

    expect(m6Manifest()->json('data.retired_version_ids'))->toBe([$this->s->version->id]);
    $later = m6Manifest()->json('data.cursor');
    $this->travel(5)->seconds();
    expect(m6Manifest($later)->json('data.retired_version_ids'))->toBe([]);
});

it('drops a form whose current version was retired from fillable_form_ids', function (): void {
    $this->travel(5)->seconds();
    $first = m6Manifest();

    $this->travel(5)->seconds();
    m6Retire($this->s->version);

    $next = m6Manifest($first->json('data.cursor'));
    expect($next->json('data.fillable_form_ids'))->toBe([])
        ->and($next->json('data.retired_version_ids'))->toBe([]);
});

it('does not report retired versions of forms the user may not fill', function (): void {
    $s = $this->s;
    $other = app(TenantContext::class)->run($s->org, function () use ($s): array {
        $form = app(CreateFormFromTemplate::class)->handle($s->owner, 'invoice');
        $v1 = app(PublishForm::class)->handle($form, 'v1', $s->owner);
        app(PublishForm::class)->handle($form->refresh(), 'v2', $s->owner);

        return [$form, $v1];
    });
    m6Retire($other[1]);

    expect(m6Manifest()->json('data.retired_version_ids'))->toBe([]);
});
