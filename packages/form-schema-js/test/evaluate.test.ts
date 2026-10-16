import { describe, expect, it } from 'vitest';
import { evaluate, parseDefinition, type ValidationError } from '../src/index.ts';
import { CONTEXT, deepFreeze, el, form, type Raw } from './helpers.ts';

const errorsOf = (definition: Raw, data: unknown): ValidationError[] => [
    ...evaluate(definition, data, CONTEXT).errors,
];

const codes = (definition: Raw, data: unknown): string[] =>
    errorsOf(definition, data).map((error) => `${error.pointer} ${error.code}`);

describe('normalization', () => {
    const definition = form([
        el('repeater', 'rows', { elements: [el('text', 'note', { required: true })] }),
    ]);

    it('drops non-object rows and reports them at their input index', () => {
        const result = evaluate(definition, { rows: [5, { note: 'a' }, null, {}] }, CONTEXT);
        expect(result.data).toEqual({ rows: [{ note: 'a' }, {}] });
        expect(result.errors.map((error) => `${error.pointer} ${error.code}`)).toEqual([
            '/rows/0 type_mismatch',
            '/rows/2 type_mismatch',
            '/rows/1/note required',
        ]);
    });

    it('keeps a null repeater as an empty value', () => {
        const result = evaluate(definition, { rows: null }, CONTEXT);
        expect(result).toMatchObject({ data: { rows: null }, errors: [] });
    });

    it('treats a non-object submission as empty and never mutates inputs', () => {
        expect(evaluate(definition, 'nope', CONTEXT).data).toEqual({});
        const frozenDefinition = deepFreeze(
            form([el('money', 'm', { currency: 'EUR', default: '1' })]),
        );
        const frozenData = deepFreeze({ m: null, x: 1 });
        const result = evaluate(frozenDefinition, frozenData, CONTEXT);
        expect(result.data).toEqual({ m: '1.00' });
        expect(frozenData).toEqual({ m: null, x: 1 });
    });

    it('accepts a parsed model and an empty context', () => {
        const model = parseDefinition(
            form([el('text', 'who', { default: { cat: [{ var: '$user.name' }, '?'] } })]),
        );
        expect(evaluate(model, {}).data).toEqual({ who: '?' });
    });
});

describe('scopes and calculations', () => {
    it('nests repeaters, uses $row and aggregates inner calculations', () => {
        const definition = form([
            el('repeater', 'orders', {
                elements: [
                    el('repeater', 'lines', {
                        elements: [
                            el('number', 'qty'),
                            el('calculated', 'double', {
                                expression: { 'dec.mul': [{ var: '$row.qty' }, 2] },
                            }),
                        ],
                    }),
                    el('calculated', 'order_total', {
                        expression: { sum: [{ var: 'lines' }, 'double'] },
                        scale: 1,
                    }),
                ],
            }),
            el('calculated', 'grand', {
                format: 'money',
                expression: { sum: [{ var: 'orders' }, 'order_total'] },
            }),
            el('calculated', 'list', {
                format: 'text',
                expression: { map: [{ var: 'orders' }, { var: 'order_total' }] },
            }),
        ]);
        const result = evaluate(
            definition,
            { orders: [{ lines: [{ qty: '1' }, { qty: '2.5' }] }, { lines: [] }] },
            CONTEXT,
        );
        expect(result.errors).toEqual([]);
        expect(result.data).toEqual({
            orders: [
                {
                    lines: [
                        { qty: '1', double: '2.000000' },
                        { qty: '2.5', double: '5.000000' },
                    ],
                    order_total: '7.0',
                },
                { lines: [], order_total: '0.0' },
            ],
            grand: '7.00',
            list: '7.0,0.0',
        });
    });

    it('ignores out-of-range scales of unvalidated definitions', () => {
        const definition = form([
            el('calculated', 'big', { expression: '1', scale: 1e9 }),
            el('calculated', 'neg', { format: 'money', expression: '1', scale: -1 }),
            el('money', 'm', { currency: 'EUR', scale: 9 }),
        ]);
        expect(evaluate(definition, { m: '1.5' }, CONTEXT)).toMatchObject({
            data: { big: '1.000000', neg: '1.00', m: '1.50' },
            errors: [],
        });
    });

    it('evaluates logic nested deeper than 512 levels as null', () => {
        let deep: unknown = true;
        for (let level = 0; level < 600; level++) {
            deep = { '!!': [deep] };
        }
        const definition = form([el('text', 'a', { visibleIf: deep })]);
        expect(evaluate(definition, { a: 'x' }, CONTEXT).visibility).toEqual({ '/a': false });
    });

    it('rejects non-finite location numbers and keeps them for reporting', () => {
        const definition = form([el('location', 'loc'), el('text', 't')]);
        const loc = { lat: 1, lng: 2, accuracy: Infinity, capturedAt: '2026-10-16T09:41:00Z' };
        expect(errorsOf(definition, { loc, t: -Infinity })).toEqual([
            { pointer: '/loc', code: 'invalid_location' },
            { pointer: '/t', code: 'type_mismatch' },
        ]);
    });

    it('evaluates unknown operators as null', () => {
        const definition = form([el('text', 'a', { visibleIf: { nope: [] } })]);
        expect(evaluate(definition, { a: 'x' }, CONTEXT)).toMatchObject({
            data: {},
            visibility: { '/a': false },
        });
    });

    it('needs further rounds when visibility depends on a later default', () => {
        const definition = form([
            el('text', 'shown', { visibleIf: { '==': [{ var: 'mode' }, 'on'] }, required: true }),
            el('text', 'mode', { default: 'on' }),
        ]);
        expect(codes(definition, {})).toEqual(['/shown required']);
    });

    it('stops oscillating definitions after five rounds', () => {
        const definition = form([
            el('text', 'x', { visibleIf: { '!': [{ var: 'c' }] } }),
            el('calculated', 'c', { format: 'text', expression: { var: 'x' } }),
        ]);
        const result = evaluate(definition, { x: 'v' }, CONTEXT);
        expect(result.visibility['/x']).toBe(true);
        expect(result.data).toEqual({ x: 'v', c: 'v' });
    });

    it('applies repeater defaults and normalizes their rows', () => {
        const definition = form([
            el('repeater', 'rows', {
                default: { merge: [[{ var: '$user' }], 5] },
                elements: [el('money', 'amount', { currency: 'EUR', default: '2' })],
            }),
            el('repeater', 'other', { default: 'not rows', elements: [el('text', 't')] }),
        ]);
        const result = evaluate(definition, {}, CONTEXT);
        expect(result.data).toEqual({ rows: [{ amount: '2.00' }] });
    });
});

describe('hidden values', () => {
    const definition = form([el('hidden', 'ref', { default: 'web' }), el('hidden', 'raw')]);

    it('keeps strings, integers and booleans', () => {
        expect(evaluate(definition, { ref: 7, raw: true }, CONTEXT).data).toEqual({
            ref: 7,
            raw: true,
        });
    });

    it('replaces other values by the default, otherwise reports type_mismatch', () => {
        const result = evaluate(definition, { ref: { a: 1 }, raw: 1.5 }, CONTEXT);
        expect(result.data).toEqual({ ref: 'web', raw: 1.5 });
        expect(result.errors).toEqual([{ pointer: '/raw', code: 'type_mismatch' }]);
    });
});

describe('validation rules', () => {
    it('requiredIf, checkbox types and repeater bounds', () => {
        const definition = form([
            el('toggle', 'flag'),
            el('text', 'why', { requiredIf: { var: 'flag' } }),
            el('checkbox', 'ok', { required: true }),
            el('repeater', 'need', { required: true, elements: [el('text', 'n')] }),
            el('repeater', 'few', { maxItems: 1, elements: [el('text', 'f')] }),
        ]);
        expect(errorsOf(definition, { flag: true, ok: 'yes', few: [{}, {}] })).toEqual([
            { pointer: '/why', code: 'required' },
            { pointer: '/ok', code: 'type_mismatch' },
            { pointer: '/need', code: 'required' },
            { pointer: '/few', code: 'max_items', params: { limit: 1 } },
        ]);
    });

    it('length, pattern and count constraints with params', () => {
        const definition = form([
            el('email', 'mail', { validation: { maxLength: 5, pattern: '^x' } }),
            el('phone', 'tel', { validation: { pattern: '^\\+49' } }),
            el('text', 'free', { validation: { pattern: '(' } }),
            el('multiselect', 'tags', {
                options: [
                    { value: 'a', label: 'A' },
                    { value: 'b', label: 'B' },
                ],
                validation: { min: 2 },
            }),
            el('multiselect', 'none', {
                options: [{ value: 'a', label: 'A' }],
                validation: { min: 1 },
            }),
            el('multiselect', 'bad', { options: [{ value: 'a', label: 'A' }, 'junk'] }),
        ]);
        expect(
            errorsOf(definition, {
                mail: 'a@b.de',
                tel: '030 1',
                free: 'x',
                tags: ['a'],
                none: [],
                bad: [1],
            }),
        ).toEqual([
            { pointer: '/mail', code: 'max_length', params: { limit: 5 } },
            { pointer: '/tel', code: 'pattern' },
            { pointer: '/tags', code: 'min', params: { limit: 2 } },
            { pointer: '/bad', code: 'invalid_option' },
        ]);
    });

    it('numeric bounds and steps relative to min', () => {
        const definition = form([
            el('number', 'n', { validation: { min: '0.5', step: '0.25', max: 'oops' } }),
            el('money', 'm', { currency: 'EUR', scale: 0, validation: { max: 10 } }),
        ]);
        expect(codes(definition, { n: '1.0', m: '10' })).toEqual([]);
        expect(codes(definition, { n: '0.6', m: '11' })).toEqual(['/n step', '/m max']);
        expect(errorsOf(definition, { m: '1.5' })).toEqual([
            { pointer: '/m', code: 'scale_exceeded', params: { scale: 0 } },
        ]);
        expect(codes(definition, { n: true })).toEqual(['/n type_mismatch']);
    });

    it('temporal bounds compare instants and seconds', () => {
        const definition = form([
            el('time', 'at', { validation: { min: '08:00', max: '17:30:00' } }),
            el('datetime', 'when', { validation: { min: '2026-10-16T10:00:00+02:00', max: 7 } }),
        ]);
        expect(codes(definition, { at: '17:30', when: '2026-10-16T08:00:00Z' })).toEqual([]);
        expect(codes(definition, { at: '07:59:59', when: '2026-10-16T07:59:59Z' })).toEqual([
            '/at min',
            '/when min',
        ]);
        expect(codes(definition, { at: '17:30:01' })).toEqual(['/at max']);
    });

    it('ratings, attachments, signatures and locations', () => {
        const definition = form([
            el('rating', 'r', { max: 3 }),
            el('photo', 'p', { multiple: true }),
            el('file', 'f'),
            el('signature', 's'),
            el('location', 'l'),
            el('select', 'c', { options: [{ value: 'a', label: 'A' }] }),
        ]);
        const location = {
            lat: 1,
            lng: 2,
            accuracy: 3,
            capturedAt: '2026-10-16T09:41:00Z',
            alt: 4,
        };
        expect(codes(definition, { r: 4, p: 'x', f: 5, s: 'sig', l: location, c: ['a'] })).toEqual([
            '/r out_of_range',
            '/p invalid_attachment',
            '/f invalid_attachment',
            '/s type_mismatch',
            '/l invalid_location',
            '/c type_mismatch',
        ]);
        expect(codes(definition, { r: true, l: [] })).toEqual(['/r out_of_range']);
    });
});
