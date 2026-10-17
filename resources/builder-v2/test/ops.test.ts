import { canonicalJson, type JsonObject } from '@form-schema-app/form-schema-js';
import { describe, expect, it } from 'vitest';
import { getIn, isObject, listProp } from '../src/store/json.ts';
import {
    addColumn,
    applyOperation,
    insertNode,
    moveNode,
    OperationError,
    removeColumn,
    removeNode,
    updateNode,
} from '../src/store/ops.ts';
import { indexTree, nodeEntry } from '../src/store/tree.ts';
import { contractDefinition, deepFreeze, ID } from './support/fixtures.ts';

function keysOf(definition: JsonObject, path: readonly (string | number)[]): string[] {
    const list = getIn(definition, path);
    return (Array.isArray(list) ? list : []).map((node) =>
        isObject(node) && typeof node.key === 'string' ? node.key : '?',
    );
}

const NEW_NODE = deepFreeze({
    id: '0192f1c4-0000-7000-8000-0000000000aa',
    type: 'text',
    key: 'fresh',
    label: 'Fresh',
});
const START = ['pages', 0, 'elements'];

describe('operations', () => {
    it('insert puts the node at the index and shares untouched branches', () => {
        const before = contractDefinition();
        const after = insertNode(before, { kind: 'page', pageId: ID.pageStart }, 1, NEW_NODE);
        expect(keysOf(after, START)).toEqual(['customer', 'fresh', 'details']);
        expect(after).not.toBe(before);
        expect(getIn(after, ['pages', 1])).toBe(getIn(before, ['pages', 1]));
        expect(getIn(after, [...START, 0])).toBe(getIn(before, [...START, 0]));
        expect(after.settings).toBe(before.settings);
    });

    it('insert accepts the end index and rejects out-of-range indices', () => {
        const before = contractDefinition();
        const target = { kind: 'page', pageId: ID.pageStart } as const;
        expect(keysOf(insertNode(before, target, 2, NEW_NODE), START)).toEqual([
            'customer',
            'details',
            'fresh',
        ]);
        expect(() => insertNode(before, target, 3, NEW_NODE)).toThrow(OperationError);
        expect(() => insertNode(before, target, -1, NEW_NODE)).toThrow(OperationError);
        expect(() => insertNode(before, { kind: 'page', pageId: 'nope' }, 0, NEW_NODE)).toThrow(
            OperationError,
        );
    });

    it('insert into a column and into a repeater', () => {
        const before = contractDefinition();
        const column = insertNode(
            before,
            { kind: 'column', parentId: ID.cols, column: 1 },
            0,
            NEW_NODE,
        );
        expect(nodeEntry(column, NEW_NODE.id)?.container).toEqual({
            kind: 'column',
            parentId: ID.cols,
            column: 1,
        });
        const rows = insertNode(before, { kind: 'children', parentId: ID.items }, 2, NEW_NODE);
        expect(nodeEntry(rows, NEW_NODE.id)?.index).toBe(2);
    });

    it('remove deletes the node with its subtree', () => {
        const after = removeNode(contractDefinition(), ID.details);
        expect(keysOf(after, START)).toEqual(['customer']);
        expect(nodeEntry(after, ID.day)).toBeNull();
        expect(() => removeNode(after, ID.details)).toThrow(OperationError);
    });

    it('move uses the final index after removal (same list)', () => {
        const before = contractDefinition();
        const target = { kind: 'children', parentId: ID.items } as const;
        expect(
            keysOf(moveNode(before, ID.description, target, 1), [
                'pages',
                1,
                'elements',
                0,
                'elements',
            ]),
        ).toEqual(['qty', 'description']);
        expect(
            keysOf(moveNode(before, ID.qty, target, 0), ['pages', 1, 'elements', 0, 'elements']),
        ).toEqual(['qty', 'description']);
    });

    it('move across lists and pages keeps the node object', () => {
        const before = contractDefinition();
        const customer = nodeEntry(before, ID.customer)?.node;
        const after = moveNode(before, ID.customer, { kind: 'children', parentId: ID.items }, 0);
        expect(nodeEntry(after, ID.customer)?.node).toBe(customer);
        expect(keysOf(after, START)).toEqual(['details']);
        expect(nodeEntry(after, ID.customer)?.pageId).toBe(ID.pageItems);
    });

    it('moves pages within the page list', () => {
        const after = moveNode(contractDefinition(), ID.pageItems, { kind: 'pages' }, 0);
        expect(keysOf(after, ['pages'])).toEqual(['items_page', 'start']);
    });

    it('update replaces own properties and keeps children and the id', () => {
        const before = contractDefinition();
        const after = updateNode(before, 'element', ID.details, {
            id: 'other',
            type: 'section',
            key: 'details',
            label: 'Neu',
            elements: [],
        });
        const node = nodeEntry(after, ID.details)?.node;
        expect(node?.label).toBe('Neu');
        expect(node?.id).toBe(ID.details);
        expect(node?.elements).toBe(nodeEntry(before, ID.details)?.node.elements);
    });

    it('update drops properties the inspector removed and keeps x-* it sends', () => {
        const after = updateNode(contractDefinition(), 'element', ID.customer, {
            id: ID.customer,
            type: 'text',
            key: 'customer',
            label: 'Kunde',
            'x-crm': { field: 'name' },
        });
        expect(nodeEntry(after, ID.customer)?.node).toEqual({
            id: ID.customer,
            type: 'text',
            key: 'customer',
            label: 'Kunde',
            'x-crm': { field: 'name' },
        });
    });

    it('update of a page and of the settings keeps elements and pages', () => {
        const before = contractDefinition();
        const page = updateNode(before, 'page', ID.pageStart, { key: 'start', title: 'Anfang' });
        expect(nodeEntry(page, ID.pageStart)?.node.title).toBe('Anfang');
        expect(nodeEntry(page, ID.pageStart)?.node.elements).toBe(
            nodeEntry(before, ID.pageStart)?.node.elements,
        );
        const settings = updateNode(before, 'settings', null, {
            key: 'contract_form',
            title: 'Neu',
            locale: 'de-DE',
            pages: [],
        });
        expect(settings.title).toBe('Neu');
        expect(settings.pages).toBe(before.pages);
        expect(settings.settings).toBeUndefined();
        expect(() => updateNode(before, 'page', ID.customer, {})).toThrow(OperationError);
        expect(() => updateNode(before, 'element', null, {})).toThrow(OperationError);
    });

    it('addColumn and removeColumn keep 2–4 columns and move elements to the neighbour', () => {
        const before = contractDefinition();
        const three = addColumn(before, ID.cols, 2);
        expect(listProp(nodeEntry(three, ID.cols)!.node, 'columns')).toHaveLength(3);
        const four = addColumn(three, ID.cols, 0);
        expect(() => addColumn(four, ID.cols, 0)).toThrow(OperationError);
        expect(() => removeColumn(before, ID.cols, 0)).toThrow(OperationError);
        const back = removeColumn(addColumn(before, ID.cols, 0), ID.cols, 0);
        expect(canonicalJson(back)).toBe(canonicalJson(before));
        const moved = removeColumn(addColumn(before, ID.cols, 2), ID.cols, 0);
        expect(nodeEntry(moved, ID.day)?.container).toEqual({
            kind: 'column',
            parentId: ID.cols,
            column: 0,
        });
        const left = removeColumn(
            insertNode(
                addColumn(before, ID.cols, 2),
                { kind: 'column', parentId: ID.cols, column: 2 },
                0,
                NEW_NODE,
            ),
            ID.cols,
            2,
        );
        expect(nodeEntry(left, NEW_NODE.id)?.container).toEqual({
            kind: 'column',
            parentId: ID.cols,
            column: 1,
        });
        expect(() => addColumn(before, ID.customer, 0)).toThrow(OperationError);
    });

    it('removing the first column puts its elements before the right neighbour', () => {
        const before = contractDefinition();
        const withThree = insertNode(
            addColumn(before, ID.cols, 2),
            { kind: 'column', parentId: ID.cols, column: 1 },
            0,
            NEW_NODE,
        );
        const after = removeColumn(withThree, ID.cols, 0);
        expect(nodeEntry(after, ID.day)?.index).toBe(0);
        expect(nodeEntry(after, NEW_NODE.id)?.index).toBe(1);
    });

    it('applyOperation dispatches every operation kind on frozen input', () => {
        const before = contractDefinition();
        const ops = [
            {
                op: 'insert',
                target: { kind: 'page', pageId: ID.pageItems },
                index: 0,
                node: NEW_NODE,
            },
            {
                op: 'move',
                id: ID.customer,
                target: { kind: 'page', pageId: ID.pageItems },
                index: 1,
            },
            { op: 'remove', id: ID.qty },
            {
                op: 'update',
                kind: 'element',
                id: ID.day,
                props: { id: ID.day, type: 'date', key: 'day', label: 'Datum' },
            },
            { op: 'addColumn', parentId: ID.cols, index: 1 },
            { op: 'removeColumn', parentId: ID.cols, column: 1 },
        ] as const;
        const final = ops.reduce<JsonObject>(
            (definition, op) => applyOperation(definition, op),
            before,
        );
        expect(indexTree(final).nodes.size).toBe(indexTree(before).nodes.size);
        expect(canonicalJson(before)).toBe(canonicalJson(contractDefinition()));
    });
});
