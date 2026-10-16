/** select, radio and multiselect. */
import type { ElementNode, JsonValue } from '@form-schema-app/form-schema-js';
import type { ReactElement } from 'react';
import type { DataPath } from '../data/path.ts';
import { Field } from './Field.tsx';
import { inputAria, useField, type FieldState } from './useField.ts';

interface OptionItem {
    readonly value: string;
    readonly label: string;
}

function optionsOf(node: ElementNode): OptionItem[] {
    const options = node.raw['options'];
    if (!Array.isArray(options)) {
        return [];
    }
    return options.flatMap((option: unknown) => {
        if (typeof option !== 'object' || option === null) {
            return [];
        }
        const { value, label } = option as { value?: unknown; label?: unknown };
        return typeof value === 'string'
            ? [{ value, label: typeof label === 'string' ? label : value }]
            : [];
    });
}

function SelectInput({
    field,
    options,
}: {
    readonly field: FieldState;
    readonly options: readonly OptionItem[];
}): ReactElement {
    const value = typeof field.value === 'string' ? field.value : '';
    return (
        <Field field={field}>
            <select
                {...inputAria(field)}
                className="fsr-input fsr-select"
                value={value}
                onChange={(event) => {
                    field.setValue(event.target.value === '' ? null : event.target.value);
                    field.touch();
                }}
                onBlur={field.touch}
            >
                <option value="">{field.context.messages.selectPlaceholder}</option>
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </Field>
    );
}

function groupAria(field: FieldState): { 'aria-invalid': boolean | undefined; disabled: boolean } {
    return {
        'aria-invalid': field.error === undefined ? undefined : true,
        disabled: field.disabled,
    };
}

function RadioGroup({
    field,
    options,
}: {
    readonly field: FieldState;
    readonly options: readonly OptionItem[];
}): ReactElement {
    return (
        <Field field={field} group>
            <div
                className="fsr-options"
                role="radiogroup"
                aria-required={field.required ? true : undefined}
            >
                {options.map((option, index) => (
                    <label
                        key={option.value}
                        className="fsr-option"
                        htmlFor={index === 0 ? field.id : `${field.id}-${String(index)}`}
                    >
                        <input
                            {...groupAria(field)}
                            id={index === 0 ? field.id : `${field.id}-${String(index)}`}
                            type="radio"
                            name={field.id}
                            value={option.value}
                            checked={field.value === option.value}
                            onChange={() => {
                                field.setValue(option.value);
                                field.touch();
                            }}
                        />
                        <span>{option.label}</span>
                    </label>
                ))}
            </div>
        </Field>
    );
}

function selectedValues(value: JsonValue | undefined): ReadonlySet<string> {
    return new Set(
        Array.isArray(value)
            ? value.filter((item): item is string => typeof item === 'string')
            : [],
    );
}

function CheckboxGroup({
    field,
    options,
}: {
    readonly field: FieldState;
    readonly options: readonly OptionItem[];
}): ReactElement {
    const selected = selectedValues(field.value);
    const toggle = (value: string, checked: boolean): void => {
        const next = options
            .map((option) => option.value)
            .filter((candidate) => (candidate === value ? checked : selected.has(candidate)));
        field.setValue(next.length === 0 ? null : next);
        field.touch();
    };
    return (
        <Field field={field} group>
            <div className="fsr-options">
                {options.map((option, index) => {
                    const id = index === 0 ? field.id : `${field.id}-${String(index)}`;
                    return (
                        <label key={option.value} className="fsr-option" htmlFor={id}>
                            <input
                                {...groupAria(field)}
                                id={id}
                                type="checkbox"
                                value={option.value}
                                checked={selected.has(option.value)}
                                onChange={(event) => {
                                    toggle(option.value, event.target.checked);
                                }}
                            />
                            <span>{option.label}</span>
                        </label>
                    );
                })}
            </div>
        </Field>
    );
}

export function ChoiceField({
    node,
    base,
}: {
    readonly node: ElementNode;
    readonly base: DataPath;
}): ReactElement {
    const field = useField(node, base);
    const options = optionsOf(node);
    if (node.type === 'radio') {
        return <RadioGroup field={field} options={options} />;
    }
    if (node.type === 'multiselect') {
        return <CheckboxGroup field={field} options={options} />;
    }
    return <SelectInput field={field} options={options} />;
}
