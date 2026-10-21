<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Forms\Actions\SaveDraft;
use App\Domain\Forms\Import\Qpdf\QpdfAcroFormReader;
use App\Domain\Forms\Jobs\ImportFillablePdf;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Support\InvalidDefinitionException;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Identity\Tenancy\TenantNotSetException;
use Fieldset\Evaluation\EvaluationContext;
use Fieldset\Evaluation\Evaluator;
use Fieldset\Json\Json;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Unit\Forms\PdfImportFixtures as Fx;

/*
 * BUILD_PROMPT §8.13 acceptance criteria, end to end through the queued job and the real qpdf
 * (scripts/qpdf runs it from Docker on hosts without qpdf).
 */

const PDF_TEST_DISK = 'pdf-import-test';

beforeEach(function (): void {
    Storage::fake(PDF_TEST_DISK);
    $this->org = makeOrganization();
    $this->designer = memberOf($this->org, SystemRole::Designer);
    app(TenantContext::class)->set($this->org);

    $this->upload = function (string $fixture): string {
        $path = 'pdf-imports/'.bin2hex(random_bytes(6)).'.pdf';
        Storage::disk(PDF_TEST_DISK)->put($path, (string) file_get_contents(Fx::path($fixture)));

        return $path;
    };
    $this->import = function (string $fixture, ?string $name = null) {
        $path = ($this->upload)($fixture);
        ImportFillablePdf::dispatchSync($this->designer, $path, $name, $fixture, 'en', PDF_TEST_DISK);

        return $path;
    };
});

it('imports a fillable PDF as an unpublished draft with an import report', function (): void {
    $path = ($this->import)('service-sheet.pdf');

    $form = Form::query()->sole();
    expect($form->name)->toBe('Service sheet')
        ->and($form->status->value)->toBe('draft')
        ->and($form->versions()->count())->toBe(0)
        ->and($form->import_report->file)->toBe('service-sheet.pdf')
        ->and(array_map(fn ($u): array => [$u->field, $u->page, $u->reason], $form->import_report->unmapped))->toBe([
            ['InternalRef', 1, 'hidden'], ['PrintButton', 1, 'push_button'], ['Discount', 2, 'custom_calculation'],
        ])
        ->and($form->import_report->stats->imported)->toBe(49)
        ->and(Activity::query()->where('event', 'form.imported_from_pdf')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'form.created')->count())->toBe(0);
    Storage::disk(PDF_TEST_DISK)->assertMissing($path);

    $notification = $this->designer->notifications()->sole();
    expect($notification->data['title'])->toBe('Draft "Service sheet" imported from PDF')
        ->and($notification->data['body'])->toContain('3 fields were not imported');
});

it('carries over the 8-row table as a repeater without row limit, required flags, tooltips and options', function (): void {
    ($this->import)('service-sheet.pdf', 'Servicebericht');
    $definition = Form::query()->sole()->draft_definition;
    $rows = Fx::element($definition, 'rows');

    expect($rows->type)->toBe('repeater')
        ->and(property_exists($rows, 'maxItems'))->toBeFalse()
        ->and(count($rows->elements))->toBe(4)
        ->and(Fx::element($definition, 'customer_name'))->label->toBe('Customer')->required->toBeTrue()
        ->and(Fx::element($definition, 'service_type'))->required->toBeTrue()->label->toBe('Service type')
        ->and(array_map(fn ($o) => $o->value, Fx::element($definition, 'service_type')->options))->toBe(['Maintenance', 'Repair', 'Installation'])
        ->and($definition->title)->toBe('Servicebericht');
});

it('turns Acrobat built-in calculations into calculated elements with the PDF results', function (): void {
    ($this->import)('service-sheet.pdf');
    $definition = Form::query()->sole()->draft_definition;

    // Acrobat semantics: empty fields count as 0; a row total is qty × price; SUM adds the row totals.
    $result = (new Evaluator)->evaluate($definition, Json::decode(json_encode([
        'customer_name' => 'Test', 'service_type' => 'Repair',
        'rows' => [
            ['desc' => 'Seal', 'qty' => '2', 'price' => '12.50'],
            ['desc' => 'Filter', 'qty' => '3', 'price' => '4.20'],
            ['desc' => 'Labour'],
        ],
        'travel' => '10.00',
    ])), new EvaluationContext);

    expect($result->data->rows[0]->total)->toBe('25.00')
        ->and($result->data->rows[1]->total)->toBe('12.60')
        ->and($result->data->rows[2]->total)->toBe('0.00')
        ->and($result->data->net_total)->toBe('37.60')
        ->and($result->data->avg_price)->toBe('5.57')
        ->and($result->data->max_qty)->toBe('3.00')
        ->and($result->data->grand_total)->toBe('47.60');
});

it('never imports values filled into the PDF', function (): void {
    ($this->import)('service-sheet.pdf');
    $json = Json::encode(Form::query()->sole()->draft_definition);

    foreach (['Erika Mustermann', 'T-0042', 'secret-ref', 'Pump seal', '16.10.2026', 'noisy pump', '25,00'] as $value) {
        expect($json)->not->toContain($value);
    }
});

it('fails with a clear error and leaves no form for unusable PDFs', function (string $fixture, string $message): void {
    $path = ($this->import)($fixture);

    expect(Form::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'form.imported_from_pdf')->count())->toBe(0);
    Storage::disk(PDF_TEST_DISK)->assertMissing($path);
    $notification = $this->designer->notifications()->sole();
    expect($notification->data['title'])->toBe('PDF import failed: '.$fixture)
        ->and($notification->data['body'])->toBe($message);
})->with([
    'malformed' => ['malformed.pdf', 'The file is not a readable PDF.'],
    'over 50 pages' => ['too-many-pages.pdf', 'The PDF has 51 pages; at most 50 pages can be imported.'],
    'user password' => ['encrypted-user.pdf', 'The PDF is encrypted or password protected. Remove the protection and upload it again.'],
    'owner password' => ['encrypted-owner.pdf', 'The PDF is encrypted or password protected. Remove the protection and upload it again.'],
    'no form fields' => ['no-form.pdf', 'The PDF has no fillable form fields that can be imported.'],
]);

it('rejects files over the size limit before parsing', function (): void {
    config(['forms.pdf_import.max_bytes' => 10 * 1024]);
    ($this->import)('service-sheet.pdf');

    expect(Form::query()->count())->toBe(0)
        ->and($this->designer->notifications()->sole()->data['body'])->toBe('The PDF is too large (maximum 10 KB).');
});

it('cannot publish the imported draft until it passes the linter', function (): void {
    ($this->import)('service-sheet.pdf');
    $form = Form::query()->sole();
    $original = Json::decode(Json::encode($form->draft_definition));

    // Deleting a field that a calculation uses (grand_total = net_total + travel) is a lint error.
    $broken = Json::decode(Json::encode($original));
    $broken->pages[1]->elements = array_values(array_filter($broken->pages[1]->elements, fn ($e): bool => $e->key !== 'travel'));
    app(SaveDraft::class)->handle($form, $broken, $this->designer);

    expect(fn () => app(PublishForm::class)->handle($form->refresh(), 'v1', $this->designer))->toThrow(InvalidDefinitionException::class)
        ->and($form->versions()->count())->toBe(0);

    app(SaveDraft::class)->handle($form, $original, $this->designer);
    expect(app(PublishForm::class)->handle($form->refresh(), 'v1', $this->designer)->version)->toBe(1);
});

it('reads the same AcroForm live with qpdf as from the stored fixture dump', function (string $fixture): void {
    $live = app(QpdfAcroFormReader::class)->read(Fx::path($fixture.'.pdf'));
    $stored = Fx::document($fixture);

    expect($live->fields)->toEqual($stored->fields)
        ->and($live->texts)->toEqual($stored->texts)
        ->and($live->pageCount)->toBe($stored->pageCount)
        ->and($live->warnings)->toBe([]);
})->with(['service-sheet', 'hierarchical']);

it('runs the queued import inside the tenant that uploaded the file', function (): void {
    config(['queue.default' => 'database']);
    $tenant = app(TenantContext::class);
    $other = makeOrganization(null, 'Other GmbH');
    $path = ($this->upload)('hierarchical.pdf');
    $tenant->run($this->org, function () use ($path): void {
        ImportFillablePdf::dispatch($this->designer, $path, null, 'hierarchical.pdf', 'en', PDF_TEST_DISK);
    });

    // A fresh worker: no tenant until the job's Context is restored.
    $tenant->clear();
    Context::flush();
    app()->forgetScopedInstances();
    Artisan::call('queue:work', ['connection' => 'database', '--max-jobs' => 1, '--stop-when-empty' => true, '--tries' => 1, '--memory' => 4096]);
    $tenant = app(TenantContext::class);

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and($tenant->run($this->org, fn () => Form::query()->pluck('name')->all()))->toBe(['Delivery note'])
        ->and($tenant->run($other, fn () => Form::query()->count()))->toBe(0)
        ->and($tenant->withoutTenant(fn () => Form::query()->sole()->organization_id))->toBe($this->org->id);
});

it('refuses to run without a tenant', function (): void {
    $path = ($this->upload)('hierarchical.pdf');
    app(TenantContext::class)->clear();

    expect(fn () => ImportFillablePdf::dispatchSync($this->designer, $path, null, null, 'en', PDF_TEST_DISK))->toThrow(TenantNotSetException::class);
    Storage::disk(PDF_TEST_DISK)->assertMissing($path);
});
