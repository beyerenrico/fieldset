import type { JsonObject } from '@form-schema-app/form-schema-js';
import { describe, expect, it } from 'vitest';
import type { InspectorScope } from '../src/contract.ts';
import { insertNode } from '../src/store/ops.ts';
import { inspectorScope, keysInScopeOf, uniqueKey } from '../src/store/scope.ts';
import { contractDefinition, contractFixture, ID } from './support/fixtures.ts';

describe('inspector scope', () => {
    it('matches the contract fixture for a top-level element', () => {
        const scope = inspectorScope(
            contractDefinition(),
            { kind: 'element', id: ID.customer },
            new Set(),
        );
        expect(scope).toEqual(contractFixture('scope') as InspectorScope);
    });

    it('uses the row scope inside a repeater', () => {
        const scope = inspectorScope(
            contractDefinition(),
            { kind: 'element', id: ID.qty },
            new Set([ID.qty]),
        );
        expect(scope.scopeKeys).toEqual(['description', 'qty']);
        expect(scope.autoKey).toBe(true);
    });

    it('uses the root scope for pages, settings and repeaters themselves', () => {
        const definition = contractDefinition();
        for (const selection of [
            { kind: 'settings' } as const,
            { kind: 'page', id: ID.pageItems } as const,
            { kind: 'element', id: ID.items } as const,
            null,
        ]) {
            expect(inspectorScope(definition, selection, new Set()).scopeKeys).toEqual([
                'customer',
                'details',
                'cols',
                'day',
                'items',
            ]);
        }
    });

    it('treats text calculations as text and nests row keys of inner repeaters', () => {
        const inner: JsonObject = {
            id: '0192f1c4-0000-7000-8000-0000000000d1',
            type: 'repeater',
            key: 'parts',
            label: 'Teile',
            elements: [
                {
                    id: '0192f1c4-0000-7000-8000-0000000000d2',
                    type: 'calculated',
                    key: 'code',
                    format: 'text',
                    expression: 'x',
                },
            ],
        };
        const definition = insertNode(
            contractDefinition(),
            { kind: 'children', parentId: ID.items },
            2,
            inner,
        );
        const scope = inspectorScope(definition, null, new Set());
        expect(scope.rowKeys).toEqual({ items: ['description', 'qty', 'parts'], parts: ['code'] });
        expect(scope.keyTypes.code).toBe('text');
    });
});

describe('unique keys', () => {
    it('collects every key (layout included) of the target scope', () => {
        const definition = contractDefinition();
        expect(
            [...keysInScopeOf(definition, { kind: 'page', pageId: ID.pageItems })].sort(),
        ).toEqual(['cols', 'customer', 'day', 'details', 'items']);
        expect([
            ...keysInScopeOf(definition, { kind: 'column', parentId: ID.cols, column: 1 }),
        ]).toContain('details');
        expect([...keysInScopeOf(definition, { kind: 'children', parentId: ID.items })]).toEqual([
            'description',
            'qty',
        ]);
        expect([...keysInScopeOf(definition, { kind: 'pages' })]).toEqual(['start', 'items_page']);
    });

    it('suffixes taken keys within 64 characters', () => {
        expect(uniqueKey('text', new Set())).toBe('text');
        expect(uniqueKey('text', new Set(['text', 'text_2']))).toBe('text_3');
        const long = 'a'.repeat(64);
        expect(uniqueKey(long, new Set([long]))).toBe(`${'a'.repeat(62)}_2`);
    });
});
