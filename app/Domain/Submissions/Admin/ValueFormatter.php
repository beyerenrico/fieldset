<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Admin;

use Carbon\CarbonImmutable;
use Fieldset\Json\Json;
use NumberFormatter;
use stdClass;
use Throwable;

/**
 * Read-only display strings for submission values in the admin (M3-T09). Number and money values stay
 * decimal strings: digits are grouped with the locale's separators, never converted to floats (§11).
 */
final readonly class ValueFormatter
{
    public const string EMPTY = '—';

    public function __construct(private string $locale, private string $timezone) {}

    public function format(stdClass $element, mixed $value): string
    {
        if (in_array($value, [null, '', []], true)) {
            return self::EMPTY;
        }

        $type = is_string($element->type ?? null) ? $element->type : '';

        return match ($type) {
            'number' => $this->decimalOrRaw($value),
            'money' => $this->money($value, $this->currency($element)),
            'calculated' => $this->calculated($element, $value),
            'date' => $this->date($value),
            'datetime' => $this->dateTime($value),
            'select', 'radio' => $this->optionLabel($element, $value),
            'multiselect' => is_array($value) ? implode(', ', array_map(fn (mixed $v): string => $this->optionLabel($element, $v), $value)) : $this->scalar($value),
            'checkbox', 'toggle' => (string) __($value === true ? 'inbox.value.yes' : 'inbox.value.no', [], $this->locale),
            'rating' => $this->scalar($value).' / '.(is_int($element->max ?? null) ? $element->max : 5),
            'location' => $this->location($value),
            default => $this->scalar($value),
        };
    }

    /** Groups the integer digits of a decimal string; anything else is returned unchanged. */
    public function decimal(string $value): string
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $m) !== 1) {
            return $value;
        }

        $formatter = new NumberFormatter($this->locale, NumberFormatter::DECIMAL);
        $group = $formatter->getSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL);
        $decimal = $formatter->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
        $integer = implode($group, array_map(strrev(...), array_reverse(str_split(strrev($m[2]), 3))));

        return $m[1].$integer.(($m[3] ?? '') !== '' ? $decimal.$m[3] : '');
    }

    public function dateTime(mixed $value): string
    {
        try {
            return CarbonImmutable::parse($this->scalar($value))->setTimezone($this->timezone)->settings(['locale' => $this->locale])->isoFormat('L LT');
        } catch (Throwable) {
            return $this->scalar($value);
        }
    }

    private function calculated(stdClass $element, mixed $value): string
    {
        return match ($element->format ?? 'number') {
            'money' => $this->money($value, $this->currency($element)),
            'text' => $this->scalar($value),
            default => $this->decimalOrRaw($value),
        };
    }

    private function money(mixed $value, string $currency): string
    {
        return trim($this->decimalOrRaw($value).' '.$currency);
    }

    private function decimalOrRaw(mixed $value): string
    {
        return is_string($value) ? $this->decimal($value) : $this->scalar($value);
    }

    private function currency(stdClass $element): string
    {
        return is_string($element->currency ?? null) ? $element->currency : '';
    }

    private function date(mixed $value): string
    {
        $string = $this->scalar($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $string) !== 1) {
            return $string;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $string)?->settings(['locale' => $this->locale])->isoFormat('L') ?? $string;
        } catch (Throwable) {
            return $string;
        }
    }

    private function optionLabel(stdClass $element, mixed $value): string
    {
        foreach (is_array($element->options ?? null) ? $element->options : [] as $option) {
            if ($option instanceof stdClass && ($option->value ?? null) === $value) {
                return is_string($option->label ?? null) ? $option->label : $this->scalar($value);
            }
        }

        return $this->scalar($value);
    }

    /** Coordinates with the locale's decimal separator; "; " between them where that separator is a comma. */
    private function location(mixed $value): string
    {
        if (! $value instanceof stdClass || ! is_numeric($value->lat ?? null) || ! is_numeric($value->lng ?? null)) {
            return $this->scalar($value);
        }

        $separator = new NumberFormatter($this->locale, NumberFormatter::DECIMAL)->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL) ?: '.';
        $coordinate = fn (mixed $number): string => str_replace('.', $separator, $this->scalar($number));
        $text = $coordinate($value->lat).($separator === ',' ? '; ' : ', ').$coordinate($value->lng);

        return is_numeric($value->accuracy ?? null) ? $text.' (± '.$coordinate($value->accuracy).' m)' : $text;
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            default => Json::encode($value),
        };
    }
}
