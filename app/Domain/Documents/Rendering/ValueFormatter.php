<?php

declare(strict_types=1);

namespace App\Domain\Documents\Rendering;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use IntlDateFormatter;
use NumberFormatter;
use stdClass;

/**
 * Formats submission values for print with Intl, per the definition (or design) locale (§11 i18n).
 * Decimal strings are formatted digit by digit with the locale's symbols, never through floats, so
 * no value is rounded or truncated; money takes its symbol position from the locale's currency pattern.
 */
final readonly class ValueFormatter
{
    private const array DATE_STYLES = ['short' => IntlDateFormatter::SHORT, 'medium' => IntlDateFormatter::MEDIUM, 'long' => IntlDateFormatter::LONG];

    private const array NUMERIC_CALCULATED = ['number', 'money'];

    private string $decimalSeparator;

    private string $groupingSeparator;

    private string $minus;

    public function __construct(
        private string $locale,
        private string $timezone,
        private string $dateStyle = 'medium',
        private string $lang = 'en',
    ) {
        $numbers = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $this->decimalSeparator = $numbers->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL) ?: '.';
        $this->groupingSeparator = $numbers->getSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL) ?: ',';
        $this->minus = $numbers->getSymbol(NumberFormatter::MINUS_SIGN_SYMBOL) ?: '-';
    }

    /** Display string for an element's value; '' when empty. */
    public function format(stdClass $element, mixed $value): string
    {
        if (in_array($value, [null, '', []], true)) {
            return '';
        }

        return match ((string) ($element->type ?? '')) {
            'number' => $this->decimal($value),
            'money' => $this->money($value, $this->string($element->currency ?? null)),
            'date' => $this->date($value),
            'time' => $this->time($value),
            'datetime' => $this->dateTime($value),
            'select', 'radio' => $this->option($element, $value),
            'multiselect' => implode(', ', array_map(fn (mixed $v): string => $this->option($element, $v), is_array($value) ? $value : [$value])),
            'checkbox', 'toggle' => $this->yesNo($value),
            'rating' => $this->scalar($value).' / '.(is_int($element->max ?? null) ? $element->max : 5),
            'location' => $this->location($value),
            'calculated' => $this->calculated($element, $value),
            default => $this->scalar($value),
        };
    }

    public function isNumeric(stdClass $element): bool
    {
        $type = (string) ($element->type ?? '');

        return in_array($type, ['number', 'money', 'rating'], true)
            || ($type === 'calculated' && in_array($element->format ?? 'number', self::NUMERIC_CALCULATED, true));
    }

    public function decimal(mixed $value): string
    {
        $string = $this->scalar($value);
        if (preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $string, $m) !== 1) {
            return $string;
        }
        $integer = ltrim($m[2], '0') === '' ? '0' : ltrim($m[2], '0');
        $grouped = implode($this->groupingSeparator, array_reverse(array_map(strrev(...), str_split(strrev($integer), 3))));
        $fraction = $m[3] ?? '';

        return ($m[1] === '-' ? $this->minus : '').$grouped.($fraction !== '' ? $this->decimalSeparator.$fraction : '');
    }

    public function money(mixed $value, ?string $currency): string
    {
        $string = $this->scalar($value);
        if ($currency === null || preg_match('/^[A-Z]{3}$/', $currency) !== 1 || preg_match('/^-?\d+(\.\d+)?$/', $string) !== 1) {
            return $this->decimal($string);
        }
        $negative = str_starts_with($string, '-');
        $template = (string) new NumberFormatter($this->locale, NumberFormatter::CURRENCY)->formatCurrency($negative ? -1.0 : 1.0, $currency);
        $number = $this->decimal(ltrim($string, '-'));

        return (string) preg_replace('/\d+(?:[.,\x{00A0}\x{202F}\x{2019}\']\d+)*/u', str_replace(['\\', '$'], ['\\\\', '\\$'], $number), $template, 1);
    }

    public function date(mixed $value): string
    {
        $string = $this->scalar($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $string, new DateTimeZone('UTC'));

        return $date === false ? $string : (string) $this->intl(self::DATE_STYLES[$this->dateStyle], IntlDateFormatter::NONE, 'UTC')->format($date);
    }

    public function time(mixed $value): string
    {
        $string = $this->scalar($value);
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $string, $m) !== 1) {
            return $string;
        }
        $time = new DateTimeImmutable('1970-01-01T'.$string, new DateTimeZone('UTC'));
        $style = isset($m[1]) ? IntlDateFormatter::MEDIUM : IntlDateFormatter::SHORT;

        return (string) $this->intl(IntlDateFormatter::NONE, $style, 'UTC')->format($time);
    }

    /** An RFC 3339 date-time shown in the organization time zone. */
    public function dateTime(mixed $value): string
    {
        $string = $this->scalar($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/i', $string) !== 1) {
            return $string; // never "now" or relative strings: output must be deterministic
        }
        try {
            $moment = new DateTimeImmutable($string);
        } catch (Exception) {
            return $string;
        }

        return (string) $this->intl(self::DATE_STYLES[$this->dateStyle], IntlDateFormatter::SHORT, $this->timezone)->format($moment);
    }

    public function yesNo(mixed $value): string
    {
        return (string) __($value === true ? 'documents.render.yes' : 'documents.render.no', [], $this->lang);
    }

    private function calculated(stdClass $element, mixed $value): string
    {
        return match ($element->format ?? 'number') {
            'money' => $this->money($value, $this->string($element->currency ?? null)),
            'text' => $this->scalar($value),
            default => $this->decimal($value),
        };
    }

    private function option(stdClass $element, mixed $value): string
    {
        foreach (is_array($element->options ?? null) ? $element->options : [] as $option) {
            if ($option instanceof stdClass && ($option->value ?? null) === $value) {
                return $this->scalar($option->label ?? $value);
            }
        }

        return $this->scalar($value);
    }

    /** Coordinates with the locale's decimal separator; "; " between them where that separator is a comma. */
    private function location(mixed $value): string
    {
        if (! $value instanceof stdClass || ! is_numeric($value->lat ?? null) || ! is_numeric($value->lng ?? null)) {
            return '';
        }
        $coordinate = fn (mixed $number): string => str_replace('.', $this->decimalSeparator, $this->scalar($number));
        $text = $coordinate($value->lat).($this->decimalSeparator === ',' ? '; ' : ', ').$coordinate($value->lng);

        return is_numeric($value->accuracy ?? null) ? $text.' (± '.$coordinate($value->accuracy).' m)' : $text;
    }

    private function intl(int $dateType, int $timeType, string $timezone): IntlDateFormatter
    {
        return new IntlDateFormatter($this->locale, $dateType, $timeType, $timezone);
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            default => '',
        };
    }
}
