<?php

declare(strict_types=1);

use App\Domain\Forms\Actions\CreateFormFromTemplate;
use App\Domain\Forms\Actions\ExportDefinition;
use App\Domain\Forms\Actions\ImportDefinition;
use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Support\DefinitionChecker;
use App\Domain\Forms\Templates\TemplateCatalog;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use FormSchema\Json\Json;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->designer = memberOf($this->org, SystemRole::Designer);
    app(TenantContext::class)->set($this->org);
});

// Datasets resolve before the app boots, so no base_path() here.
dataset('templates', fn (): array => array_column(new TemplateCatalog(dirname(__DIR__, 3).'/spec/templates')->all(), 'key'));

it('ships templates that pass the meta-schema and the linter without errors', function (string $key): void {
    $report = app(DefinitionChecker::class)->check(TemplateCatalog::bundled()->definition($key));

    expect($report->schemaErrors)->toBe([])->and($report->errors())->toBe([]);
})->with('templates');

it('creates a form from a template with lineage and publishes it', function (string $key): void {
    $form = app(CreateFormFromTemplate::class)->handle($this->designer, $key);

    expect($form->draft_definition->source->template)->toBe($key)
        ->and($form->draft_definition->source->version)->toBe('1.0.0');

    $version = app(PublishForm::class)->handle($form, 'from template', $this->designer);
    expect($version->version)->toBe(1);
})->with('templates');

it('picks the next free key when a concurrent request takes the computed key first', function (): void {
    // Simulates another designer creating the same template between our key lookup and our insert. The
    // simulated row lives in our savepoint and is rolled back with it, so the retry may reuse the key.
    $raced = false;
    Form::creating(function (Form $form) use (&$raced): void {
        if (! $raced && $form->key === 'service_report_2') {
            $raced = true;
            DB::table('forms')->insert([
                'organization_id' => $this->org->id, 'key' => 'service_report_2', 'name' => 'Other', 'status' => 'draft',
                'draft_definition' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });
    app(CreateFormFromTemplate::class)->handle($this->designer, 'service_report');

    $form = app(CreateFormFromTemplate::class)->handle($this->designer, 'service_report');

    expect($raced)->toBeTrue()->and($form->exists)->toBeTrue()
        ->and($form->draft_definition->key)->toBe($form->key)
        ->and(Form::query()->where('key', 'like', 'service_report%')->count())->toBe(2);
});

it('includes the required templates', function (): void {
    expect(array_column(TemplateCatalog::bundled()->all(), 'key'))->toContain('service_report', 'invoice', 'inspection_checklist');
});

it('exports and re-imports a definition losslessly', function (): void {
    $form = app(CreateFormFromTemplate::class)->handle($this->designer, 'service_report');
    $version = app(PublishForm::class)->handle($form, 'v1', $this->designer);

    $json = app(ExportDefinition::class)->handle($form, $version, $this->designer);
    [$copy, $report] = app(ImportDefinition::class)->handle($this->designer, $json, 'Imported copy');

    $original = Json::decode(Json::encode($version->definition));
    $imported = Json::decode(Json::encode($copy->draft_definition));
    unset($original->key, $original->title, $imported->key, $imported->title);
    expect(Json::canonical($imported))->toBe(Json::canonical($original))
        ->and($report->hasErrors())->toBeFalse()
        ->and(Json::decode($json)->design)->toBe('default');
});

it('rejects files that are not definitions', function (string $json): void {
    expect(fn () => app(ImportDefinition::class)->handle($this->designer, $json))->toThrow(InvalidArgumentException::class);
})->with(['not json' => ['{oops'], 'array' => ['[1,2]'], 'too large' => [str_repeat(' ', ImportDefinition::MAX_BYTES + 1)]]);
