<?php

declare(strict_types=1);

use App\Domain\Forms\Jobs\ImportFillablePdf;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Enums\SystemRole;
use App\Filament\Admin\Resources\Forms\Pages\EditForm;
use App\Filament\Admin\Resources\Forms\Pages\ListForms;
use FormSchema\Json\Json;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Unit\Forms\PdfImportFixtures as Fx;

const PDF_UI_DISK = 'pdf-import-ui-test';

beforeEach(function (): void {
    Storage::fake(PDF_UI_DISK);
    config(['forms.pdf_import.disk' => PDF_UI_DISK]);
    $this->org = makeOrganization();
    $this->designer = memberOf($this->org, SystemRole::Designer);
    actingInAdmin($this->designer, $this->org);

    $this->importedForm = function (): Form {
        $path = 'pdf-imports/service-sheet.pdf';
        Storage::disk(PDF_UI_DISK)->put($path, (string) file_get_contents(Fx::path('service-sheet.pdf')));
        ImportFillablePdf::dispatchSync($this->designer, $path, null, 'service-sheet.pdf', 'en', PDF_UI_DISK);

        return Form::query()->sole();
    };
});

it('queues the import from the "Import from PDF" action with the private upload', function (): void {
    Queue::fake();
    $file = UploadedFile::fake()->createWithContent('service-sheet.pdf', (string) file_get_contents(Fx::path('service-sheet.pdf')));

    Livewire::test(ListForms::class)
        ->callAction('importPdf', ['file' => $file, 'name' => 'Wartung'])
        ->assertHasNoActionErrors()
        ->assertNotified(__('forms.import.queued'));

    Queue::assertPushed(ImportFillablePdf::class, function (ImportFillablePdf $job): bool {
        Storage::disk(PDF_UI_DISK)->assertExists($job->path);

        return $job->name === 'Wartung' && $job->fileName === 'service-sheet.pdf' && $job->disk === PDF_UI_DISK
            && str_starts_with($job->path, 'pdf-imports/') && $job->user->is($this->designer) && $job->locale === 'en';
    });
});

it('imports end to end from the action when the queue runs synchronously', function (): void {
    $file = UploadedFile::fake()->createWithContent('service-sheet.pdf', (string) file_get_contents(Fx::path('service-sheet.pdf')));

    Livewire::test(ListForms::class)->callAction('importPdf', ['file' => $file])->assertHasNoActionErrors();

    expect(Form::query()->sole()->name)->toBe('Service sheet')
        ->and(Storage::disk(PDF_UI_DISK)->allFiles())->toBe([])
        ->and($this->designer->notifications()->count())->toBe(1);
});

it('accepts only PDF files', function (): void {
    Livewire::test(ListForms::class)
        ->callAction('importPdf', ['file' => UploadedFile::fake()->createWithContent('form.json', '{"a":1}')])
        ->assertHasActionErrors(['file']);

    expect(Form::query()->count())->toBe(0);
});

it('shows the import report on the edit page until it is dismissed', function (): void {
    $form = ($this->importedForm)();

    Livewire::test(EditForm::class, ['record' => $form->getRouteKey()])
        ->assertSee(__('forms.import.report.title'))
        ->assertSee('Page 1 · PrintButton — not imported: push button')
        ->assertSee('Page 2 · Discount — not imported: custom JavaScript calculation')
        ->assertSee('49 of 52 fields imported')
        ->callAction('dismissImportReport')
        ->assertNotified(__('forms.import.report.dismissed'))
        ->assertDontSee(__('forms.import.report.title'));

    expect($form->refresh()->import_report)->toBeNull();
});

it('marks low-confidence imported elements in the builder', function (): void {
    $form = ($this->importedForm)();

    Livewire::test(EditForm::class, ['record' => $form->getRouteKey()])
        ->assertSee('Postcode (postcode) ⚠ check')
        ->assertDontSee('Customer (customer_name) ⚠');
});

it('keeps x-import provenance when the draft is saved from the builder', function (): void {
    $form = ($this->importedForm)();

    Livewire::test(EditForm::class, ['record' => $form->getRouteKey()])->call('save')->assertHasNoFormErrors();

    expect(Fx::element($form->refresh()->draft_definition, 'postcode')->{'x-import'}->field)->toBe('postcode')
        ->and(Fx::element($form->draft_definition, 'rows')->{'x-import'}->field)->toBe('Row{n}*');
});

it('escapes PDF field names shown in the report', function (): void {
    $form = ($this->importedForm)();
    $form->forceFill(['import_report' => Json::decode('{"file":"x.pdf","unmapped":[{"field":"<script>alert(1)</script>","page":1,"reason":"hidden"}],"warnings":[],"stats":{}}')])->save();

    Livewire::test(EditForm::class, ['record' => $form->getRouteKey()])
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});
