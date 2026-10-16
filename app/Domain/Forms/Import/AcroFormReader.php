<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import;

use App\Domain\Forms\Import\Model\AcroFormDocument;

/** Reads the AcroForm field tree of a fillable PDF (ADR 0024). Never executes embedded JavaScript. */
interface AcroFormReader
{
    /**
     * @throws PdfImportException when the file is malformed, encrypted, too large or has no form
     */
    public function read(string $path): AcroFormDocument;
}
