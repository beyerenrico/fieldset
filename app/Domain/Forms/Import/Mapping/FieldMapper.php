<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import\Mapping;

use App\Domain\Forms\Import\Model\AcroField;

/**
 * Maps one AcroForm field to an element type per the §8.13 table: text/number/money/date (Acrobat
 * formatting), textarea, maxLength, checkbox, radio, select/multiselect, signature, required,
 * readOnlyIf and built-in calculations. Push buttons, hidden fields and custom JavaScript
 * calculations are not imported.
 */
final readonly class FieldMapper
{
    private const array LENGTH_TYPES = ['text', 'textarea', 'phone'];

    public function __construct(private string $defaultCurrency = 'EUR') {}

    public function map(AcroField $field): MappedField|UnmappedField
    {
        $mapped = match (true) {
            $field->isPushButton() => 'push_button',
            $field->isHidden() => 'hidden',
            $field->type === 'Sig' => new MappedField($field, 'signature'),
            $field->type === 'Btn' => $this->button($field),
            $field->type === 'Ch' => $this->choice($field),
            $field->type === 'Tx' => $this->text($field),
            default => 'unsupported_type',
        };

        if (is_string($mapped)) {
            return new UnmappedField($field->name, $field->page, $mapped);
        }

        return $this->withFlags($mapped);
    }

    private function withFlags(MappedField $mapped): MappedField
    {
        $field = $mapped->field;
        $properties = $mapped->properties;
        $warnings = $mapped->warnings;
        if (! $mapped->isCalculated()) {
            $properties += array_filter([
                'required' => $field->has(AcroField::FF_REQUIRED) ? true : null,
                'readOnlyIf' => $field->has(AcroField::FF_READ_ONLY) ? true : null,
            ], static fn (?bool $v): bool => $v !== null);
        }
        if ($field->widgets > 1 && ! $field->isRadio() && $mapped->type !== 'radio') {
            $warnings[] = ['code' => 'multiple_widgets', 'params' => ['count' => $field->widgets]];
        }
        if (in_array($mapped->type, self::LENGTH_TYPES, true) && $field->maxLength !== null) {
            $properties['validation'] = ['maxLength' => $field->maxLength];
        }

        return new MappedField($field, $mapped->type, $properties, $mapped->confidence, $mapped->calculation, $warnings);
    }

    private function text(AcroField $field): MappedField|string
    {
        if ($field->has(AcroField::FF_FILE_SELECT)) {
            return 'file_select';
        }
        $format = ScriptPatterns::format($field->formatScript ?? $field->keystrokeScript);
        if ($field->calculateScript !== null) {
            return $this->calculated($field, $format);
        }
        if ($field->has(AcroField::FF_MULTILINE)) {
            return new MappedField($field, 'textarea');
        }
        $warnings = $field->has(AcroField::FF_PASSWORD) ? [['code' => 'password_field']] : [];

        return match ($format['kind'] ?? null) {
            null => new MappedField($field, 'text', [], 1.0, null, $warnings),
            'number' => $this->number($field, $format, $warnings),
            'percent' => new MappedField($field, 'number', [], 0.8, null, [...$warnings, ['code' => 'percent_format']]),
            'date', 'time', 'datetime' => new MappedField($field, (string) $format['kind'], [], 1.0, null, $warnings),
            'phone' => new MappedField($field, 'phone', [], 0.9, null, $warnings),
            'special' => new MappedField($field, 'text', [], 0.9, null, [...$warnings, ['code' => 'special_format']]),
            default => new MappedField($field, 'text', [], 0.7, null, [...$warnings, ['code' => 'custom_format']]),
        };
    }

    /**
     * @param  array{kind: string, decimals?: int, currency?: string}  $format
     * @param  list<array{code: string, params?: array<string, int|string>}>  $warnings
     */
    private function number(AcroField $field, array $format, array $warnings): MappedField
    {
        [$currency, $confidence, $warnings] = $this->currency($format, $warnings);
        if ($currency === null) {
            return new MappedField($field, 'number', [], 1.0, null, $warnings);
        }

        return new MappedField($field, 'money', ['currency' => $currency, 'scale' => min($format['decimals'] ?? 2, 4)], $confidence, null, $warnings);
    }

    /** @param  array{kind: string, decimals?: int, currency?: string}|null  $format */
    private function calculated(AcroField $field, ?array $format): MappedField|string
    {
        $calculation = ScriptPatterns::calculation($field->calculateScript);
        if ($calculation === null) {
            return 'custom_calculation';
        }

        $warnings = [];
        $properties = ['format' => 'number'];
        if (($format['kind'] ?? null) === 'number') {
            [$currency, , $warnings] = $this->currency($format, $warnings);
            $properties = $currency === null
                ? ['format' => 'number', 'scale' => min($format['decimals'] ?? 2, 10)]
                : ['format' => 'money', 'currency' => $currency, 'scale' => min($format['decimals'] ?? 2, 10)];
        } elseif ($format !== null) {
            $warnings[] = ['code' => 'calculation_format'];
        }

        return new MappedField($field, 'calculated', $properties, 0.9, $calculation, $warnings);
    }

    /**
     * @param  array{kind: string, decimals?: int, currency?: string}  $format
     * @param  list<array{code: string, params?: array<string, int|string>}>  $warnings
     * @return array{0: ?string, 1: float, 2: list<array{code: string, params?: array<string, int|string>}>}
     */
    private function currency(array $format, array $warnings): array
    {
        $code = ScriptPatterns::currency($format['currency'] ?? '');
        if ($code === '?') {
            return [$this->defaultCurrency, 0.8, [...$warnings, ['code' => 'currency_guessed', 'params' => ['symbol' => (string) ($format['currency'] ?? ''), 'currency' => $this->defaultCurrency]]]];
        }

        return [$code, 1.0, $warnings];
    }

    private function button(AcroField $field): MappedField|string
    {
        $options = $this->options($field->options);
        if ($field->isRadio()) {
            return $options === [] ? 'no_options' : new MappedField($field, 'radio', ['options' => $options]);
        }
        if (count($options) > 1) {
            // Check boxes sharing one name with different export values behave like a radio group.
            return new MappedField($field, 'radio', ['options' => $options], 0.8, null, [['code' => 'checkbox_group']]);
        }

        return new MappedField($field, 'checkbox');
    }

    private function choice(AcroField $field): MappedField|string
    {
        $options = $this->options($field->options);
        if ($options === []) {
            return 'no_options';
        }
        $type = ! $field->has(AcroField::FF_COMBO) && $field->has(AcroField::FF_MULTI_SELECT) ? 'multiselect' : 'select';
        $editable = $field->has(AcroField::FF_COMBO) && $field->has(AcroField::FF_EDIT);

        return new MappedField($field, $type, ['options' => $options], $editable ? 0.9 : 1.0, null, $editable ? [['code' => 'editable_choice']] : []);
    }

    /**
     * Spec option rules: value 1–100 characters and unique within the element; label non-empty.
     *
     * @param  list<array{value: string, label: string}>  $options
     * @return list<array{value: string, label: string}>
     */
    private function options(array $options): array
    {
        $unique = [];
        foreach ($options as $option) {
            $value = mb_substr($option['value'], 0, 100);
            if (trim($value) === '' || isset($unique[$value])) {
                continue;
            }
            $label = trim($option['label']);
            $unique[$value] = ['value' => $value, 'label' => $label !== '' ? $label : $value];
        }

        return array_values($unique);
    }
}
