import { afterEach, describe, expect, it, vi } from 'vitest';
import type Sortable from 'sortablejs';
import { dropRequest, revertDom, SortableBinder, subjectOf } from '../src/canvas/sortable.ts';

function list(container: string, ids: string[], attr = 'data-builder-node'): HTMLElement {
    const el = document.createElement('div');
    el.dataset.builderContainer = container;
    el.setAttribute('data-builder-list', '');
    ids.forEach((id) => {
        const item = document.createElement('div');
        item.setAttribute(attr, id);
        el.append(item);
    });
    document.body.append(el);
    return el;
}

const ids = (el: HTMLElement) =>
    [...el.children].map(
        (child) =>
            child.getAttribute('data-builder-node') ??
            child.getAttribute('data-builder-palette-item'),
    );

afterEach(() => {
    document.body.innerHTML = '';
});

describe('SortableJS onEnd handling', () => {
    it('reads a move within a list and reverts the DOM', () => {
        const from = list('page:p1', ['a', 'b', 'c']);
        const item = from.children[0] as HTMLElement;
        from.append(item); // SortableJS moved a to the end
        const event = {
            item,
            from,
            to: from,
            oldIndex: 0,
            newIndex: 2,
            oldDraggableIndex: 0,
            newDraggableIndex: 2,
            clone: item,
            pullMode: undefined,
        } as unknown as Sortable.SortableEvent;
        expect(dropRequest(event)).toEqual({
            kind: 'move',
            id: 'a',
            target: { kind: 'page', pageId: 'p1' },
            index: 2,
        });
        revertDom(event);
        expect(ids(from)).toEqual(['a', 'b', 'c']);
    });

    it('reads a move across lists and reverts it', () => {
        const from = list('page:p1', ['a', 'b']);
        const to = list('children:s1', ['x']);
        const item = from.children[1] as HTMLElement;
        to.prepend(item);
        const event = {
            item,
            from,
            to,
            oldIndex: 1,
            newIndex: 0,
            oldDraggableIndex: 1,
            newDraggableIndex: 0,
            clone: item,
            pullMode: true,
        } as unknown as Sortable.SortableEvent;
        expect(dropRequest(event)).toEqual({
            kind: 'move',
            id: 'b',
            target: { kind: 'children', parentId: 's1' },
            index: 0,
        });
        revertDom(event);
        expect(ids(from)).toEqual(['a', 'b']);
        expect(ids(to)).toEqual(['x']);
    });

    it('turns a palette clone drop into an insert and puts the palette back', () => {
        const palette = list('', ['text', 'number'], 'data-builder-palette-item');
        palette.removeAttribute('data-builder-container');
        palette.setAttribute('data-builder-palette', '');
        const to = list('column:c1:1', []);
        const item = palette.children[0] as HTMLElement;
        const clone = item.cloneNode(true) as HTMLElement;
        palette.insertBefore(clone, item);
        to.append(item);
        const event = {
            item,
            from: palette,
            to,
            oldIndex: 0,
            newIndex: 0,
            oldDraggableIndex: 0,
            newDraggableIndex: 0,
            clone,
            pullMode: 'clone',
        } as unknown as Sortable.SortableEvent;
        expect(dropRequest(event)).toEqual({
            kind: 'insert',
            type: 'text',
            target: { kind: 'column', parentId: 'c1', column: 1 },
            index: 0,
        });
        revertDom(event);
        expect(ids(palette)).toEqual(['text', 'number']);
        expect(palette.children[0]).toBe(item);
        expect(to.children).toHaveLength(0);
    });

    it('reads the insert position of a palette drop from the target list, not from SortableJS', () => {
        const palette = list('', ['text'], 'data-builder-palette-item');
        palette.removeAttribute('data-builder-container');
        palette.setAttribute('data-builder-palette', '');
        const to = list('page:p1', ['a', 'b']);
        const item = palette.children[0] as HTMLElement;
        const clone = item.cloneNode(true) as HTMLElement;
        palette.insertBefore(clone, item);
        to.append(item); // dropped below the last card
        // SortableJS counts with the palette's draggable selector, which matches no card: always 0.
        const event = {
            item,
            from: palette,
            to,
            oldDraggableIndex: 0,
            newDraggableIndex: 0,
        } as unknown as Sortable.SortableEvent;
        expect(dropRequest(event)).toMatchObject({ kind: 'insert', type: 'text', index: 2 });
        to.insertBefore(item, to.children[1] ?? null);
        expect(dropRequest(event)).toMatchObject({ kind: 'insert', index: 1 });
    });

    it('ignores drops that change nothing or land outside the canvas', () => {
        const from = list('page:p1', ['a']);
        const item = from.children[0] as HTMLElement;
        expect(
            dropRequest({
                item,
                from,
                to: from,
                oldDraggableIndex: 0,
                newDraggableIndex: 0,
            }),
        ).toBeNull();
        const nowhere = document.createElement('div');
        expect(
            dropRequest({
                item,
                from,
                to: nowhere,
                oldDraggableIndex: 0,
                newDraggableIndex: 0,
            }),
        ).toBeNull();
    });

    it('resolves the dragged subject (node, page or palette type)', () => {
        const page = document.createElement('div');
        page.dataset.builderPage = 'p1';
        const tool = document.createElement('button');
        tool.dataset.builderPaletteItem = 'text';
        const lookup = vi.fn((type: string) => ({ node: { type } }));
        expect(subjectOf(page, lookup)).toEqual({ id: 'p1' });
        expect(subjectOf(tool, lookup)).toEqual({ node: { type: 'text' } });
        expect(subjectOf(document.createElement('div'), lookup)).toBeNull();
    });

    it('recreates one instance per list and destroys them', () => {
        const binder = new SortableBinder({
            accepts: () => true,
            paletteNode: () => null,
            onDrop: vi.fn(),
            onDragChange: vi.fn(),
        });
        const lists = [list('pages', []), list('page:p1', ['a']), list('children:s', [])];
        binder.bind(lists);
        binder.bind(lists);
        expect(binder.instanceCount).toBe(3);
        binder.bindPalette([list('', [], 'data-builder-palette-item')]);
        expect(binder.instanceCount).toBe(4);
        binder.destroy();
        expect(binder.instanceCount).toBe(0);
    });
});
