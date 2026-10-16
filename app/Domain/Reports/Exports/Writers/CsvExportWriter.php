<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exports\Writers;

use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer;
use RuntimeException;
use ZipArchive;

/**
 * CSV via openspout (UTF-8 with BOM, comma, RFC 4180 quoting). Without repeaters the export is one
 * `submissions.csv`; with repeaters a ZIP holds it plus one CSV per repeater (ADR 0033). Text that a
 * spreadsheet would run as a formula (= + - @ tab CR, not a number) is prefixed with an apostrophe.
 */
final class CsvExportWriter implements ExportWriter
{
    private string $directory;

    private Writer $main;

    /** @var array<string, Writer> */
    private array $writers = [];

    /** @var array<string, string> file name => local path */
    private array $files = [];

    public function open(string $directory, ExportSheet $main, array $repeaters): void
    {
        $this->directory = $directory;
        $names = new SheetNames;
        $this->main = $this->writer($names->file('submissions'), $main->headers);
        foreach ($repeaters as $key => $sheet) {
            $this->writers[$key] = $this->writer($names->file($key), $sheet->headers);
        }
    }

    public function submission(array $cells): void
    {
        $this->main->addRow($this->row($cells));
    }

    public function repeaterRow(string $repeaterKey, array $cells): void
    {
        ($this->writers[$repeaterKey] ?? null)?->addRow($this->row($cells));
    }

    public function close(): WrittenFile
    {
        $this->main->close();
        foreach ($this->writers as $writer) {
            $writer->close();
        }
        if ($this->writers === []) {
            return new WrittenFile((string) reset($this->files), 'csv');
        }

        $zipPath = $this->directory.'/export.zip';
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create the CSV archive.');
        }
        foreach ($this->files as $name => $path) {
            $zip->addFile($path, $name);
        }
        $zip->close();

        return new WrittenFile($zipPath, 'zip');
    }

    /** Neutralizes spreadsheet formulas in text cells (OWASP CSV injection). */
    public static function text(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 && preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1 ? "'".$value : $value;
    }

    /** @param  list<string>  $headers */
    private function writer(string $stem, array $headers): Writer
    {
        $path = $this->directory.'/'.$stem.'.csv';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow($this->row($headers));
        $this->files[$stem.'.csv'] = $path;

        return $writer;
    }

    /** @param  list<string|int|bool|DateCell|null>  $cells */
    private function row(array $cells): Row
    {
        return new Row(array_map(static fn (string|int|bool|DateCell|null $value): StringCell => new StringCell(match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            $value instanceof DateCell => $value->text(),
            default => self::text($value),
        }, null), $cells));
    }
}
