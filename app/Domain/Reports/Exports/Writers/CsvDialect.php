<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exports\Writers;

use NumberFormatter;

/**
 * CSV conventions of a locale, so a spreadsheet opens the file in columns with numbers it can read:
 * where the decimal separator is a comma (de: "1234,5") the field delimiter is a semicolon, otherwise a
 * comma. Decimal strings keep every digit — only the separator changes (never a float round trip).
 */
final readonly class CsvDialect
{
    private function __construct(public string $delimiter, public string $decimalSeparator) {}

    public static function forLocale(string $locale): self
    {
        $decimal = new NumberFormatter($locale, NumberFormatter::DECIMAL)->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);

        return $decimal === ',' ? new self(';', ',') : new self(',', '.');
    }

    /** "1234.50" → "1234,50" for a comma locale; anything that is not a decimal string stays as it is. */
    public function decimal(string $value): string
    {
        return $this->decimalSeparator !== '.' && preg_match('/^-?\d+\.\d+$/', $value) === 1 ? str_replace('.', $this->decimalSeparator, $value) : $value;
    }
}
