<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exports\Writers;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\BooleanCell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\XLSX\Writer;

/**
 * XLSX via openspout (streamed to temp files per sheet). Text is always written as a string cell —
 * never through Cell::fromValue, which would turn "=…" into a formula. Decimal values stay exact
 * strings (openspout numeric cells only take int|float, ADR 0033); integers (row counts, versions)
 * are numeric cells.
 */
final class XlsxExportWriter implements ExportWriter
{
    private Writer $writer;

    private string $path;

    private Sheet $main;

    /** @var array<string, Sheet> */
    private array $sheets = [];

    private ?Sheet $current = null;

    private Style $date;

    private Style $dateTime;

    public function open(string $directory, ExportSheet $main, array $repeaters): void
    {
        $this->path = $directory.'/export.xlsx';
        $this->date = new Style()->setFormat('yyyy-mm-dd');
        $this->dateTime = new Style()->setFormat('yyyy-mm-dd hh:mm');
        $names = new SheetNames;

        $this->writer = new Writer;
        $this->writer->openToFile($this->path);
        $this->main = $this->writer->getCurrentSheet();
        $this->main->setName($names->sheet($main->name));
        $this->writer->addRow($this->header($main->headers));
        foreach ($repeaters as $key => $sheet) {
            $this->sheets[$key] = $this->writer->addNewSheetAndMakeItCurrent();
            $this->sheets[$key]->setName($names->sheet($sheet->name));
            $this->writer->addRow($this->header($sheet->headers));
        }
        $this->writer->setCurrentSheet($this->main);
        $this->current = $this->main;
    }

    public function submission(array $cells): void
    {
        $this->write($this->main, $cells);
    }

    public function repeaterRow(string $repeaterKey, array $cells): void
    {
        if (isset($this->sheets[$repeaterKey])) {
            $this->write($this->sheets[$repeaterKey], $cells);
        }
    }

    public function close(): WrittenFile
    {
        $this->writer->close();

        return new WrittenFile($this->path, 'xlsx');
    }

    /** @param  list<string|int|bool|DateCell|null>  $cells */
    private function write(Sheet $sheet, array $cells): void
    {
        if ($this->current !== $sheet) {
            $this->writer->setCurrentSheet($sheet);
            $this->current = $sheet;
        }
        $this->writer->addRow(new Row(array_map($this->cell(...), $cells)));
    }

    private function cell(string|int|bool|DateCell|null $value): Cell
    {
        return match (true) {
            $value === null, $value === '' => new EmptyCell(null, null),
            is_bool($value) => new BooleanCell($value, null),
            is_int($value) => new NumericCell($value, null),
            $value instanceof DateCell => new DateTimeCell($value->value, $value->withTime ? $this->dateTime : $this->date),
            default => new StringCell($value, null),
        };
    }

    /** @param  list<string>  $headers */
    private function header(array $headers): Row
    {
        return new Row(array_map(static fn (string $h): Cell => new StringCell($h, null), $headers), new Style()->setFontBold());
    }
}
