<?php

declare(strict_types=1);

namespace App\Domain\Reports\Schema;

use stdClass;

/**
 * One data key of a form across its versions (BUILD_PROMPT §8.4): the element of the newest version
 * that has the key supplies label and type; options are the union over all versions (newest label wins,
 * so values of removed options keep their label). `versions` holds each version's own element (form
 * version id => element), used for per-submission money currency and scale. Repeater children carry a
 * dotted key (`items.qty`, as projected into submission_values) and the key of their repeater.
 */
final readonly class ReportField
{
    public const string FILTER_SELECT = 'select';

    public const string FILTER_BOOLEAN = 'boolean';

    public const string FILTER_DATE = 'date';

    public const string FILTER_NUMBER = 'number';

    private const array NUMERIC = ['number', 'money', 'rating'];

    private const array GROUPABLE = ['select', 'radio', 'checkbox', 'toggle', 'text', 'email', 'rating', 'date', 'datetime', 'hidden'];

    /** @param  array<int, stdClass>  $versions  form version id => that version's element, newest first */
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public stdClass $element,
        public ?string $repeater = null,
        public array $versions = [],
    ) {}

    /** The element of a submission's version (same key), or the newest element. */
    public function elementFor(?int $versionId): stdClass
    {
        $element = $versionId === null ? null : ($this->versions[$versionId] ?? null);

        return $element instanceof stdClass ? $element : $this->element;
    }

    public function isRepeater(): bool
    {
        return $this->type === 'repeater';
    }

    public function isTopLevel(): bool
    {
        return $this->repeater === null;
    }

    /** Values land in submission_values.value_num (number, money, rating, numeric calculations). */
    public function isNumeric(): bool
    {
        return in_array($this->type, self::NUMERIC, true)
            || ($this->type === 'calculated' && ($this->element->format ?? 'number') !== 'text');
    }

    public function isMoney(): bool
    {
        return $this->type === 'money' || ($this->type === 'calculated' && ($this->element->format ?? null) === 'money');
    }

    public function isDate(): bool
    {
        return in_array($this->type, ['date', 'datetime'], true);
    }

    public function isBoolean(): bool
    {
        return in_array($this->type, ['checkbox', 'toggle'], true);
    }

    /** Field-value filter kind of the explorer (select, boolean, date, number) or null. */
    public function filterKind(): ?string
    {
        return match (true) {
            in_array($this->type, ['select', 'radio', 'multiselect'], true) => self::FILTER_SELECT,
            $this->isBoolean() => self::FILTER_BOOLEAN,
            $this->isDate() => self::FILTER_DATE,
            $this->isNumeric() => self::FILTER_NUMBER,
            default => null,
        };
    }

    /** Saved reports group by top-level keys with one value per submission. */
    public function isGroupable(): bool
    {
        return $this->isTopLevel() && in_array($this->type, self::GROUPABLE, true);
    }

    public function currency(): string
    {
        return $this->currencyOf($this->element);
    }

    /** Currency of the key in a submission's version (the newest version's for unknown versions). */
    public function currencyFor(?int $versionId): string
    {
        return $this->currencyOf($this->elementFor($versionId));
    }

    /** @return array<int, string> form version id => currency, for the versions where the key is money */
    public function currencies(): array
    {
        if (! $this->isMoney()) {
            return [];
        }
        $currencies = [];
        foreach ($this->versions as $versionId => $element) {
            if ($this->isMoneyElement($element)) {
                $currencies[$versionId] = $this->currencyOf($element);
            }
        }

        return $currencies;
    }

    /** Fraction digits of money in a currency: the largest `scale` of the versions using it (default 2). */
    public function scaleFor(string $currency): int
    {
        $scales = [];
        foreach ($this->versions === [] ? [$this->element] : $this->versions as $element) {
            if ($this->isMoneyElement($element) && $this->currencyOf($element) === $currency) {
                $scales[] = is_int($element->scale ?? null) ? max(0, min(10, $element->scale)) : 2;
            }
        }

        return $scales === [] ? 2 : max($scales);
    }

    /** @return array<string, string> option value => label over all versions (select, radio, multiselect) */
    public function options(): array
    {
        $options = [];
        foreach ([$this->element, ...array_values($this->versions)] as $element) {
            foreach (is_array($element->options ?? null) ? $element->options : [] as $option) {
                if ($option instanceof stdClass && is_scalar($option->value ?? null)) {
                    $value = (string) $option->value;
                    $options[$value] ??= is_string($option->label ?? null) && $option->label !== '' ? $option->label : $value;
                }
            }
        }

        return $options;
    }

    private function isMoneyElement(stdClass $element): bool
    {
        return ($element->type ?? null) === 'money' || (($element->type ?? null) === 'calculated' && ($element->format ?? null) === 'money');
    }

    private function currencyOf(stdClass $element): string
    {
        return is_string($element->currency ?? null) ? $element->currency : '';
    }
}
