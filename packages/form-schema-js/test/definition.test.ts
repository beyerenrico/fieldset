import { describe, expect, it } from 'vitest';
import { cycleStarts, dependencyGraph, evaluationOrder } from '../src/definition/dependencies.ts';
import { compilePattern, usesForbiddenSyntax } from '../src/definition/pattern.ts';
import { resolveReference, targetElement } from '../src/definition/resolve.ts';
import { DefinitionError, isDefinitionModel, parseDefinition } from '../src/index.ts';
import { el, form, page } from './helpers.ts';

function sample() {
    return form([
        el('text', 'name'),
        el('section', 'box', {
            elements: [
                el('columns', 'cols', {
                    columns: [
                        { elements: [el('number', 'left')] },
                        'not-a-column',
                        { elements: [el('heading', 'h', { text: 'x' })] },
                    ],
                }),
            ],
        }),
        el('repeater', 'items', {
            elements: [
                el('number', 'qty'),
                el('repeater', 'parts', { elements: [el('text', 'serial')] }),
            ],
        }),
        'not-an-element',
    ]);
}

describe('parseDefinition', () => {
    it('builds paths, scopes and document order', () => {
        const model = parseDefinition(sample());
        expect(model.nodes.map((node) => `${node.key}@${node.path}`)).toEqual([
            'name@/pages/0/elements/0',
            'box@/pages/0/elements/1',
            'cols@/pages/0/elements/1/elements/0',
            'left@/pages/0/elements/1/elements/0/columns/0/elements/0',
            'h@/pages/0/elements/1/elements/0/columns/2/elements/0',
            'items@/pages/0/elements/2',
            'qty@/pages/0/elements/2/elements/0',
            'parts@/pages/0/elements/2/elements/1',
            'serial@/pages/0/elements/2/elements/1/elements/0',
        ]);
        expect([...model.root.dataKeys.keys()]).toEqual(['name', 'left', 'items']);
        const serial = model.nodes.find((node) => node.key === 'serial');
        expect(serial?.scope.keyPath).toEqual(['items', 'parts']);
        expect(model.scopes).toHaveLength(3);
    });

    it('accepts JSON text, returns models unchanged and freezes everything', () => {
        const model = parseDefinition(JSON.stringify(sample()));
        expect(isDefinitionModel(model)).toBe(true);
        expect(parseDefinition(model)).toBe(model);
        expect(Object.isFrozen(model.definition)).toBe(true);
        expect(Object.isFrozen(model.nodes[0])).toBe(true);
        expect(isDefinitionModel({})).toBe(false);
    });

    it('does not share state with the input', () => {
        const input = sample();
        const model = parseDefinition(input);
        (input['pages'] as unknown[]).length = 0;
        expect(model.pages).toHaveLength(1);
    });

    it('rejects non-objects and tolerates missing structure', () => {
        expect(() => parseDefinition([])).toThrow(DefinitionError);
        expect(() => parseDefinition('42')).toThrow(DefinitionError);
        expect(parseDefinition({ pages: 'x' }).pages).toEqual([]);
        expect(parseDefinition({ pages: ['x', { key: 'p' }] }).pages[0]?.elements).toEqual([]);
        const untyped = parseDefinition(form([{ id: 'x' }]));
        expect(untyped.nodes[0]?.isData).toBe(false);
    });
});

describe('reference resolution', () => {
    const model = parseDefinition(
        form([
            el('number', 'total'),
            el('repeater', 'rows', { elements: [el('money', 'price', { currency: 'EUR' })] }),
        ]),
    );
    const rowScope = model.nodes.find((node) => node.key === 'price')?.scope ?? model.root;

    it.each([
        ['', 'scope'],
        ['$today', 'builtin'],
        ['$today.x', 'unknown'],
        ['$user.email', 'builtin'],
        ['$user.phone', 'unknown'],
        ['$form.version', 'builtin'],
        ['$root', 'scope'],
        ['$root.total', 'element'],
        ['$root.price', 'unknown'],
        ['$row', 'unknown'],
        ['total', 'element'],
        ['nope', 'unknown'],
    ])('root scope: %s → %s', (path, kind) => {
        expect(resolveReference(path, model.root).kind).toBe(kind);
    });

    it.each([
        ['$row', 'scope'],
        ['$row.price', 'element'],
        ['$row.total', 'unknown'],
        ['price', 'element'],
        ['total', 'element'],
    ])('row scope: %s → %s', (path, kind) => {
        expect(resolveReference(path, rowScope).kind).toBe(kind);
    });

    it('follows repeater indexes to row elements', () => {
        expect(targetElement(resolveReference('rows.0.price', model.root))?.key).toBe('price');
        expect(targetElement(resolveReference('rows.x.price', model.root))).toBeNull();
        expect(targetElement(resolveReference('rows.0.nope', model.root))).toBeNull();
        expect(targetElement(resolveReference('total.x', model.root))).toBeNull();
        expect(targetElement(resolveReference('$today', model.root))).toBeNull();
    });
});

describe('calculation dependencies', () => {
    it('orders row calculations before aggregates and tolerates cycles', () => {
        const model = parseDefinition(
            form([
                el('calculated', 'net', { expression: { sum: [{ var: 'rows' }, 'line'] } }),
                el('repeater', 'rows', {
                    elements: [
                        el('number', 'qty'),
                        el('calculated', 'line', { expression: { var: 'qty' } }),
                    ],
                }),
                el('calculated', 'a', { expression: { var: 'b' } }),
                el('calculated', 'b', { expression: { var: ['a'] } }),
                el('calculated', 'c', { expression: { map: [{ var: 'rows' }, { var: 'c' }] } }),
                el('calculated', 'd', { expression: { var: { cat: ['n', 'et'] } } }),
            ]),
        );
        const graph = dependencyGraph(model);
        expect(evaluationOrder(graph).map((node) => node.key)).toEqual([
            'line',
            'net',
            'c',
            'd',
            'a',
            'b',
        ]);
        expect(cycleStarts(graph).map((node) => node.key)).toEqual(['a']);
    });
});

describe('patterns', () => {
    it('rejects lookbehind and backreferences outside classes', () => {
        expect(usesForbiddenSyntax('(?<=a)b')).toBe(true);
        expect(usesForbiddenSyntax('(?<!a)b')).toBe(true);
        expect(usesForbiddenSyntax('(a)\\1')).toBe(true);
        expect(usesForbiddenSyntax('(?<n>a)\\k<n>')).toBe(true);
        expect(usesForbiddenSyntax('[\\1(?<=]')).toBe(false);
        expect(usesForbiddenSyntax('(?<name>a)\\d')).toBe(false);
    });

    it('compiles in Unicode mode with a legacy fallback and caches results', () => {
        expect(compilePattern('^\\w+$')?.flags).toBe('u');
        expect(compilePattern('\\-')?.flags).toBe('');
        expect(compilePattern('(')).toBeNull();
        expect(compilePattern('(')).toBeNull();
        expect(compilePattern('(?<=x)')).toBeNull();
    });
});

describe('page builder helper', () => {
    it('creates pages for multi-page forms', () => {
        const model = parseDefinition(
            form([], { pages: [page('a', [el('text', 'x')]), page('b', [])] }),
        );
        expect(model.pages.map((item) => item.key)).toEqual(['a', 'b']);
    });
});
