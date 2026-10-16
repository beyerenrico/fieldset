<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import;

use App\Domain\Forms\Import\Model\AcroFormDocument;
use App\Domain\Forms\Import\Qpdf\QpdfAcroFormReader;
use Illuminate\Container\Attributes\Bind;

/** Reads the AcroForm field tree of a fillable PDF (ADR 0024). Never executes embedded JavaScript. */
#[Bind(QpdfAcroFormReader::class)]
interface AcroFormReader
{
    /**
     * @throws PdfImportException when the file is malformed, encrypted, too large or has no form
     */
    public function read(string $path): AcroFormDocument;
}
