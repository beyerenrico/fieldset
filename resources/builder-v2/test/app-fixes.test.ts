import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest';
import { mountBuilder, type BuilderApp } from '../src/app.ts';
import { NODE_CHANGED_EVENT } from '../src/inspector.ts';
import { nodeEntry } from '../src/store/tree.ts';
import { contractConfig, ID } from './support/fixtures.ts';
import {
    englishStrings,
    fakeWire,
    key,
    settle,
    skeleton,
    syncResult,
    type FakeWire,
} from './support/dom.ts';

/* Gate fixes at app level: inspector interplay, toolbar, lint, conflicts, palette, menu, keyboard. */

let app: BuilderApp;
let root: HTMLElement;
let inspector: HTMLElement;
let wire: FakeWire;
let confirm: Mock<(message: string) => boolean>;

function card(id: string): HTMLElement {
    return root.querySelector<HTMLElement>(
        `[data-builder-node="${id}"], [data-builder-page="${id}"]`,
    )!;
}
function live(): string {
    return root.querySelector('[data-builder-live]')!.textContent;
}
function toolbar(command: string): HTMLButtonElement {
    return root.querySelector<HTMLButtonElement>(`[data-builder-toolbar="${command}"]`)!;
}
function nodeChanged(id: string, props: Record<string, unknown>): void {
    window.dispatchEvent(
        new CustomEvent(NODE_CHANGED_EVENT, {
            detail: { kind: 'element', id, node: JSON.stringify({ id, ...props }) },
        }),
    );
}
function labelOf(id: string): unknown {
    return nodeEntry(app.store.state.definition, id)?.node.label;
}

beforeEach(async () => {
    vi.useFakeTimers();
    ({ root, inspector } = skeleton({ ...contractConfig(), lockedIds: [ID.description] }));
    wire = fakeWire();
    confirm = vi.fn<(message: string) => boolean>(() => true);
    app = mountBuilder(root, { wire, strings: englishStrings(), confirm });
    await settle();
});

afterEach(() => {
    app.destroy();
    vi.useRealTimers();
});

describe('inspector interplay', () => {
    it('inspects the node a palette insert created', async () => {
        card(ID.customer).click();
        await settle();
        root.querySelector<HTMLElement>('[data-builder-palette-item="text"]')!.click();
        await settle();
        const id =
            app.store.state.selection?.kind === 'element' ? app.store.state.selection.id : '';
        expect(nodeEntry(app.store.state.definition, id)?.node.type).toBe('text');
        expect(wire.inspect).toHaveBeenLastCalledWith(
            'element',
            id,
            expect.stringContaining('"type":"text"'),
            expect.any(String),
        );
    });

    it('commits a pending inspector edit before undo, so undo takes back that edit', async () => {
        card(ID.customer).click();
        await settle();
        wire.commitInspector.mockImplementationOnce(() => {
            nodeChanged(ID.customer, { type: 'text', key: 'customer', label: 'Kunde (Firma)' });
            return Promise.resolve();
        });
        inspector
            .querySelector('[data-test-input]')!
            .dispatchEvent(new Event('input', { bubbles: true }));
        toolbar('undo').click();
        await settle();
        expect(wire.commitInspector).toHaveBeenCalledTimes(1);
        expect(labelOf(ID.customer)).toBe('Kunde');
        expect(live()).toBe('Undone: Edit Kunde (Firma).');
        toolbar('redo').click();
        expect(labelOf(ID.customer)).toBe('Kunde (Firma)');
    });

    it('does not publish, export or switch builders when the inspector refuses the last edit', async () => {
        const navigate = vi.fn();
        app.destroy();
        ({ root, inspector } = skeleton());
        wire = fakeWire();
        app = mountBuilder(root, { wire, strings: englishStrings(), confirm, navigate });
        await settle();
        card(ID.customer).click();
        await settle();
        for (const command of ['publish', 'export', 'classic']) {
            inspector
                .querySelector('[data-test-input]')!
                .dispatchEvent(new Event('change', { bubbles: true }));
            toolbar(command).click();
            await settle();
            // A repeated message gets a trailing no-break space so screen readers read it again.
            expect(live().trim()).toBe(
                'Your last change under Properties is not valid yet. Fix the marked fields first.',
            );
        }
        expect(wire.mountAction).not.toHaveBeenCalled();
        expect(navigate).not.toHaveBeenCalled();
    });
});

describe('toolbar availability', () => {
    it('keeps unavailable buttons focusable and explains a blocked publish', async () => {
        app.store.dispatch(
            { op: 'update', kind: 'settings', id: null, props: { title: 'Neu' } },
            { label: 'Edit' },
        );
        wire.sync.mockResolvedValueOnce(
            syncResult({
                lint: {
                    schema_errors: [],
                    findings: [{ severity: 'error', code: 'duplicate_key', path: '/pages/0' }],
                },
            }),
        );
        await vi.advanceTimersByTimeAsync(800);
        const publish = toolbar('publish');
        expect(publish.disabled).toBe(false);
        expect(publish.getAttribute('aria-disabled')).toBe('true');
        expect(publish.getAttribute('aria-describedby')).toBe('bv2-publish-reason');
        expect(document.getElementById('bv2-publish-reason')!.textContent).toBe(
            'Fix the errors under Problems before publishing.',
        );
        publish.focus();
        publish.click();
        await settle();
        expect(document.activeElement).toBe(publish);
        expect(live()).toBe('Fix the errors under Problems before publishing.');
        expect(wire.mountAction).not.toHaveBeenCalled();
    });

    it('announces "Nothing to undo" from an aria-disabled undo that keeps focus', () => {
        const undo = toolbar('undo');
        expect(undo.getAttribute('aria-disabled')).toBe('true');
        undo.focus();
        undo.click();
        expect(document.activeElement).toBe(undo);
        expect(live()).toBe('Nothing to undo.');
    });
});

describe('lint announcements', () => {
    it('announces a changed problem count once per answer, not the first answer', async () => {
        const finding = { severity: 'warning' as const, code: 'label_missing', path: '/pages/0' };
        expect(live()).toBe('');
        app.store.dispatch(
            { op: 'update', kind: 'settings', id: null, props: { title: 'A' } },
            { label: 'Edit' },
        );
        wire.sync.mockResolvedValueOnce(
            syncResult({ lint: { schema_errors: [], findings: [finding] } }),
        );
        await vi.advanceTimersByTimeAsync(800);
        expect(live()).toBe('1 problem found.');
        expect(root.querySelector('[data-builder-lint-count]')!.textContent).toBe('1 problem');
        expect(root.querySelector('[data-builder-lint-item]')!.textContent).toContain('Warning:');
        app.store.dispatch(
            { op: 'update', kind: 'settings', id: null, props: { title: 'B' } },
            { label: 'Edit' },
        );
        wire.sync.mockResolvedValueOnce(syncResult({ lint: { schema_errors: [], findings: [] } }));
        await vi.advanceTimersByTimeAsync(800);
        expect(live()).toBe('No problems found.');
    });
});

describe('conflict banner', () => {
    it('confirms the destructive choices and names what each one loses', async () => {
        const reload = vi.fn();
        app.destroy();
        root = skeleton().root;
        wire = fakeWire();
        app = mountBuilder(root, { wire, strings: englishStrings(), confirm, reload });
        await settle();
        app.store.dispatch(
            { op: 'update', kind: 'settings', id: null, props: { title: 'Mine' } },
            { label: 'Edit' },
        );
        wire.sync.mockResolvedValueOnce(
            syncResult({ status: 'conflict', checksum: 'c'.repeat(64) }),
        );
        await vi.advanceTimersByTimeAsync(800);
        const button = (name: string) =>
            root.querySelector<HTMLElement>(`[data-builder-conflict="${name}"]`)!;
        expect(button('reload').textContent).toBe('Discard my changes and load the saved version');
        expect(button('overwrite').textContent).toBe(
            'Keep my version (overwrites the other changes)',
        );
        confirm.mockReturnValue(false);
        button('overwrite').click();
        button('reload').click();
        await settle();
        expect(confirm).toHaveBeenCalledWith(expect.stringContaining('The other changes are lost'));
        expect(confirm).toHaveBeenCalledWith(expect.stringContaining('cannot be restored'));
        expect(wire.sync).toHaveBeenCalledTimes(2);
        expect(reload).not.toHaveBeenCalled();
        confirm.mockReturnValue(true);
        button('reload').click();
        expect(reload).toHaveBeenCalledTimes(1);
    });
});

describe('palette inserts into a selected group', () => {
    it('inserts at the end of a selected section, repeater or first column', () => {
        const insert = (): void => {
            root.querySelector<HTMLElement>('[data-builder-palette-item="text"]')!.click();
        };
        app.store.select({ kind: 'element', id: ID.items });
        insert();
        const rows = nodeEntry(app.store.state.definition, ID.items)!.node.elements as {
            type: string;
        }[];
        expect(rows.map((row) => row.type)).toEqual(['text', 'number', 'text']);
        app.store.select({ kind: 'element', id: ID.cols });
        insert();
        const column = (
            nodeEntry(app.store.state.definition, ID.cols)!.node.columns as {
                elements: { type: string }[];
            }[]
        )[0]!.elements;
        expect(column.map((element) => element.type)).toEqual(['date', 'text']);
    });

    it('puts a group after a selected group when nesting would be too deep', () => {
        app.store.select({ kind: 'element', id: ID.cols });
        root.querySelector<HTMLElement>('[data-builder-palette-item="section"]')!.click();
        const id =
            app.store.state.selection?.kind === 'element' ? app.store.state.selection.id : '';
        expect(nodeEntry(app.store.state.definition, id)?.container).toEqual({
            kind: 'children',
            parentId: ID.details,
        });
        expect(nodeEntry(app.store.state.definition, id)?.index).toBe(1);
    });
});

describe('Move menu and keyboard', () => {
    it('adds and removes columns from the Move menu, then focuses the card', () => {
        const open = () => {
            key(card(ID.cols), 'F10', { shiftKey: true });
            return card(ID.cols).querySelector<HTMLElement>('[role="menu"]')!;
        };
        let menu = open();
        expect(document.activeElement).toBe(menu.querySelector('[role="menuitem"]'));
        expect(
            [...menu.querySelectorAll<HTMLElement>('[data-builder-action*="column"]')].map(
                (item) => item.textContent,
            ),
        ).toEqual(['Add column', 'Remove column 1', 'Remove column 2']);
        expect(
            menu
                .querySelector('[data-builder-action="remove-column"]')!
                .getAttribute('aria-disabled'),
        ).toBe('true');
        menu.querySelector<HTMLElement>('[data-builder-action="add-column"]')!.click();
        expect(card(ID.cols).querySelectorAll(':scope [data-builder-list]')).toHaveLength(3);
        menu = open();
        menu.querySelector<HTMLElement>(
            '[data-builder-action="remove-column"][data-column="2"]',
        )!.click();
        expect(live()).toBe('Column removed.');
        expect(document.activeElement).toBe(card(ID.cols));
        key(card(ID.cols), 'ContextMenu');
        expect(card(ID.cols).querySelector('[role="menu"]')).not.toBeNull();
    });

    it('focuses the Columns card after removing a column with its button', () => {
        root.querySelector<HTMLElement>(
            `[data-builder-node="${ID.cols}"] [data-builder-action="add-column"]`,
        )!.click();
        card(ID.cols)
            .querySelectorAll<HTMLElement>('[data-builder-action="remove-column"]')[2]!
            .click();
        expect(document.activeElement).toBe(card(ID.cols));
    });

    it('asks before deleting a node with children or a published key', () => {
        confirm.mockReturnValueOnce(false);
        card(ID.details).focus();
        key(card(ID.details), 'Delete');
        expect(confirm).toHaveBeenLastCalledWith('Delete Details and everything in it?');
        expect(nodeEntry(app.store.state.definition, ID.details)).not.toBeNull();
        expect(live()).toBe('Delete of Details cancelled.');
        key(card(ID.description), 'Delete');
        expect(confirm).toHaveBeenLastCalledWith(
            expect.stringContaining('Beschreibung is published.'),
        );
        expect(nodeEntry(app.store.state.definition, ID.description)).toBeNull();
        confirm.mockClear();
        key(card(ID.qty), 'Delete');
        expect(confirm).not.toHaveBeenCalled();
    });
});

describe('properties and keyboard help', () => {
    it('goes to the first inspector field with the button and Alt+P, never while typing', () => {
        const field = inspector.querySelector<HTMLElement>('[data-test-input]')!;
        root.querySelector<HTMLElement>('[data-builder-goto-inspector]')!.click();
        expect(document.activeElement).toBe(field);
        card(ID.customer).focus();
        key(card(ID.customer), 'π', { altKey: true, code: 'KeyP' });
        expect(document.activeElement).toBe(field);
        const other = inspector.querySelector<HTMLInputElement>('[data-test-key]')!;
        other.focus();
        key(other, 'p', { altKey: true, code: 'KeyP' });
        expect(document.activeElement).toBe(other);
    });

    it('announces when the inspector has no field, and toggles the visible keyboard help', () => {
        inspector.replaceChildren();
        root.querySelector<HTMLElement>('[data-builder-goto-inspector]')!.click();
        expect(live()).toBe('Select an element, a page or the form settings first.');
        const toggle = root.querySelector<HTMLElement>('[data-builder-help-toggle]')!;
        const help = root.querySelector<HTMLElement>('[data-builder-help]')!;
        toggle.click();
        expect(help.hidden).toBe(false);
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        toggle.click();
        expect(help.hidden).toBe(true);
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
    });
});
