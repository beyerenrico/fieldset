/** text, textarea, email and phone inputs. */
import type { ElementNode } from '@form-schema-app/form-schema-js';
import type { ChangeEvent, ReactElement } from 'react';
import type { DataPath } from '../data/path.ts';
import { Field } from './Field.tsx';
import { inputAria, useField } from './useField.ts';

const INPUT_TYPES: Readonly<
    Record<string, { type: string; inputMode?: 'tel' | 'email'; autoComplete?: string }>
> = {
    text: { type: 'text' },
    email: { type: 'email', inputMode: 'email', autoComplete: 'email' },
    phone: { type: 'tel', inputMode: 'tel', autoComplete: 'tel' },
};

function rowsOf(node: ElementNode): number {
    const rows = node.raw['rows'];
    return typeof rows === 'number' && Number.isInteger(rows) ? Math.min(Math.max(rows, 2), 20) : 4;
}

export function TextField({
    node,
    base,
}: {
    readonly node: ElementNode;
    readonly base: DataPath;
}): ReactElement {
    const field = useField(node, base);
    const value = typeof field.value === 'string' ? field.value : '';
    const onChange = (event: ChangeEvent<HTMLInputElement | HTMLTextAreaElement>): void => {
        field.setValue(event.target.value);
    };
    const common = {
        ...inputAria(field),
        className: 'fsr-input',
        value,
        placeholder: field.placeholder,
        onChange,
        onBlur: field.touch,
    };
    if (node.type === 'textarea') {
        return (
            <Field field={field}>
                <textarea {...common} rows={rowsOf(node)} />
            </Field>
        );
    }
    const config = INPUT_TYPES[node.type] ?? { type: 'text' };
    return (
        <Field field={field}>
            <input
                {...common}
                type={config.type}
                inputMode={config.inputMode}
                autoComplete={config.autoComplete}
            />
        </Field>
    );
}
