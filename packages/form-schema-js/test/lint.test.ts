import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { hasLintErrors, lint, type LintFinding } from '../src/index.ts';
import { isValidNumbering, tokenizeNumbering } from '../src/lint/numbering.ts';
import { el, form, page } from './helpers.ts';

const brief = (findings: readonly LintFinding[]) =>
    findings.map((item) => `${item.code} ${item.path}`);

describe('lint rules beyond the conformance suite', () => {
    it('reports duplicate ids (case-insensitive) at later occurrences', () => {
        const a = el('text', 'a', { id: '00000000-0000-4000-8000-0000000000AA' });
        const b = el('text', 'b', { id: '00000000-0000-4000-8000-0000000000aa' });
        expect(brief(lint(form([a, b])))).toEqual(['duplicate_id /pages/0/elements/1']);
    });

    it('reports reserved types at the type property', () => {
        expect(brief(lint(form([el('record', 'r')])))).toEqual([
            'reserved_type /pages/0/elements/0/type',
        ]);
    });

    it('checks references of page visibility, $row and built-in members', () => {
        const definition = form([], {
            pages: [
                page('main', [el('text', 'a', { visibleIf: { var: '$row.a' } })], {
                    visibleIf: { var: '$user.phone' },
                }),
            ],
        });
        expect(brief(lint(definition))).toEqual([
            'unknown_reference /pages/0/visibleIf',
            'unknown_reference /pages/0/elements/0/visibleIf',
        ]);
    });

    it('resolves signerNameFrom through the scope chain', () => {
        const definition = form([
            el('text', 'customer'),
            el('repeater', 'rows', {
                elements: [el('signature', 'sig', { signerNameFrom: 'customer' })],
            }),
            el('signature', 'outer', { signerNameFrom: 'sig' }),
        ]);
        expect(brief(lint(definition))).toEqual([
            'unknown_reference /pages/0/elements/2/signerNameFrom',
        ]);
    });

    it('detects decimal misuse through $root and repeater index paths', () => {
        const definition = form([
            el('money', 'price', { currency: 'EUR' }),
            el('repeater', 'rows', {
                elements: [
                    el('number', 'qty'),
                    el('text', 't', { visibleIf: { '<': [{ var: '$root.price' }, 1] } }),
                ],
            }),
            el('text', 'u', { visibleIf: { '==': [{ var: 'rows.0.qty' }, '1'] } }),
            el('text', 'v', {
                visibleIf: { some: [{ var: 'rows' }, { '>': [{ var: 'qty' }, 1] }] },
            }),
            el('calculated', 'w', {
                format: 'text',
                expression: { 'dec.round': [{ var: 'price' }, 2.5] },
            }),
        ]);
        expect(brief(lint(definition))).toEqual([
            'decimal_builtin_operator /pages/0/elements/1/elements/1/visibleIf',
            'decimal_builtin_operator /pages/0/elements/2/visibleIf',
            'float_literal /pages/0/elements/4/expression',
        ]);
    });

    it('reports unknown operators inside per-item arguments but not references', () => {
        const definition = form([
            el('repeater', 'rows', { elements: [el('text', 'x')] }),
            el('text', 'y', {
                visibleIf: { all: [{ var: 'rows' }, { regex: [{ var: 'whatever' }] }] },
            }),
        ]);
        expect(brief(lint(definition))).toEqual(['unknown_operator /pages/0/elements/1/visibleIf']);
    });

    it('checks row keys only for repeater aggregates', () => {
        const definition = form([
            el('repeater', 'rows', { elements: [el('number', 'q')] }),
            el('calculated', 'a', { expression: { max: [{ var: 'rows' }, 'q'] } }),
            el('calculated', 'b', { expression: { min: [{ var: 'missing_list' }, 'q'] } }),
            el('calculated', 'c', { expression: { max: ['1', '2'] } }),
        ]);
        expect(brief(lint(definition))).toEqual([
            'unknown_reference /pages/0/elements/2/expression',
        ]);
    });

    it('reports distinct references in one property separately and duplicates once', () => {
        const definition = form([
            el('text', 'a', { visibleIf: { and: [{ var: 'x' }, { var: 'y' }, { var: 'x' }] } }),
        ]);
        expect(lint(definition).map((item) => item.params)).toEqual([
            { reference: 'x' },
            { reference: 'y' },
        ]);
    });

    it('compares history by scoped key and ignores non-object entries', () => {
        const published = form([
            el('repeater', 'rows', { elements: [el('number', 'q')] }),
            el('text', 'q'),
        ]);
        const current = form([
            el('repeater', 'rows', { elements: [el('text', 'q')] }),
            el('text', 'q'),
        ]);
        expect(brief(lint(current, [published, 'garbage']))).toEqual([
            'key_type_changed /pages/0/elements/0/elements/0',
        ]);
    });

    it('flags lint errors but not warnings as blocking', () => {
        expect(hasLintErrors(lint(form([el('text', 'a', { role: 'nope.nope' })])))).toBe(false);
        expect(hasLintErrors(lint(form([el('text', 'a'), el('text', 'a')])))).toBe(true);
    });
});

describe('numbering formats', () => {
    it.each([
        ['SR-{YYYY}-{SEQ:5}', 'yearly', true],
        ['{SEQ:12}', 'never', true],
        ['{SEQ:13}', 'never', false],
        ['{SEQ:0}', 'never', false],
        ['A{SEQ:1}{SEQ:2}', 'never', false],
        ['{YY}-{SEQ:3}', 'monthly', false],
        ['{MM}-{SEQ:3}', 'monthly', false],
        ['N {SEQ:3}', 'never', false],
    ])('%s with reset %s → %s', (format, reset, valid) => {
        expect(isValidNumbering(format, reset)).toBe(valid);
    });

    it('tokenizes literals and tokens', () => {
        expect(tokenizeNumbering('A.{YY}/{SEQ:4}')).toEqual(['literal', 'YY', 'literal', 'SEQ']);
    });

    it('ignores definitions without a numbering format', () => {
        expect(
            lint(form([el('text', 'a')], { settings: { numbering: { reset: 'yearly' } } })),
        ).toEqual([]);
    });
});

describe('conformance evaluate definitions', () => {
    const folder = join(
        dirname(fileURLToPath(import.meta.url)),
        '../../../spec/conformance/evaluate',
    );
    it.each(readdirSync(folder))('%s lints clean', (file) => {
        const suite = JSON.parse(readFileSync(join(folder, file), 'utf8')) as {
            definition: unknown;
        };
        expect(lint(suite.definition)).toEqual([]);
    });
});
