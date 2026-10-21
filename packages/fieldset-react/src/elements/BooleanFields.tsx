/** checkbox, toggle and rating. */
import type { ElementNode } from '@fieldset/fieldset-js';
import type { ReactElement } from 'react';
import type { DataPath } from '../data/path.ts';
import { interpolate } from '../i18n/messages.ts';
import { Field, RequiredMarker } from './Field.tsx';
import { inputAria, useField } from './useField.ts';

export function BooleanField({
    node,
    base,
}: {
    readonly node: ElementNode;
    readonly base: DataPath;
}): ReactElement {
    const field = useField(node, base);
    const toggle = node.type === 'toggle';
    return (
        <Field field={field} ownLabel>
            <label className={`fsr-check ${toggle ? 'fsr-check--switch' : ''}`} htmlFor={field.id}>
                <input
                    {...inputAria(field)}
                    type="checkbox"
                    role={toggle ? 'switch' : undefined}
                    checked={field.value === true}
                    onChange={(event) => {
                        field.setValue(event.target.checked);
                        field.touch();
                    }}
                />
                {toggle && <span className="fsr-switch" aria-hidden="true" />}
                <span className={field.inCell ? 'fsr-sr-only' : 'fsr-check__label'}>
                    {field.label}
                    {field.required && (
                        <RequiredMarker text={field.context.messages.requiredMarker} />
                    )}
                </span>
            </label>
        </Field>
    );
}

function maxOf(node: ElementNode): number {
    const max = node.raw['max'];
    return typeof max === 'number' && Number.isInteger(max) && max >= 3 && max <= 10 ? max : 5;
}

export function RatingField({
    node,
    base,
}: {
    readonly node: ElementNode;
    readonly base: DataPath;
}): ReactElement {
    const field = useField(node, base);
    const max = maxOf(node);
    const current = typeof field.value === 'number' ? field.value : 0;
    const values = Array.from({ length: max }, (_, index) => index + 1);
    return (
        <Field field={field} group>
            <div
                className="fsr-rating"
                role="radiogroup"
                aria-required={field.required ? true : undefined}
            >
                {values.map((value) => {
                    const id = value === 1 ? field.id : `${field.id}-${String(value)}`;
                    return (
                        <label
                            key={value}
                            htmlFor={id}
                            className={`fsr-rating__star ${value <= current ? 'fsr-rating__star--on' : ''}`}
                        >
                            <input
                                id={id}
                                className="fsr-sr-only"
                                type="radio"
                                name={field.id}
                                value={value}
                                checked={current === value}
                                disabled={field.disabled}
                                aria-invalid={field.error === undefined ? undefined : true}
                                onChange={() => {
                                    field.setValue(value);
                                    field.touch();
                                }}
                            />
                            <span aria-hidden="true">★</span>
                            <span className="fsr-sr-only">
                                {interpolate(field.context.messages.ratingValue, { value, max })}
                            </span>
                        </label>
                    );
                })}
            </div>
        </Field>
    );
}
