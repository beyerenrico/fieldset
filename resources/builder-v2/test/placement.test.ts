import { describe, expect, it } from 'vitest';
import { insertionPoint, needsRepeaterConfirm, placeNewNode } from '../src/placement.ts';
import { applyOperation } from '../src/store/ops.ts';
import { contractDefinition, ID } from './support/fixtures.ts';

const SECTION = { type: 'section', key: 's', elements: [] };

describe('placement', () => {
    it('inserts after the selected element, at the end of a selected page, else of the last page', () => {
        const definition = contractDefinition();
        expect(insertionPoint(definition, { kind: 'element', id: ID.day })).toEqual({
            target: { kind: 'column', parentId: ID.cols, column: 0 },
            index: 1,
        });
        expect(insertionPoint(definition, { kind: 'page', id: ID.pageStart })).toEqual({
            target: { kind: 'page', pageId: ID.pageStart },
            index: 2,
        });
        expect(insertionPoint(definition, { kind: 'settings' })).toEqual({
            target: { kind: 'page', pageId: ID.pageItems },
            index: 1,
        });
        expect(insertionPoint(definition, null)).toEqual({
            target: { kind: 'page', pageId: ID.pageItems },
            index: 1,
        });
        expect(insertionPoint({ pages: [] }, null)).toBeNull();
    });

    it('falls back to the end of the page when the nesting rules refuse the place', () => {
        const definition = contractDefinition();
        const column = {
            target: { kind: 'column', parentId: ID.cols, column: 0 },
            index: 1,
        } as const;
        expect(placeNewNode(definition, SECTION, column, 3)).toEqual({
            target: { kind: 'page', pageId: ID.pageStart },
            index: 2,
        });
        expect(placeNewNode(definition, SECTION, column, 1)).toBe('too_deep');
        expect(placeNewNode(definition, { type: 'text', key: 't' }, column, 3)).toEqual(column);
        expect(
            placeNewNode(
                definition,
                SECTION,
                { target: { kind: 'page', pageId: ID.pageStart }, index: 9 },
                3,
            ),
        ).toBe('bad_index');
    });

    it('asks only for published elements whose repeater changes', () => {
        const before = contractDefinition();
        const out = applyOperation(before, {
            op: 'move',
            id: ID.description,
            target: { kind: 'page', pageId: ID.pageItems },
            index: 0,
        });
        expect(needsRepeaterConfirm(before, out, ID.description, new Set([ID.description]))).toBe(
            true,
        );
        expect(needsRepeaterConfirm(before, out, ID.description, new Set())).toBe(false);
        const within = applyOperation(before, {
            op: 'move',
            id: ID.description,
            target: { kind: 'children', parentId: ID.items },
            index: 1,
        });
        expect(
            needsRepeaterConfirm(before, within, ID.description, new Set([ID.description])),
        ).toBe(false);
        const intoRepeater = applyOperation(before, {
            op: 'move',
            id: ID.details,
            target: { kind: 'page', pageId: ID.pageItems },
            index: 0,
        });
        expect(needsRepeaterConfirm(before, intoRepeater, ID.details, new Set([ID.day]))).toBe(
            false,
        );
        expect(needsRepeaterConfirm(before, before, ID.pageStart, new Set([ID.pageStart]))).toBe(
            false,
        );
    });
});
