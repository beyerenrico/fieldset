import type { JsonObject } from '@fieldset/fieldset-js';
import { describe, expect, it } from 'vitest';
import type { Container, Operation } from '../src/contract.ts';
import { insertNode } from '../src/store/ops.ts';
import {
    canAddColumn,
    canDrop,
    canRemoveColumn,
    checkOperation,
    type DropSubject,
    type Refusal,
} from '../src/store/rules.ts';
import { containerHeight } from '../src/store/tree.ts';
import { contractDefinition, ID } from './support/fixtures.ts';

const MAX = 3;
const SECTION: JsonObject = { type: 'section', key: 's', elements: [] };
const NESTED: JsonObject = {
    type: 'section',
    key: 'outer',
    elements: [
        { id: '0192f1c4-0000-7000-8000-0000000000c1', type: 'section', key: 'inner', elements: [] },
    ],
};
const TEXT: JsonObject = { type: 'text', key: 't', label: 'T' };
const PAGE: JsonObject = { key: 'p', title: 'P', elements: [] };

const page = (pageId: string): Container => ({ kind: 'page', pageId });
const children = (parentId: string): Container => ({ kind: 'children', parentId });
const column = (parentId: string, index: number): Container => ({
    kind: 'column',
    parentId,
    column: index,
});

/** [name, subject, target, expected refusal] — list depths: page 1, details 2, cols columns 3. */
const MATRIX: [string, DropSubject, Container, Refusal | null][] = [
    ['page into the page list', { id: ID.pageItems }, { kind: 'pages' }, null],
    ['new page into the page list', { node: PAGE, page: true }, { kind: 'pages' }, null],
    ['page into a page', { id: ID.pageItems }, page(ID.pageStart), 'page_list_only'],
    ['page into a section', { id: ID.pageItems }, children(ID.details), 'page_list_only'],
    ['element into the page list', { id: ID.customer }, { kind: 'pages' }, 'not_in_page_list'],
    ['new element into the page list', { node: TEXT }, { kind: 'pages' }, 'not_in_page_list'],
    ['element into another page', { id: ID.customer }, page(ID.pageItems), null],
    ['element into a section', { id: ID.customer }, children(ID.details), null],
    ['element into a repeater', { id: ID.customer }, children(ID.items), null],
    ['element into a column', { id: ID.customer }, column(ID.cols, 1), null],
    ['section into itself', { id: ID.details }, children(ID.details), 'into_itself'],
    ['section into its own column', { id: ID.details }, column(ID.cols, 0), 'into_itself'],
    ['columns into own column', { id: ID.cols }, column(ID.cols, 1), 'into_itself'],
    ['repeater into a section (depth 2)', { id: ID.items }, children(ID.details), null],
    ['repeater into a column (depth 3)', { id: ID.items }, column(ID.cols, 1), 'too_deep'],
    ['new section into a column (depth 3)', { node: SECTION }, column(ID.cols, 0), 'too_deep'],
    ['new section into a section (depth 2)', { node: SECTION }, children(ID.details), null],
    [
        'nested sections into a section (depth 2+2)',
        { node: NESTED },
        children(ID.details),
        'too_deep',
    ],
    ['nested sections onto a page (depth 1+2)', { node: NESTED }, page(ID.pageStart), null],
    ['section holding columns into a repeater', { id: ID.details }, children(ID.items), 'too_deep'],
    ['text into a column (depth 3)', { node: TEXT }, column(ID.cols, 0), null],
    ['unknown node', { id: 'missing' }, page(ID.pageStart), 'unknown_node'],
    ['unknown page', { id: ID.customer }, page('missing'), 'unknown_target'],
    ['missing column', { id: ID.customer }, column(ID.cols, 5), 'unknown_target'],
    ['children of a non-container', { id: ID.day }, children(ID.customer), 'unknown_target'],
];

describe('canDrop', () => {
    it.each(MATRIX)('%s', (_name, subject, target, expected) => {
        expect(canDrop(contractDefinition(), subject, target, MAX)).toBe(expected);
    });

    it('checks the final index, counting the removed node in the same list', () => {
        const definition = contractDefinition();
        expect(canDrop(definition, { id: ID.customer }, page(ID.pageStart), MAX, 1)).toBeNull();
        expect(canDrop(definition, { id: ID.customer }, page(ID.pageStart), MAX, 2)).toBe(
            'bad_index',
        );
        expect(canDrop(definition, { id: ID.customer }, page(ID.pageItems), MAX, 1)).toBeNull();
        expect(canDrop(definition, { id: ID.customer }, page(ID.pageItems), MAX, 2)).toBe(
            'bad_index',
        );
        expect(canDrop(definition, { node: TEXT }, page(ID.pageItems), MAX, -1)).toBe('bad_index');
    });

    it('honours a different maximum depth', () => {
        expect(canDrop(contractDefinition(), { id: ID.items }, column(ID.cols, 1), 4)).toBeNull();
        expect(canDrop(contractDefinition(), { node: SECTION }, page(ID.pageStart), 1)).toBe(
            'too_deep',
        );
    });

    it('measures container height', () => {
        expect(containerHeight(TEXT)).toBe(0);
        expect(containerHeight(SECTION)).toBe(1);
        expect(containerHeight(NESTED)).toBe(2);
    });
});

describe('column limits', () => {
    it('keeps 2–4 columns', () => {
        const definition = contractDefinition();
        expect(canAddColumn(definition, ID.cols)).toBeNull();
        expect(canRemoveColumn(definition, ID.cols, 0)).toBe('min_columns');
        expect(canAddColumn(definition, ID.customer)).toBe('not_columns');
        expect(canRemoveColumn(definition, ID.details, 0)).toBe('not_columns');
    });
});

describe('checkOperation', () => {
    const cases: [string, Operation, Refusal | null][] = [
        ['insert ok', { op: 'insert', target: page(ID.pageStart), index: 0, node: TEXT }, null],
        [
            'insert page into page list',
            { op: 'insert', target: { kind: 'pages' }, index: 2, node: PAGE },
            null,
        ],
        [
            'insert too deep',
            { op: 'insert', target: column(ID.cols, 0), index: 0, node: SECTION },
            'too_deep',
        ],
        [
            'move into itself',
            { op: 'move', id: ID.details, target: column(ID.cols, 1), index: 0 },
            'into_itself',
        ],
        ['remove unknown', { op: 'remove', id: 'missing' }, 'unknown_node'],
        ['remove ok', { op: 'remove', id: ID.qty }, null],
        ['update settings', { op: 'update', kind: 'settings', id: null, props: {} }, null],
        [
            'update wrong kind',
            { op: 'update', kind: 'page', id: ID.customer, props: {} },
            'unknown_node',
        ],
        ['update element', { op: 'update', kind: 'element', id: ID.customer, props: {} }, null],
        ['add column', { op: 'addColumn', parentId: ID.cols, index: 2 }, null],
        ['add column bad index', { op: 'addColumn', parentId: ID.cols, index: 3 }, 'bad_index'],
        [
            'remove last two columns',
            { op: 'removeColumn', parentId: ID.cols, column: 0 },
            'min_columns',
        ],
    ];
    it.each(cases)('%s', (_name, op, expected) => {
        expect(checkOperation(contractDefinition(), op, MAX)).toBe(expected);
    });

    it('allows removing a column once there are three', () => {
        const three = insertNode(contractDefinition(), page(ID.pageStart), 0, {
            id: '0192f1c4-0000-7000-8000-0000000000c2',
            type: 'columns',
            key: 'c3',
            columns: [{ elements: [] }, { elements: [] }, { elements: [] }],
        });
        expect(
            checkOperation(
                three,
                { op: 'removeColumn', parentId: '0192f1c4-0000-7000-8000-0000000000c2', column: 2 },
                MAX,
            ),
        ).toBeNull();
        expect(
            checkOperation(
                three,
                { op: 'removeColumn', parentId: '0192f1c4-0000-7000-8000-0000000000c2', column: 3 },
                MAX,
            ),
        ).toBe('bad_index');
    });
});
