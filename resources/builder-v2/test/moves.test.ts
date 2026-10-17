import { describe, expect, it } from 'vitest';
import { computeMove, type MoveDirection } from '../src/canvas/moves.ts';
import type { Container } from '../src/contract.ts';
import { insertNode } from '../src/store/ops.ts';
import { contractDefinition, ID } from './support/fixtures.ts';

const page = (pageId: string): Container => ({ kind: 'page', pageId });
const children = (parentId: string): Container => ({ kind: 'children', parentId });
const column = (parentId: string, index: number): Container => ({
    kind: 'column',
    parentId,
    column: index,
});

const EXTRA = '0192f1c4-0000-7000-8000-0000000000e1';
/** Contract definition plus a text in column 2 of `cols`. */
function definition() {
    return insertNode(contractDefinition(), column(ID.cols, 1), 0, {
        id: EXTRA,
        type: 'text',
        key: 'extra',
        label: 'Extra',
    });
}

const CASES: [string, string, MoveDirection, { target: Container; index: number } | null][] = [
    ['down within a page', ID.customer, 'down', { target: page(ID.pageStart), index: 1 }],
    ['up at the top of the first page', ID.customer, 'up', null],
    [
        'down at the end of a page goes to the next page',
        ID.details,
        'down',
        { target: page(ID.pageItems), index: 0 },
    ],
    [
        'up at the top of a page goes to the end of the previous one',
        ID.items,
        'up',
        { target: page(ID.pageStart), index: 2 },
    ],
    [
        'down from column 1 goes to the top of column 2',
        ID.day,
        'down',
        { target: column(ID.cols, 1), index: 0 },
    ],
    [
        'up from column 2 goes to the end of column 1',
        EXTRA,
        'up',
        { target: column(ID.cols, 0), index: 1 },
    ],
    [
        'down from the last column leaves the columns',
        EXTRA,
        'down',
        { target: children(ID.details), index: 1 },
    ],
    [
        'up from the first column leaves before the columns',
        ID.day,
        'up',
        { target: children(ID.details), index: 0 },
    ],
    ['up in a repeater', ID.qty, 'up', { target: children(ID.items), index: 0 }],
    [
        'down at the end of a repeater leaves it',
        ID.qty,
        'down',
        { target: page(ID.pageItems), index: 1 },
    ],
    ['into the previous section', ID.customer, 'into', null],
    ['out of a repeater', ID.description, 'out', { target: page(ID.pageItems), index: 1 }],
    ['out of a page is impossible', ID.customer, 'out', null],
    ['out of a column', ID.day, 'out', { target: children(ID.details), index: 1 }],
    ['to the previous page', ID.qty, 'pagePrev', { target: page(ID.pageStart), index: 2 }],
    ['to the next page', ID.day, 'pageNext', { target: page(ID.pageItems), index: 0 }],
    ['no next page', ID.qty, 'pageNext', null],
    ['page down', ID.pageStart, 'down', { target: { kind: 'pages' }, index: 1 }],
    ['page up at the top', ID.pageStart, 'up', null],
    ['page via PageUp', ID.pageItems, 'pagePrev', { target: { kind: 'pages' }, index: 0 }],
    ['page into is impossible', ID.pageItems, 'into', null],
    ['unknown node', 'missing', 'down', null],
];

describe('computeMove', () => {
    it.each(CASES)('%s', (_name, id, direction, expected) => {
        expect(computeMove(definition(), id, direction)).toEqual(expected);
    });

    it('moves into the previous container (section, repeater, columns)', () => {
        const base = insertNode(contractDefinition(), page(ID.pageStart), 2, {
            id: EXTRA,
            type: 'text',
            key: 'extra',
            label: 'Extra',
        });
        expect(computeMove(base, EXTRA, 'into')).toEqual({
            target: children(ID.details),
            index: 1,
        });
        expect(computeMove(base, ID.details, 'into')).toBeNull();
        const afterCols = insertNode(contractDefinition(), children(ID.details), 1, {
            id: EXTRA,
            type: 'text',
            key: 'extra',
            label: 'Extra',
        });
        expect(computeMove(afterCols, EXTRA, 'into')).toEqual({
            target: column(ID.cols, 0),
            index: 1,
        });
    });
});
