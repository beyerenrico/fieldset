<?php

declare(strict_types=1);

namespace App\Domain\Reports\Enums;

/** Export kinds of the submission explorer (BUILD_PROMPT §8.4, ADR 0033). */
enum ExportFormat: string
{
    /** Sheet 1 submissions, one more sheet per repeater (rows linked by number and row). */
    case Xlsx = 'xlsx';
    /** submissions.csv; with repeaters a ZIP with one more CSV per repeater. */
    case Csv = 'csv';
    /** The latest rendition (PDF) of every submission in a ZIP, size-capped. */
    case PdfZip = 'pdf_zip';

    public function label(): string
    {
        return __('reports.export.format.'.$this->value);
    }
}
