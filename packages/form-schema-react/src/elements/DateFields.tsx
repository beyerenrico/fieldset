/** date, time and datetime: native inputs. */
import type { ElementNode } from '@form-schema-app/form-schema-js';
import type { ChangeEvent, ReactElement } from 'react';
import type { DataPath } from '../data/path.ts';
import { fromLocalInputValue, toLocalInputValue } from '../format/datetime.ts';
import { Field } from './Field.tsx';
import { inputAria, useField } from './useField.ts';

export function DateField({
    node,
    base,
}: {
    readonly node: ElementNode;
    readonly base: DataPath;
}): ReactElement {
    const field = useField(node, base);
    const raw = typeof field.value === 'string' ? field.value : '';
    const common = { ...inputAria(field), className: 'fsr-input', onBlur: field.touch };

    if (node.type === 'datetime') {
        const onChange = (event: ChangeEvent<HTMLInputElement>): void => {
            const next = event.target.value;
            field.setValue(next === '' ? null : (fromLocalInputValue(next) ?? next));
        };
        const local = toLocalInputValue(raw);
        return (
            <Field field={field}>
                <input
                    {...common}
                    type="datetime-local"
                    step={/:\d\d:\d\d$/.test(local) ? 1 : undefined}
                    value={local}
                    onChange={onChange}
                />
            </Field>
        );
    }
    const onChange = (event: ChangeEvent<HTMLInputElement>): void => {
        field.setValue(event.target.value === '' ? null : event.target.value);
    };
    if (node.type === 'time') {
        const hasSeconds = /^\d\d:\d\d:\d\d$/.test(raw);
        return (
            <Field field={field}>
                <input
                    {...common}
                    type="time"
                    step={hasSeconds ? 1 : undefined}
                    value={raw}
                    onChange={onChange}
                />
            </Field>
        );
    }
    return (
        <Field field={field}>
            <input {...common} type="date" value={raw} onChange={onChange} />
        </Field>
    );
}
