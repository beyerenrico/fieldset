<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exports\Writers;

/** A sheet (XLSX) or file (CSV) of an export: its name and header row. */
final readonly class ExportSheet
{
    /** @param  list<string>  $headers */
    public function __construct(public string $name, public array $headers) {}
}
