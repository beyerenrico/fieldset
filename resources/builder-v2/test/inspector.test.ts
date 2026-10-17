import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { BuilderWire, InspectorScope, NodeChangedDetail } from '../src/contract.ts';
import { Translator, toStrings } from '../src/i18n.ts';
import {
    COMMIT_CHANGE_MS,
    COMMIT_INPUT_MS,
    inspectedNode,
    InspectorGlue,
    NODE_CHANGED_EVENT,
    PUBLISHED_EVENT,
} from '../src/inspector.ts';
import { BuilderStore } from '../src/store/store.ts';
import { nodeEntry } from '../src/store/tree.ts';
import { contractDefinition, contractFixture, ID } from './support/fixtures.ts';
import { englishStrings, settle } from './support/dom.ts';

let store: BuilderStore;
let inspect: ReturnType<typeof vi.fn<BuilderWire['inspect']>>;
let commit: ReturnType<typeof vi.fn<BuilderWire['commitInspector']>>;
let announce: ReturnType<typeof vi.fn<(message: string) => void>>;
let glue: InspectorGlue;
let pane: HTMLElement;

function nodeChanged(detail: unknown): void {
    window.dispatchEvent(new CustomEvent(NODE_CHANGED_EVENT, { detail }));
}

beforeEach(() => {
    vi.useFakeTimers();
    document.body.innerHTML = '<aside data-builder-inspector><input type="text" /></aside>';
    pane = document.querySelector('[data-builder-inspector]')!;
    store = new BuilderStore(contractDefinition(), { maxContainerDepth: 3 });
    inspect = vi.fn<BuilderWire['inspect']>(() => Promise.resolve());
    commit = vi.fn<BuilderWire['commitInspector']>(() => Promise.resolve());
    announce = vi.fn<(message: string) => void>();
    glue = new InspectorGlue({
        store,
        wire: { inspect, commitInspector: commit },
        tr: new Translator(toStrings(englishStrings())),
        announce,
        pane,
    });
});

afterEach(() => {
    glue.destroy();
    vi.useRealTimers();
});

describe('inspector glue', () => {
    it('inspects the selected node without children, with its scope', async () => {
        store.select({ kind: 'element', id: ID.details });
        await settle();
        const [kind, id, node, scope] = inspect.mock.calls[0]!;
        expect([kind, id]).toEqual(['element', ID.details]);
        expect(JSON.parse(node)).toEqual({
            id: ID.details,
            type: 'section',
            key: 'details',
            label: 'Details',
        });
        expect((JSON.parse(scope) as InspectorScope).scopeKeys).toEqual([
            'customer',
            'details',
            'cols',
            'day',
            'items',
        ]);
        expect(inspectedNode(store.state.definition, { kind: 'settings' })).not.toHaveProperty(
            'pages',
        );
        expect(inspectedNode(store.state.definition, null)).toBeNull();
    });

    it('applies node-changed as an update that keeps children and merges within a second', () => {
        const children = nodeEntry(store.state.definition, ID.details)?.node.elements;
        nodeChanged(contractFixture('node-changed'));
        expect(nodeEntry(store.state.definition, ID.customer)?.node.label).toBe('Kunde (Firma)');
        expect(store.state.history.past).toHaveLength(1);
        vi.advanceTimersByTime(300);
        nodeChanged({
            kind: 'element',
            id: ID.details,
            node: JSON.stringify({
                id: ID.details,
                type: 'section',
                key: 'details',
                label: 'Mehr',
            }),
        });
        expect(nodeEntry(store.state.definition, ID.details)?.node.elements).toBe(children);
        nodeChanged({
            kind: 'element',
            id: ID.details,
            node: JSON.stringify({
                id: ID.details,
                type: 'section',
                key: 'details',
                label: 'Mehr Details',
            }),
        });
        expect(store.state.history.past).toHaveLength(2);
        nodeChanged({
            kind: 'element',
            id: ID.details,
            node: JSON.stringify({
                id: ID.details,
                type: 'section',
                key: 'details',
                label: 'Mehr Details',
            }),
        });
        expect(store.state.history.past).toHaveLength(2);
    });

    it('removes properties missing from node-changed and ignores malformed events', () => {
        const detail: NodeChangedDetail = {
            kind: 'element',
            id: ID.customer,
            node: JSON.stringify({
                id: ID.customer,
                type: 'text',
                key: 'customer',
                label: 'Kunde',
            }),
        };
        nodeChanged([detail]);
        expect(nodeEntry(store.state.definition, ID.customer)?.node).not.toHaveProperty('required');
        expect(nodeEntry(store.state.definition, ID.customer)?.node).not.toHaveProperty('x-crm');
        const before = store.state;
        nodeChanged({ kind: 'element', id: ID.customer, node: '{oops' });
        nodeChanged({ kind: 'other', id: ID.customer, node: '{}' });
        nodeChanged(null);
        expect(store.state).toBe(before);
    });

    it('updates the settings without touching pages and stops auto keys once the key is edited', () => {
        const pages = store.state.definition.pages;
        nodeChanged({
            kind: 'settings',
            id: null,
            node: JSON.stringify({ ...contractDefinition(), title: 'Neu', pages: [] }),
        });
        expect(store.state.definition.title).toBe('Neu');
        expect(store.state.definition.pages).toBe(pages);
        store.setAutoKey(ID.day, true);
        nodeChanged({
            kind: 'element',
            id: ID.day,
            node: JSON.stringify({ id: ID.day, type: 'date', key: 'day', label: 'Datum' }),
        });
        expect(store.state.autoKeyIds.has(ID.day)).toBe(true);
        nodeChanged({
            kind: 'element',
            id: ID.day,
            node: JSON.stringify({ id: ID.day, type: 'date', key: 'visit_day', label: 'Datum' }),
        });
        expect(store.state.autoKeyIds.has(ID.day)).toBe(false);
    });

    it('commits inspector edits on change and input, before inspecting the next node', async () => {
        store.select({ kind: 'element', id: ID.customer });
        await settle();
        pane.querySelector('input')!.dispatchEvent(new Event('input', { bubbles: true }));
        vi.advanceTimersByTime(COMMIT_INPUT_MS - 1);
        expect(commit).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1);
        await settle();
        expect(commit).toHaveBeenCalledTimes(1);
        pane.querySelector('input')!.dispatchEvent(new Event('change', { bubbles: true }));
        store.select({ kind: 'element', id: ID.qty });
        await settle();
        expect(commit).toHaveBeenCalledTimes(2);
        expect((JSON.parse(commit.mock.calls[1]![0]) as InspectorScope).scopeKeys).toEqual([
            'customer',
            'details',
            'cols',
            'day',
            'items',
        ]);
        expect(inspect).toHaveBeenLastCalledWith(
            'element',
            ID.qty,
            expect.any(String),
            expect.any(String),
        );
        vi.advanceTimersByTime(COMMIT_CHANGE_MS);
        await settle();
        expect(commit).toHaveBeenCalledTimes(2);
    });

    it('re-inspects after undo and takes locked ids from builder-v2:published', async () => {
        store.select({ kind: 'element', id: ID.customer });
        nodeChanged(contractFixture('node-changed'));
        await settle();
        inspect.mockClear();
        store.undo();
        await settle();
        expect(inspect).toHaveBeenCalledTimes(1);
        window.dispatchEvent(
            new CustomEvent(PUBLISHED_EVENT, { detail: contractFixture('published') }),
        );
        expect([...store.state.lockedIds]).toHaveLength(2);
        expect(announce).toHaveBeenCalledWith('Version 1 published.');
    });
});
