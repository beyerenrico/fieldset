import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { BuilderWire } from '../src/contract.ts';
import { Translator, toStrings } from '../src/i18n.ts';
import {
    COMMIT_CHANGE_MS,
    COMMIT_INPUT_MS,
    InspectorGlue,
    NODE_CHANGED_EVENT,
} from '../src/inspector.ts';
import { KEY_SUGGESTED_EVENT } from '../src/key-suggestion.ts';
import { BuilderStore } from '../src/store/store.ts';
import { nodeEntry } from '../src/store/tree.ts';
import { contractDefinition, ID } from './support/fixtures.ts';
import { englishStrings, settle } from './support/dom.ts';

/* Gate fixes of the inspector glue: commit status, grabs, deleted nodes, key suggestions. */

let store: BuilderStore;
let inspect: ReturnType<typeof vi.fn<BuilderWire['inspect']>>;
let commit: ReturnType<typeof vi.fn<BuilderWire['commitInspector']>>;
let glue: InspectorGlue;
let pane: HTMLElement;
let keyField: HTMLInputElement;
let labelField: HTMLInputElement;

function nodeChanged(id: string, props: Record<string, unknown>): void {
    window.dispatchEvent(
        new CustomEvent(NODE_CHANGED_EVENT, {
            detail: { kind: 'element', id, node: JSON.stringify({ id, ...props }) },
        }),
    );
}

function suggest(id: string, key: string, from: string): void {
    window.dispatchEvent(new CustomEvent(KEY_SUGGESTED_EVENT, { detail: { id, key, from } }));
}

function typeInto(input: HTMLInputElement, value: string): void {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

beforeEach(() => {
    vi.useFakeTimers();
    document.body.innerHTML = `<aside data-builder-inspector>
        <input type="text" wire:model.live.blur="inspectorData.label" data-label />
        <input type="text" wire:model="inspectorData.key" data-key />
    </aside>`;
    pane = document.querySelector('[data-builder-inspector]')!;
    keyField = pane.querySelector('[data-key]')!;
    labelField = pane.querySelector('[data-label]')!;
    store = new BuilderStore(contractDefinition(), { maxContainerDepth: 3 });
    inspect = vi.fn<BuilderWire['inspect']>(() => Promise.resolve());
    commit = vi.fn<BuilderWire['commitInspector']>(() => Promise.resolve());
    glue = new InspectorGlue({
        store,
        wire: { inspect, commitInspector: commit },
        tr: new Translator(toStrings(englishStrings())),
        announce: vi.fn(),
        pane,
    });
});

afterEach(() => {
    glue.destroy();
    vi.useRealTimers();
});

describe('inspector commit status (publish and other flushes abort on failure)', () => {
    it('reports an accepted commit, a refused one (no node-changed) and a failed one', async () => {
        store.select({ kind: 'element', id: ID.customer });
        await settle();
        commit.mockImplementationOnce(() => {
            nodeChanged(ID.customer, { type: 'text', key: 'customer', label: 'Kunde (Firma)' });
            return Promise.resolve();
        });
        typeInto(labelField, 'Kunde (Firma)');
        expect(glue.busy).toBe(true);
        await expect(glue.flush()).resolves.toBe(true);
        expect(glue.busy).toBe(false);
        typeInto(labelField, '');
        await expect(glue.flush()).resolves.toBe(false); // field errors: nothing dispatched
        await expect(glue.flush()).resolves.toBe(false); // still refused until edited again
        commit.mockRejectedValueOnce(new Error('offline'));
        typeInto(labelField, 'Kunde');
        await expect(glue.flush()).resolves.toBe(false);
    });

    it('forgets a refused edit once another node is inspected', async () => {
        store.select({ kind: 'element', id: ID.customer });
        await settle();
        typeInto(labelField, '');
        await expect(glue.flush()).resolves.toBe(false);
        store.select({ kind: 'element', id: ID.qty });
        await settle();
        await expect(glue.flush()).resolves.toBe(true);
    });
});

describe('inspector and keyboard grabs', () => {
    it('holds node-changed back during a grab, so Esc still restores the original position', async () => {
        const original = store.state.definition;
        store.restore(); // what KeyboardController.pickUp does
        store.applyTransient({
            op: 'move',
            id: ID.customer,
            target: { kind: 'page', pageId: ID.pageStart },
            index: 1,
        });
        nodeChanged(ID.qty, { type: 'number', key: 'qty', label: 'Anzahl' });
        expect(nodeEntry(store.state.definition, ID.qty)?.node.label).toBe('Menge');
        expect(store.state.history.past).toHaveLength(0);
        store.restore(); // Esc
        expect(store.state.definition).toBe(original);
        await settle();
        expect(nodeEntry(store.state.definition, ID.qty)?.node.label).toBe('Anzahl');
        expect(nodeEntry(store.state.definition, ID.customer)?.index).toBe(0);
        expect(store.state.history.past).toHaveLength(1);
        store.undo();
        expect(store.state.definition).toBe(original);
    });

    it('applies the held change after a drop, on top of the recorded move', async () => {
        store.applyTransient({
            op: 'move',
            id: ID.customer,
            target: { kind: 'page', pageId: ID.pageStart },
            index: 1,
        });
        nodeChanged(ID.qty, { type: 'number', key: 'qty', label: 'Anzahl' });
        store.commit('Move Kunde');
        await settle();
        expect(nodeEntry(store.state.definition, ID.customer)?.index).toBe(1);
        expect(nodeEntry(store.state.definition, ID.qty)?.node.label).toBe('Anzahl');
        expect(store.state.history.past.map((entry) => entry.label)).toEqual(['', 'Move Kunde']);
    });
});

describe('inspector after a delete', () => {
    it('shows the form settings once the inspected node is gone', async () => {
        store.select({ kind: 'element', id: ID.qty });
        await settle();
        store.dispatch({ op: 'remove', id: ID.qty }, { label: 'Delete Menge' });
        await settle();
        expect(store.state.selection).toBeNull();
        expect(inspect).toHaveBeenLastCalledWith(
            'settings',
            null,
            expect.not.stringContaining('"pages"'),
            expect.any(String),
        );
        inspect.mockClear();
        store.dispatch(
            { op: 'update', kind: 'settings', id: null, props: { title: 'X' } },
            { label: 'Edit' },
        );
        await settle();
        expect(inspect).not.toHaveBeenCalled();
    });
});

describe('key suggestions', () => {
    const NEW_ID = '0192f1c4-0000-7000-8000-00000000c001';

    beforeEach(async () => {
        store.dispatch(
            {
                op: 'insert',
                target: { kind: 'page', pageId: ID.pageStart },
                index: 0,
                node: { id: NEW_ID, type: 'text', key: 'text', label: 'Text' },
            },
            { label: 'Insert Text', select: { kind: 'element', id: NEW_ID } },
        );
        store.setAutoKey(NEW_ID, true);
        await settle();
        keyField.value = 'text';
        labelField.value = 'Text';
    });

    it('fills the suggested key while the key field still shows what the server saw', async () => {
        const typed = vi.fn();
        keyField.addEventListener('input', typed); // Livewire's wire:model listens for input
        suggest(NEW_ID, 'customer', 'text');
        expect(keyField.value).toBe('customer');
        expect(typed).toHaveBeenCalledTimes(1);
        expect(store.state.autoKeyIds.has(NEW_ID)).toBe(true);
        vi.advanceTimersByTime(COMMIT_CHANGE_MS);
        await settle();
        expect(commit).toHaveBeenCalledTimes(1);
        expect(JSON.parse(commit.mock.calls[0]![0]) as { autoKey: boolean }).toMatchObject({
            autoKey: true,
        });
    });

    it('keeps a key typed while the label request was in flight and stops the auto key', async () => {
        typeInto(keyField, 'my_key');
        suggest(NEW_ID, 'customer', 'text');
        expect(keyField.value).toBe('my_key');
        expect(store.state.autoKeyIds.has(NEW_ID)).toBe(false);
        vi.advanceTimersByTime(COMMIT_INPUT_MS);
        await settle();
        expect(JSON.parse(commit.mock.calls[0]![0]) as { autoKey: boolean }).toMatchObject({
            autoKey: false,
        });
    });

    it('ignores suggestions for another node, a disabled key and malformed events', () => {
        suggest(ID.customer, 'other', 'text');
        window.dispatchEvent(new CustomEvent(KEY_SUGGESTED_EVENT, { detail: { key: 1 } }));
        expect(keyField.value).toBe('text');
        keyField.disabled = true;
        suggest(NEW_ID, 'customer', 'text');
        expect(keyField.value).toBe('text');
    });

    it('typing in the label does not end the auto key, typing in the key does', () => {
        typeInto(labelField, 'Kunde');
        expect(store.state.autoKeyIds.has(NEW_ID)).toBe(true);
        typeInto(keyField, 'k');
        expect(store.state.autoKeyIds.has(NEW_ID)).toBe(false);
    });
});
