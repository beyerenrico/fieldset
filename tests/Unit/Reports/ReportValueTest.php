<?php

declare(strict_types=1);

use App\Domain\Reports\Enums\ReportMetric;
use App\Domain\Reports\Exports\Writers\CsvExportWriter;
use App\Domain\Reports\Exports\Writers\SheetNames;
use App\Domain\Reports\Saved\ReportResult;
use App\Domain\Reports\Saved\ReportRow;
use App\Domain\Reports\Schema\ReportField;
use App\Domain\Reports\Schema\ReportValue;
use App\Domain\Submissions\Admin\ValueFormatter;

/* M5 report values: decoding projected text, explorer display, export cells, CSV safety, sheet names. */

function field(string $type, array $extra = []): ReportField
{
    $element = (object) ['type' => $type, 'key' => 'k', 'label' => 'K', ...$extra];

    return new ReportField('k', 'K', $type, $element);
}

it('formats explorer cells per type and locale without floats', function (): void {
    $de = new ValueFormatter('de', 'Europe/Berlin');
    $choice = field('select', ['options' => [(object) ['value' => 'a', 'label' => 'Alpha']]]);

    expect(ReportValue::display(field('money', ['currency' => 'EUR']), '1234567.10', $de))->toBe('1.234.567,10 EUR')
        ->and(ReportValue::display(field('number'), '0.1', $de))->toBe('0,1')
        ->and(ReportValue::display(field('checkbox'), 'false', $de))->toBe(__('inbox.value.no', [], 'de'))
        ->and(ReportValue::display($choice, 'a', $de))->toBe('Alpha')
        ->and(ReportValue::display(field('multiselect', ['options' => [(object) ['value' => 'a', 'label' => 'Alpha']]]), '["a","b"]', $de))->toBe('Alpha, b')
        ->and(ReportValue::display(field('date'), '2026-10-04', $de))->toBe('04.10.2026')
        ->and(ReportValue::display(field('photo'), '["u1","u2"]', $de))->toBe('2 files')
        ->and(ReportValue::display(field('signature'), '{"signerName":"Eva"}', $de))->toBe('Eva')
        ->and(ReportValue::display(field('text'), null, $de))->toBe('—');
});

it('produces exact export cells', function (): void {
    expect(ReportValue::export(field('money'), '0.10'))->toBe('0.10')
        ->and(ReportValue::export(field('toggle'), 'true'))->toBeTrue()
        ->and(ReportValue::export(field('radio', ['options' => [(object) ['value' => 'x', 'label' => 'Ex']]]), 'x'))->toBe('Ex')
        ->and(ReportValue::export(field('location'), '{"lat":52.5,"lng":13.4}'))->toBe('52.5, 13.4')
        ->and(ReportValue::export(field('file'), '["a"]'))->toBe(1)
        ->and(ReportValue::export(field('text'), null))->toBeNull();
});

it('neutralizes spreadsheet formulas in CSV text but keeps numbers', function (string $in, string $out): void {
    expect(CsvExportWriter::text($in))->toBe($out);
})->with([
    ['=SUM(A1)', "'=SUM(A1)"], ['+1 555', "'+1 555"], ['@cmd', "'@cmd"], ['-5.25', '-5.25'], ['-x', "'-x"], ['plain', 'plain'],
]);

it('makes unique valid sheet names', function (): void {
    $names = new SheetNames;

    expect($names->sheet('Items: [main]/*?'))->toBe('Items   main')
        ->and($names->sheet('Items   main'))->toBe('Items   main 2')
        ->and(mb_strlen($names->sheet(str_repeat('x', 40))))->toBe(31)
        ->and($names->file('Material & labour'))->toBe('Material-labour');
});

it('displays money results with two decimals and the currency', function (): void {
    $result = new ReportResult(ReportMetric::Sum, 'amount', 'Amount', null, 'Total', [], new ReportRow(null, 'Total', 1, '1234.5'), true, 'EUR');
    $en = new ValueFormatter('en', 'UTC');

    expect($result->display('1234.5', $en))->toBe('1,234.50 EUR')
        ->and($result->display('3', $en))->toBe('3.00 EUR')
        ->and($result->display('0.125', $en))->toBe('0.125 EUR')
        ->and($result->display(null, $en))->toBe('—');
});
