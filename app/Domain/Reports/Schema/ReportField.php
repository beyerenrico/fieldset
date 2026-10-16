<?php

declare(strict_types=1);

namespace App\Domain\Reports\Schema;

use stdClass;

/**
 * One data key of a form across its versions (BUILD_PROMPT §8.4): the element of the newest version
 * that has the key supplies label, type and options. Repeater children carry a dotted key
 * (`items.qty`, as projected into submission_values) and the key of their repeater.
 */
final readonly class ReportField
{
    public const string FILTER_SELECT = 'select';

    public const string FILTER_BOOLEAN = 'boolean';

    public const string FILTER_DATE = 'date';

    public const string FILTER_NUMBER = 'number';

    private const array NUMERIC = ['number', 'money', 'rating'];

    private const array GROUPABLE = ['select', 'radio', 'checkbox', 'toggle', 'text', 'email', 'rating', 'date', 'datetime', 'hidden'];

    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public stdClass $element,
        public ?string $repeater = null,
    ) {}

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
        return is_string($this->element->currency ?? null) ? $this->element->currency : '';
    }

    /** @return array<string, string> option value => label (select, radio, multiselect) */
    public function options(): array
    {
        $options = [];
        foreach (is_array($this->element->options ?? null) ? $this->element->options : [] as $option) {
            if ($option instanceof stdClass && is_scalar($option->value ?? null)) {
                $value = (string) $option->value;
                $options[$value] = is_string($option->label ?? null) && $option->label !== '' ? $option->label : $value;
            }
        }

        return $options;
    }
}
