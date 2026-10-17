import { beforeEach, describe, expect, it } from 'vitest';
import { TypeIcons } from '../src/canvas/icons.ts';
import { CanvasRenderer, type ViewState } from '../src/canvas/render.ts';
import { Translator, toStrings } from '../src/i18n.ts';
import { applyOperation } from '../src/store/ops.ts';
import { contractDefinition, ID } from './support/fixtures.ts';
import { englishStrings } from './support/dom.ts';

const VIEW: ViewState = {
    selection: null,
    lockedIds: new Set([ID.customer]),
    markers: new Map(),
    collapsed: new Set(),
    grabbedId: null,
};

let tree: HTMLElement;
let renderer: CanvasRenderer;

function card(id: string): HTMLElement {
    return tree.querySelector<HTMLElement>(
        `[data-builder-node="${id}"], [data-builder-page="${id}"]`,
    )!;
}

beforeEach(() => {
    document.body.innerHTML =
        '<div role="tree" data-builder-tree data-builder-list data-builder-container="pages"></div>';
    tree = document.querySelector<HTMLElement>('[data-builder-tree]')!;
    renderer = new CanvasRenderer(tree, {
        tr: new Translator(toStrings(englishStrings())),
        icons: new TypeIcons(null, new Map()),
    });
});

describe('canvas renderer', () => {
    it('renders a tree with levels, positions, labels and handles', () => {
        renderer.render(contractDefinition(), VIEW);
        const customer = card(ID.customer);
        expect(customer.getAttribute('role')).toBe('treeitem');
        expect(customer.getAttribute('aria-level')).toBe('2');
        expect(customer.getAttribute('aria-posinset')).toBe('1');
        expect(customer.getAttribute('aria-setsize')).toBe('2');
        expect(card(ID.day).getAttribute('aria-level')).toBe('4');
        expect(card(ID.pageStart).getAttribute('aria-level')).toBe('1');
        expect(
            customer.querySelector('[data-builder-handle="element"]')!.getAttribute('aria-label'),
        ).toBe('Move Kunde');
        expect(card(ID.pageStart).querySelector('[data-builder-handle="page"]')).not.toBeNull();
        expect(
            document.getElementById(customer.getAttribute('aria-labelledby')!)!.textContent,
        ).toContain('Kunde');
        expect(card(ID.details).getAttribute('aria-expanded')).toBe('true');
        expect(customer.hasAttribute('aria-expanded')).toBe(false);
        expect(customer.querySelector<HTMLElement>('.bv2-card__lock')!.hidden).toBe(false);
        expect(
            card(ID.cols).querySelectorAll('[data-builder-action="remove-column"]'),
        ).toHaveLength(2);
        expect(
            card(ID.cols).querySelector<HTMLButtonElement>('[data-builder-action="remove-column"]')!
                .disabled,
        ).toBe(true);
    });

    it('marks the selection and keeps exactly one tab stop', () => {
        renderer.render(contractDefinition(), {
            ...VIEW,
            selection: { kind: 'element', id: ID.qty },
        });
        expect(card(ID.qty).getAttribute('aria-selected')).toBe('true');
        expect(card(ID.customer).getAttribute('aria-selected')).toBe('false');
        expect(tree.querySelectorAll('[role="treeitem"][tabindex="0"]')).toHaveLength(1);
        expect(card(ID.qty).tabIndex).toBe(0);
        renderer.setActive(ID.customer);
        expect(card(ID.qty).tabIndex).toBe(-1);
        expect(
            card(ID.customer).querySelector<HTMLElement>('[data-builder-handle]')!.tabIndex,
        ).toBe(0);
    });

    it('keeps card elements of unchanged nodes and moves them on re-render', () => {
        const before = contractDefinition();
        renderer.render(before, VIEW);
        const customer = card(ID.customer);
        const items = card(ID.items);
        const after = applyOperation(before, {
            op: 'move',
            id: ID.customer,
            target: { kind: 'children', parentId: ID.items },
            index: 1,
        });
        renderer.render(after, VIEW);
        expect(card(ID.customer)).toBe(customer);
        expect(card(ID.items)).toBe(items);
        expect(customer.parentElement!.dataset.builderContainer).toBe(`children:${ID.items}`);
        expect(customer.getAttribute('aria-level')).toBe('3');
        expect(
            [...customer.parentElement!.children].map(
                (el) => (el as HTMLElement).dataset.builderKey,
            ),
        ).toEqual(['description', 'customer', 'qty']);
    });

    it('moves a card into a later sibling container (nested render runs before placement)', () => {
        const before = contractDefinition();
        renderer.render(before, VIEW);
        const after = applyOperation(before, {
            op: 'move',
            id: ID.customer,
            target: { kind: 'children', parentId: ID.details },
            index: 1,
        });
        renderer.render(after, VIEW);
        expect(card(ID.customer).parentElement!.dataset.builderContainer).toBe(
            `children:${ID.details}`,
        );
        expect(
            card(ID.pageStart).querySelectorAll(
                ':scope > .bv2-card__body > [data-builder-list] > [role="treeitem"]',
            ),
        ).toHaveLength(1);
        renderer.render(before, VIEW);
        expect(
            [
                ...tree.querySelectorAll<HTMLElement>(
                    `[data-builder-container="page:${ID.pageStart}"] > *`,
                ),
            ].map((el) => el.dataset.builderKey),
        ).toEqual(['customer', 'details']);
    });

    it('removes cards of deleted nodes and patches labels', () => {
        const before = contractDefinition();
        renderer.render(before, VIEW);
        const removed = applyOperation(before, { op: 'remove', id: ID.details });
        renderer.render(removed, VIEW);
        expect(card(ID.details)).toBeNull();
        expect(card(ID.day)).toBeNull();
        const renamed = applyOperation(removed, {
            op: 'update',
            kind: 'element',
            id: ID.customer,
            props: { id: ID.customer, type: 'text', key: 'client', label: 'Client' },
        });
        renderer.render(renamed, VIEW);
        expect(card(ID.customer).dataset.builderKey).toBe('client');
        expect(card(ID.customer).querySelector('.bv2-card__label')!.textContent).toBe('Client');
        expect(renderer.card(ID.details)).toBeNull();
    });

    it('collapses containers and skips their cards in the visible order', () => {
        renderer.render(contractDefinition(), { ...VIEW, collapsed: new Set([ID.details]) });
        expect(card(ID.details).getAttribute('aria-expanded')).toBe('false');
        expect(card(ID.details).querySelector<HTMLElement>('.bv2-card__body')!.hidden).toBe(true);
        const visible = renderer
            .visibleCards()
            .map((el) => el.dataset.builderNode ?? el.dataset.builderPage);
        expect(visible).toEqual([
            ID.pageStart,
            ID.customer,
            ID.details,
            ID.pageItems,
            ID.items,
            ID.description,
            ID.qty,
        ]);
    });

    it('shows lint markers, the grabbed card and rebuilds columns when their count changes', () => {
        const before = contractDefinition();
        renderer.render(before, {
            ...VIEW,
            markers: new Map([[ID.day, 'error']]),
            grabbedId: ID.qty,
        });
        expect(card(ID.day).dataset.builderMarker).toBe('error');
        // Severity is visible text plus its own icon (not colour only); the title keeps the long form.
        expect(card(ID.day).querySelector('.bv2-card__flag')!.textContent).toBe('Error');
        expect(card(ID.day).querySelector<HTMLElement>('.bv2-card__flag')!.title).toBe(
            'Has errors',
        );
        expect(card(ID.qty).hasAttribute('data-builder-grabbed')).toBe(true);
        renderer.render(
            applyOperation(before, { op: 'addColumn', parentId: ID.cols, index: 2 }),
            VIEW,
        );
        expect(card(ID.cols).querySelectorAll('[data-builder-list]')).toHaveLength(3);
        expect(
            card(ID.cols).querySelector<HTMLButtonElement>('[data-builder-action="remove-column"]')!
                .disabled,
        ).toBe(false);
        expect(card(ID.day).hasAttribute('data-builder-marker')).toBe(false);
        expect(renderer.lists()[0]).toBe(tree);
    });
});
