import { parseDefinition } from '@fieldset/fieldset-js';
import { renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { createMemoryAttachmentAdapter } from '../src/attachments/memory-adapter.ts';
import { parseDecimalInput, toInputText } from '../src/data/decimal-input.ts';
import { materializeDefaults } from '../src/data/materialize.ts';
import { getAt, pointerId, setAt, toPointer } from '../src/data/path.ts';
import { fromLocalInputValue, toLocalInputValue, toRfc3339 } from '../src/format/datetime.ts';
import {
    currencyAffix,
    decimalSeparator,
    formatDateTime,
    formatDecimal,
    formatMoney,
} from '../src/format/intl.ts';
import { useFormEvaluation } from '../src/hooks/useFormEvaluation.ts';
import { errorMessage } from '../src/i18n/errors.ts';
import { interpolate, rendererMessages, resolveMessages } from '../src/i18n/messages.ts';
import { rowIndexOf } from '../src/repeater/useRowWindow.ts';
import { definition, el, orderForm, page } from './fixtures/definitions.ts';

describe('parseDecimalInput', () => {
    it.each([
        ['', { kind: 'empty' }],
        ['  ', { kind: 'empty' }],
        ['12', { kind: 'decimal', value: '12' }],
        ['007', { kind: 'decimal', value: '7' }],
        ['1,5', { kind: 'decimal', value: '1.5' }],
        ['.5', { kind: 'decimal', value: '0.5' }],
        ['5.', { kind: 'decimal', value: '5' }],
        ['-0,00', { kind: 'decimal', value: '0.00' }],
        ['-12.30', { kind: 'decimal', value: '-12.30' }],
        ['+3', { kind: 'decimal', value: '3' }],
        ['1.234,56', { kind: 'decimal', value: '1234.56' }],
        ['1,234.56', { kind: 'decimal', value: '1234.56' }],
        ['1.000.000', { kind: 'decimal', value: '1000000' }],
        ['1 000,5', { kind: 'decimal', value: '1000.5' }],
        ['abc', { kind: 'invalid', text: 'abc' }],
        ['-', { kind: 'invalid', text: '-' }],
        ['1e5', { kind: 'invalid', text: '1e5' }],
    ])('%j', (input, expected) => {
        expect(parseDecimalInput(input)).toEqual(expected);
    });

    it('formats stored values for the input', () => {
        expect(toInputText('1.50', ',')).toBe('1,50');
        expect(toInputText('oops', ',')).toBe('oops');
        expect(toInputText(3, ',')).toBe('3');
        expect(toInputText(null, ',')).toBe('');
    });
});

describe('data paths', () => {
    it('reads and writes immutably, creating rows', () => {
        const data = { items: [{ qty: '1' }], other: true };
        const next = setAt(data, ['items', 2, 'qty'], '3');
        expect(next).toEqual({ items: [{ qty: '1' }, {}, { qty: '3' }], other: true });
        expect(data.items).toHaveLength(1);
        expect(getAt(next, ['items', 2, 'qty'])).toBe('3');
        expect(getAt(next, ['items', 9, 'qty'])).toBeUndefined();
        expect(getAt(next, ['missing', 'x'])).toBeUndefined();
        expect(setAt(next, ['other'], undefined)).toEqual({ items: next['items'] });
        expect(setAt({ items: 'bad' }, ['items', 0, 'a'], 'b')).toEqual({ items: [{ a: 'b' }] });
    });

    it('builds pointers and DOM ids', () => {
        expect(toPointer(['a/b', 0, 'c~d'])).toBe('/a~1b/0/c~0d');
        expect(pointerId('x', '/items/0/qty')).toBe('x-f-items-0-qty');
    });

    it('parses row indexes below a repeater', () => {
        expect(rowIndexOf('/items', '/items/12/qty')).toBe(12);
        expect(rowIndexOf('/items', '/other/1')).toBeNull();
        expect(rowIndexOf('/items', '/items/x')).toBeNull();
    });
});

describe('materializeDefaults', () => {
    const model = parseDefinition(orderForm);

    it('fills empty values, skips calculated ones and keeps identity when unchanged', () => {
        const raw = { items: [{ title: null }] };
        const evaluated = { items: [{ title: 'Default', line: '1.00' }], sum: '1.00' };
        expect(materializeDefaults(model, raw, evaluated)).toEqual({
            items: [{ title: 'Default' }],
        });
        const same = { items: [{ title: 'x' }] };
        expect(materializeDefaults(model, same, { items: [{ title: 'x' }] })).toBe(same);
    });

    it('copies whole arrays when raw has none and ignores mismatched rows', () => {
        expect(materializeDefaults(model, {}, { items: [{ title: 'a' }] })).toEqual({
            items: [{ title: 'a' }],
        });
        const raw = { items: [{}, {}] };
        expect(materializeDefaults(model, raw, { items: [{ title: 'a' }] })).toBe(raw);
    });
});

describe('date-time conversion', () => {
    it('converts between RFC 3339 and local input values', () => {
        expect(toLocalInputValue('2026-07-01T10:00:30+02:00')).toBe('2026-07-01T10:00:30');
        expect(toLocalInputValue('garbage')).toBe('');
        expect(toLocalInputValue(5)).toBe('');
        expect(fromLocalInputValue('2026-07-01T10:00')).toBe('2026-07-01T10:00:00+02:00');
        expect(fromLocalInputValue('2026-07-01T10:00:15')).toBe('2026-07-01T10:00:15+02:00');
        expect(fromLocalInputValue('nope')).toBeNull();
        expect(toRfc3339(new Date(Date.UTC(2026, 0, 1, 12)))).toBe('2026-01-01T13:00:00+01:00');
    });
});

describe('Intl formatting', () => {
    it('formats decimals and money exactly', () => {
        expect(decimalSeparator('de-DE')).toBe(',');
        expect(formatDecimal('12345678901234567890.12', 'en-US')).toBe(
            '12,345,678,901,234,567,890.12',
        );
        expect(formatMoney('5', 'USD', 'en-US')).toBe('$5');
        expect(formatMoney('5.5', 'NOT_A_CURRENCY', 'en-US')).toBe('5.5 NOT_A_CURRENCY');
        expect(currencyAffix('NOT_A_CURRENCY', 'en-US')).toEqual({
            symbol: 'NOT_A_CURRENCY',
            before: false,
        });
        expect(currencyAffix('EUR', 'de-DE')).toEqual({ symbol: '€', before: false });
        expect(formatDateTime('nope', 'en-US')).toBe('nope');
        expect(formatDateTime('2026-10-16T09:41:00+02:00', 'en-US')).toContain('2026');
        expect(formatDecimal('1.5', 'xx-invalid-locale-tag-!!')).toBe('1.5');
    });
});

describe('messages', () => {
    it('resolves catalogs and interpolates placeholders', () => {
        expect(resolveMessages('de-AT').next).toBe('Weiter');
        expect(resolveMessages('fr-FR').next).toBe('Next');
        expect(resolveMessages(undefined, { next: 'Go' }).next).toBe('Go');
        expect(interpolate('{a} and {b}', { a: 1 })).toBe('1 and {b}');
        expect(Object.keys(rendererMessages.de).sort()).toEqual(
            Object.keys(rendererMessages.en).sort(),
        );
    });

    it('localizes error codes with formatted params', () => {
        const de = resolveMessages('de');
        expect(
            errorMessage({ pointer: '/a', code: 'min', params: { limit: 1000.5 } }, de, 'de-DE'),
        ).toBe('Der Wert muss mindestens 1.000,5 sein.');
        expect(
            errorMessage(
                { pointer: '/a', code: 'max', params: { limit: '2026-01-01' } },
                de,
                'de-DE',
            ),
        ).toContain('2026-01-01');
        expect(
            errorMessage({ pointer: '/a', code: 'step', params: { step: '0.25' } }, de, 'de-DE'),
        ).toContain('0,25');
        expect(
            errorMessage({ pointer: '/a', code: 'min', params: { limit: [1] } }, de, 'de-DE'),
        ).toContain('[1]');
        expect(errorMessage({ pointer: '/a', code: 'whatever' }, de, 'de-DE')).toBe(
            de.unknownError,
        );
        expect(
            errorMessage({ pointer: '/a', code: 'required', message: 'Custom' }, de, 'de-DE'),
        ).toBe('Custom');
    });
});

describe('memory adapter', () => {
    it('stores, describes and removes files', async () => {
        const adapter = createMemoryAttachmentAdapter();
        const info = await adapter.upload({
            file: new Blob(['x'], { type: 'image/png' }),
            fileName: 'x.png',
            kind: 'photo',
            elementKey: 'p',
            pointer: '/p',
        });
        expect(info).toMatchObject({ fileName: 'x.png', mime: 'image/png', size: 1 });
        expect(adapter.get?.(info.id)).toEqual(info);
        expect(adapter.files.size).toBe(1);
        await adapter.remove?.(info.id, { kind: 'photo', elementKey: 'p', pointer: '/p' });
        expect(adapter.files.size).toBe(0);
        await adapter.upload({
            file: new Blob(['y']),
            fileName: 'y',
            kind: 'file',
            elementKey: 'f',
            pointer: '/f',
        });
        adapter.dispose();
        expect(adapter.files.size).toBe(0);
    });
});

describe('useFormEvaluation', () => {
    it('evaluates with the spec evaluator', () => {
        const form = definition([
            page('p', [el({ type: 'text', key: 'a', label: 'A', required: true })]),
        ]);
        const { result } = renderHook(() => useFormEvaluation(form, {}));
        expect(result.current.result.errors).toEqual([{ pointer: '/a', code: 'required' }]);
        expect(result.current.model.nodes).toHaveLength(1);
    });
});
