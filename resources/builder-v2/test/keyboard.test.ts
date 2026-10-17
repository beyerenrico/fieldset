import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest';
import { mountBuilder, type BuilderApp } from '../src/app.ts';
import { nodeEntry } from '../src/store/tree.ts';
import { contractConfig, ID } from './support/fixtures.ts';
import { englishStrings, fakeWire, key, settle, skeleton, type FakeWire } from './support/dom.ts';

let app: BuilderApp;
let root: HTMLElement;
let wire: FakeWire;
let confirm: Mock<(message: string) => boolean>;

function card(id: string): HTMLElement {
    return root.querySelector<HTMLElement>(
        `[data-builder-node="${id}"], [data-builder-page="${id}"]`,
    )!;
}
function handle(id: string): HTMLElement {
    return card(id).querySelector<HTMLElement>(':scope > .bv2-card__head > [data-builder-handle]')!;
}
function live(): string {
    return root.querySelector('[data-builder-live]')!.textContent;
}
function containerOf(id: string) {
    return nodeEntry(app.store.state.definition, id)?.container;
}

beforeEach(async () => {
    const config = { ...contractConfig(), lockedIds: [ID.description] };
    root = skeleton(config).root;
    wire = fakeWire();
    confirm = vi.fn<(message: string) => boolean>(() => true);
    app = mountBuilder(root, { wire, strings: englishStrings(), confirm });
    await settle();
});

afterEach(() => {
    app.destroy();
});

describe('keyboard: pick up, move, drop', () => {
    it('picks up with Space, moves with arrows and drops with Enter as one undo step', () => {
        handle(ID.customer).focus();
        key(handle(ID.customer), ' ');
        expect(live()).toContain('Kunde picked up');
        expect(card(ID.customer).hasAttribute('data-builder-grabbed')).toBe(true);
        key(document.activeElement!, 'ArrowDown');
        expect(live()).toBe('Kunde: position 2 of 2 in Start.');
        expect(document.activeElement).toBe(handle(ID.customer));
        key(document.activeElement!, 'ArrowRight');
        expect(containerOf(ID.customer)).toEqual({ kind: 'children', parentId: ID.details });
        expect(live()).toBe('Kunde: position 2 of 2 in Details.');
        key(document.activeElement!, 'PageDown');
        expect(containerOf(ID.customer)).toEqual({ kind: 'page', pageId: ID.pageItems });
        key(document.activeElement!, 'Enter');
        expect(live()).toBe('Kunde dropped.');
        expect(card(ID.customer).hasAttribute('data-builder-grabbed')).toBe(false);
        expect(app.store.state.history.past).toHaveLength(1);
        app.store.undo();
        expect(containerOf(ID.customer)).toEqual({ kind: 'page', pageId: ID.pageStart });
    });

    it('moves into the previous container with → and out with ←', () => {
        handle(ID.details).focus();
        key(handle(ID.details), 'Enter');
        key(document.activeElement!, 'ArrowUp');
        expect(nodeEntry(app.store.state.definition, ID.details)?.index).toBe(0);
        key(document.activeElement!, 'Escape');
        expect(nodeEntry(app.store.state.definition, ID.details)?.index).toBe(1);
        expect(live()).toBe('Move of Details cancelled.');
        expect(app.store.state.history.past).toHaveLength(0);
        handle(ID.items).focus();
        key(handle(ID.items), ' ');
        key(document.activeElement!, 'PageUp');
        key(document.activeElement!, 'ArrowRight');
        expect(containerOf(ID.items)).toEqual({ kind: 'children', parentId: ID.details });
        key(document.activeElement!, 'ArrowLeft');
        expect(containerOf(ID.items)).toEqual({ kind: 'page', pageId: ID.pageStart });
        key(document.activeElement!, ' ');
        expect(app.store.state.history.past).toHaveLength(1);
    });

    it('announces refusals at the edge and for nesting rules', () => {
        handle(ID.customer).focus();
        key(handle(ID.customer), ' ');
        key(document.activeElement!, 'ArrowUp');
        expect(live()).toBe('Not possible: it cannot move further in this direction.');
        key(document.activeElement!, 'Escape');
        handle(ID.items).focus();
        key(handle(ID.items), ' ');
        key(document.activeElement!, 'PageUp');
        key(document.activeElement!, 'ArrowRight');
        expect(containerOf(ID.items)).toEqual({ kind: 'children', parentId: ID.details });
        key(document.activeElement!, 'ArrowRight');
        expect(live()).toBe('Not possible: groups can be nested at most 3 levels deep.');
        expect(containerOf(ID.items)).toEqual({ kind: 'children', parentId: ID.details });
        key(document.activeElement!, 'Escape');
        expect(containerOf(ID.items)).toEqual({ kind: 'page', pageId: ID.pageItems });
    });

    it('asks before moving a published element out of a repeater and restores when declined', () => {
        confirm.mockReturnValueOnce(false);
        handle(ID.description).focus();
        key(handle(ID.description), ' ');
        key(document.activeElement!, 'ArrowLeft');
        key(document.activeElement!, 'Enter');
        expect(confirm).toHaveBeenCalledWith(expect.stringContaining('Beschreibung is published'));
        expect(containerOf(ID.description)).toEqual({ kind: 'children', parentId: ID.items });
        expect(live()).toBe('Not possible: the move was not confirmed.');
        handle(ID.description).focus();
        key(handle(ID.description), ' ');
        key(document.activeElement!, 'ArrowLeft');
        key(document.activeElement!, 'Enter');
        expect(containerOf(ID.description)).toEqual({ kind: 'page', pageId: ID.pageItems });
    });

    it('drops on pointer down elsewhere', () => {
        handle(ID.customer).focus();
        key(handle(ID.customer), ' ');
        key(document.activeElement!, 'ArrowDown');
        document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }));
        expect(app.store.state.history.past).toHaveLength(1);
    });
});

describe('keyboard: navigation and commands', () => {
    it('moves focus between visible cards and selects with Enter', async () => {
        card(ID.pageStart).focus();
        key(card(ID.pageStart), 'ArrowDown');
        expect(document.activeElement).toBe(card(ID.customer));
        expect(card(ID.customer).tabIndex).toBe(0);
        key(card(ID.customer), 'End');
        expect(document.activeElement).toBe(card(ID.qty));
        key(card(ID.qty), 'Home');
        expect(document.activeElement).toBe(card(ID.pageStart));
        key(card(ID.pageStart), 'ArrowDown');
        key(card(ID.customer), 'Enter');
        await settle();
        expect(card(ID.customer).getAttribute('aria-selected')).toBe('true');
        expect(wire.inspect).toHaveBeenCalledWith(
            'element',
            ID.customer,
            expect.any(String),
            expect.any(String),
        );
    });

    it('collapses with ← and expands with →, then enters the container', () => {
        card(ID.details).focus();
        key(card(ID.details), 'ArrowLeft');
        expect(card(ID.details).getAttribute('aria-expanded')).toBe('false');
        expect(document.activeElement).toBe(card(ID.details));
        key(card(ID.details), 'ArrowLeft');
        expect(document.activeElement).toBe(card(ID.pageStart));
        card(ID.details).focus();
        key(card(ID.details), 'ArrowRight');
        expect(card(ID.details).getAttribute('aria-expanded')).toBe('true');
        key(card(ID.details), 'ArrowRight');
        expect(document.activeElement).toBe(card(ID.cols));
    });

    it('deletes with Delete and duplicates with Ctrl+D through cloneNode', async () => {
        card(ID.qty).focus();
        key(card(ID.qty), 'Delete');
        expect(nodeEntry(app.store.state.definition, ID.qty)).toBeNull();
        expect(live()).toBe('Menge deleted. Press Ctrl+Z (Cmd+Z on Mac) to undo.');
        expect(document.activeElement).toBe(card(ID.description));
        const event = key(card(ID.description), 'd', { ctrlKey: true });
        expect(event.defaultPrevented).toBe(true);
        await settle();
        expect(wire.cloneNode).toHaveBeenCalledWith(
            expect.stringContaining('"description"'),
            JSON.stringify(['description']),
        );
        expect(
            nodeEntry(app.store.state.definition, '0192f1c4-0000-7000-8000-0000000c1011')?.index,
        ).toBe(1);
        expect(live()).toBe('Beschreibung duplicated.');
    });
});

describe('Move menu', () => {
    it('opens with focus on the first item, moves the card and returns focus', () => {
        const button = card(ID.customer).querySelector<HTMLElement>('[data-builder-menu]')!;
        button.click();
        const menu = card(ID.customer).querySelector<HTMLElement>('[role="menu"]')!;
        expect(button.getAttribute('aria-expanded')).toBe('true');
        expect(document.activeElement).toBe(menu.querySelector('[data-builder-action="move-up"]'));
        expect(
            menu.querySelector('[data-builder-action="move-up"]')!.getAttribute('aria-disabled'),
        ).toBe('true');
        key(document.activeElement!, 'ArrowDown');
        expect(document.activeElement).toBe(
            menu.querySelector('[data-builder-action="move-down"]'),
        );
        menu.querySelector<HTMLElement>('[data-builder-action="move-down"]')!.click();
        expect(nodeEntry(app.store.state.definition, ID.customer)?.index).toBe(1);
        expect(card(ID.customer).querySelector('[role="menu"]')).toBeNull();
        expect(document.activeElement).toBe(card(ID.customer).querySelector('[data-builder-menu]'));
    });

    it('closes with Escape and offers delete and the page moves', () => {
        const button = card(ID.qty).querySelector<HTMLElement>('[data-builder-menu]')!;
        button.click();
        const menu = card(ID.qty).querySelector<HTMLElement>('[role="menu"]')!;
        expect(
            menu
                .querySelector('[data-builder-action="move-page-prev"]')!
                .hasAttribute('aria-disabled'),
        ).toBe(false);
        expect(
            menu
                .querySelector('[data-builder-action="move-page-next"]')!
                .getAttribute('aria-disabled'),
        ).toBe('true');
        key(document.activeElement!, 'Escape');
        expect(card(ID.qty).querySelector('[role="menu"]')).toBeNull();
        expect(document.activeElement).toBe(button);
        button.click();
        card(ID.qty).querySelector<HTMLElement>('[data-builder-action="delete"]')!.click();
        expect(nodeEntry(app.store.state.definition, ID.qty)).toBeNull();
    });

    it('adds and removes columns from the columns card', () => {
        card(ID.cols).querySelector<HTMLElement>('[data-builder-action="add-column"]')!.click();
        expect(card(ID.cols).querySelectorAll('[data-builder-list]')).toHaveLength(3);
        expect(live()).toBe('Column added.');
        card(ID.cols)
            .querySelectorAll<HTMLElement>('[data-builder-action="remove-column"]')[0]!
            .click();
        expect(card(ID.cols).querySelectorAll('[data-builder-list]')).toHaveLength(2);
        expect(nodeEntry(app.store.state.definition, ID.day)?.container).toEqual({
            kind: 'column',
            parentId: ID.cols,
            column: 0,
        });
    });
});
