<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import;

use stdClass;

/** A draft definition built from a fillable PDF, and its import report. */
final readonly class PdfImportResult
{
    public function __construct(public stdClass $definition, public ImportReport $report) {}
}
