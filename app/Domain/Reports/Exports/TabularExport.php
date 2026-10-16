<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exports;

use App\Domain\Reports\Enums\ExportFormat;
use App\Domain\Reports\Exports\Writers\CsvExportWriter;
use App\Domain\Reports\Exports\Writers\ExportWriter;
use App\Domain\Reports\Exports\Writers\XlsxExportWriter;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Reports\Schema\FieldCatalog;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Database\Eloquent\Builder;

/** Writes the XLSX or CSV export chunk by chunk and keeps the export's progress counters current. */
final readonly class TabularExport
{
    public function __construct(private ExportRowReader $reader) {}

    /** @param  Builder<Submission>  $query */
    public function write(ReportExport $export, Builder $query, FieldCatalog $catalog, ExportOptions $options, string $directory): ExportFile
    {
        $layout = ExportLayout::for($catalog, $options->timezone);
        $writer = $this->writer($export->format);
        $writer->open($directory, $layout->main(), $layout->repeaterSheets());

        $rows = 0;
        foreach ($this->reader->chunks($query, max(1, (int) config('reports.exports.chunk_size', 500))) as $records) {
            foreach ($records as $record) {
                $writer->submission($layout->submissionCells($record));
                foreach ($layout->repeaterKeys() as $key) {
                    foreach ($layout->repeaterRows($record, $key) as $line) {
                        $writer->repeaterRow($key, $line);
                    }
                }
            }
            $rows += count($records);
            $export->forceFill(['processed_rows' => $rows, 'successful_rows' => $rows])->save();
        }
        $file = $writer->close();

        return new ExportFile($file->path, $file->extension, $rows, $rows);
    }

    private function writer(ExportFormat $format): ExportWriter
    {
        return $format === ExportFormat::Csv ? new CsvExportWriter : new XlsxExportWriter;
    }
}
