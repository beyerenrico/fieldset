/** number and money inputs: decimal strings, locale-aware separators, `inputmode="decimal"`. */
import type { ElementNode } from '@fieldset/fieldset-js';
import { useState, type ChangeEvent, type ReactElement } from 'react';
import { isDecimalString, parseDecimalInput, toInputText } from '../data/decimal-input.ts';
import { getAt, type DataPath } from '../data/path.ts';
import { currencyAffix, decimalSeparator } from '../format/intl.ts';
import { Field } from './Field.tsx';
import { inputAria, useField } from './useField.ts';

export function DecimalField({
    node,
    base,
}: {
    readonly node: ElementNode;
    readonly base: DataPath;
}): ReactElement {
    const field = useField(node, base);
    const { locale, result } = field.context;
    const [text, setText] = useState<string | null>(null);
    const separator = decimalSeparator(locale);
    const currency =
        node.type === 'money' && typeof node.raw['currency'] === 'string'
            ? node.raw['currency']
            : null;
    const affix = currency === null ? null : currencyAffix(currency, locale);

    const onChange = (event: ChangeEvent<HTMLInputElement>): void => {
        const next = event.target.value;
        setText(next);
        const parsed = parseDecimalInput(next);
        field.setValue(
            parsed.kind === 'empty' ? null : parsed.kind === 'decimal' ? parsed.value : parsed.text,
        );
    };
    const onBlur = (): void => {
        setText(null);
        field.touch();
        const normalized = getAt(result.data, field.path);
        if (
            currency !== null &&
            isDecimalString(field.value) &&
            isDecimalString(normalized) &&
            normalized !== field.value
        ) {
            field.setValue(normalized);
        }
    };
    const input = (
        <input
            {...inputAria(field)}
            className="fsr-input fsr-input--decimal"
            type="text"
            inputMode="decimal"
            autoComplete="off"
            value={text ?? toInputText(field.value, separator)}
            placeholder={field.placeholder}
            onFocus={() => {
                setText(toInputText(field.value, separator));
            }}
            onChange={onChange}
            onBlur={onBlur}
        />
    );
    if (affix === null) {
        return <Field field={field}>{input}</Field>;
    }
    const adornment = (
        <span className="fsr-adornment" aria-hidden="true">
            {affix.symbol}
        </span>
    );
    return (
        <Field field={field}>
            <div className={`fsr-affix ${affix.before ? 'fsr-affix--before' : 'fsr-affix--after'}`}>
                {affix.before && adornment}
                {input}
                {!affix.before && adornment}
                <span className="fsr-sr-only">{currency}</span>
            </div>
        </Field>
    );
}
