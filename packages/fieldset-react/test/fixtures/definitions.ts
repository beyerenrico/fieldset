import type { FormDefinition, FormElement, Page } from '@fieldset/fieldset-js';

let counter = 0;

/** Element with a generated id. */
export function el<T extends Omit<FormElement, 'id'>>(element: T): T & { id: string } {
    counter += 1;
    return { id: `00000000-0000-4000-8000-${String(counter).padStart(12, '0')}`, ...element };
}

export function page(
    key: string,
    elements: readonly FormElement[],
    extra: Partial<Page> = {},
): Page {
    return { key, title: `Page ${key}`, elements, ...extra };
}

export function definition(
    pages: readonly Page[],
    extra: Partial<FormDefinition> = {},
): FormDefinition {
    return {
        specVersion: '1.0.0',
        key: 'test_form',
        title: 'Test form',
        locale: 'en-US',
        pages,
        ...extra,
    };
}

const options = [
    { value: 'a', label: 'Alpha' },
    { value: 'b', label: 'Beta' },
    { value: 'c', label: 'Gamma' },
];

export const inputsForm: FormDefinition = definition([
    page('main', [
        el({
            type: 'text',
            key: 'name',
            label: 'Name',
            help: 'Your full name',
            placeholder: 'Jane',
        }),
        el({ type: 'textarea', key: 'notes', label: 'Notes', rows: 3 }),
        el({ type: 'email', key: 'mail', label: 'Email' }),
        el({ type: 'phone', key: 'tel', label: 'Phone' }),
        el({ type: 'number', key: 'qty', label: 'Quantity' }),
        el({ type: 'money', key: 'price', label: 'Price', currency: 'EUR' }),
        el({ type: 'date', key: 'day', label: 'Day' }),
        el({ type: 'time', key: 'at', label: 'Time' }),
        el({ type: 'datetime', key: 'when', label: 'When' }),
        el({ type: 'select', key: 'choice', label: 'Choice', options }),
        el({ type: 'radio', key: 'pick', label: 'Pick', options }),
        el({ type: 'multiselect', key: 'many', label: 'Many', options }),
        el({ type: 'checkbox', key: 'agree', label: 'I agree' }),
        el({ type: 'toggle', key: 'urgent', label: 'Urgent' }),
        el({ type: 'rating', key: 'stars', label: 'Rating', max: 4 }),
        el({ type: 'hidden', key: 'secret', label: 'Secret', default: 'x' }),
        el({
            type: 'calculated',
            key: 'total',
            label: 'Total',
            format: 'money',
            currency: 'EUR',
            expression: { 'dec.mul': [{ var: 'qty' }, { var: 'price' }] },
        }),
    ]),
]);

export const orderForm: FormDefinition = definition([
    page('order', [
        el({
            type: 'repeater',
            key: 'items',
            label: 'Items',
            minItems: 1,
            maxItems: 3,
            itemLabel: 'Item',
            addLabel: 'Add item',
            elements: [
                el({ type: 'text', key: 'title', label: 'Title', required: true }),
                el({ type: 'number', key: 'qty', label: 'Qty' }),
                el({ type: 'money', key: 'price', label: 'Unit price', currency: 'EUR' }),
                el({
                    type: 'calculated',
                    key: 'line',
                    label: 'Line total',
                    format: 'money',
                    currency: 'EUR',
                    expression: { 'dec.mul': [{ var: 'qty' }, { var: 'price' }] },
                }),
            ],
        }),
        el({
            type: 'calculated',
            key: 'sum',
            label: 'Sum',
            format: 'money',
            currency: 'EUR',
            expression: { sum: [{ var: 'items' }, 'line'] },
        }),
    ]),
]);

export function tableForm(rows = 3): FormDefinition {
    return definition(
        [
            page('t', [
                el({
                    type: 'repeater',
                    key: 'lines',
                    label: 'Lines',
                    display: 'table',
                    elements: [
                        el({
                            type: 'section',
                            key: 'grp',
                            elements: [el({ type: 'text', key: 'item', label: 'Item' })],
                        }),
                        el({ type: 'number', key: 'amount', label: 'Amount' }),
                        el({ type: 'heading', key: 'h', text: 'ignored in table' }),
                    ],
                }),
            ]),
        ],
        { title: `Table ${String(rows)}` },
    );
}
