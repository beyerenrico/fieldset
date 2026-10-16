<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import;

use App\Domain\Forms\Actions\CreateForm;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use FormSchema\Json\Json;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\DB;

/**
 * Imports a fillable PDF as a new draft form with an import report (BUILD_PROMPT §8.13). The draft
 * passes the same meta-schema check as any other (CreateForm) and is never published here. Any
 * failure leaves no form behind; the audit log gets one `form.imported_from_pdf` entry.
 */
final readonly class ImportPdfForm
{
    public function __construct(
        private AcroFormReader $reader,
        private CreateForm $create,
        private TenantContext $tenant,
        #[Config('forms.pdf_import.low_confidence', 0.6)] private float $lowConfidence = 0.6,
    ) {}

    /**
     * @param  string  $path  absolute path of the uploaded PDF
     * @param  string|null  $name  form name chosen by the user
     * @param  string|null  $fileName  original file name, for the default name and the report
     * @return array{0: Form, 1: ImportReport}
     */
    public function handle(User $actor, string $path, ?string $name = null, ?string $fileName = null): array
    {
        $this->tenant->require();
        $document = $this->reader->read($path);
        $title = $this->title($name, $document->title, $fileName);
        $locale = $this->tenant->organization()?->locale === 'en' ? 'en-GB' : 'de-DE';

        $result = new PdfDefinitionBuilder(lowConfidence: $this->lowConfidence)->build($document, $title, $locale);
        if ($result->definition->pages === []) {
            throw new PdfImportException('no_fields');
        }
        $report = $result->report->withFile($fileName ?? basename($path));

        return DB::transaction(function () use ($actor, $title, $result, $report): array {
            [$form] = $this->create->handle($actor, $title, $result->definition, null, 'form.imported_from_pdf');
            $form->forceFill(['import_report' => Json::decode(Json::encode($report->toArray()))])->save();

            return [$form, $report];
        });
    }

    private function title(?string $name, ?string $pdfTitle, ?string $fileName): string
    {
        $fromFile = $fileName === null ? '' : trim((string) preg_replace('/[\s_\-]+/u', ' ', pathinfo($fileName, PATHINFO_FILENAME)));
        foreach ([$name, $pdfTitle, $fromFile !== '' ? mb_strtoupper(mb_substr($fromFile, 0, 1)).mb_substr($fromFile, 1) : null] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return mb_substr($candidate, 0, 200);
            }
        }

        return (string) __('forms.import.untitled');
    }
}
