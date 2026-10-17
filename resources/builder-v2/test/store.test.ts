import { describe, expect, it, vi } from 'vitest';
import { BuilderStore, type Change } from '../src/store/store.ts';
import { nodeEntry } from '../src/store/tree.ts';
import { contractDefinition, ID } from './support/fixtures.ts';

function makeStore(now: () => number = () => 0): BuilderStore {
    return new BuilderStore(contractDefinition(), {
        maxContainerDepth: 3,
        lockedIds: [ID.customer],
        now,
    });
}

const toItems = {
    op: 'move',
    id: ID.customer,
    target: { kind: 'page', pageId: ID.pageItems },
    index: 0,
} as const;

describe('store', () => {
    it('dispatches valid operations as undo steps and notifies listeners', () => {
        const store = makeStore();
        const changes: Change[] = [];
        store.subscribe((_state, change) => changes.push(change));
        expect(store.dispatch(toItems, { label: 'Move Kunde' })).toBeNull();
        expect(nodeEntry(store.state.definition, ID.customer)?.pageId).toBe(ID.pageItems);
        expect(changes.map((change) => change.kind)).toEqual(['op']);
        expect(store.undo()).toBe('Move Kunde');
        expect(nodeEntry(store.state.definition, ID.customer)?.pageId).toBe(ID.pageStart);
        expect(store.redo()).toBe('Move Kunde');
        expect(store.redo()).toBeNull();
        expect(store.state.lockedIds.has(ID.customer)).toBe(true);
    });

    it('refuses invalid operations without changing state', () => {
        const store = makeStore();
        const listener = vi.fn();
        store.subscribe(listener);
        const before = store.state;
        expect(
            store.dispatch(
                {
                    op: 'move',
                    id: ID.details,
                    target: { kind: 'children', parentId: ID.details },
                    index: 0,
                },
                { label: 'x' },
            ),
        ).toBe('into_itself');
        expect(store.dispatch({ op: 'remove', id: 'missing' }, { label: 'x' })).toBe(
            'unknown_node',
        );
        expect(store.state).toBe(before);
        expect(listener).not.toHaveBeenCalled();
    });

    it('drops a selection whose node disappeared', () => {
        const store = makeStore();
        store.select({ kind: 'element', id: ID.qty });
        store.dispatch({ op: 'remove', id: ID.qty }, { label: 'Delete' });
        expect(store.state.selection).toBeNull();
        store.undo();
        expect(store.state.selection).toEqual({ kind: 'element', id: ID.qty });
        store.select({ kind: 'page', id: ID.customer });
        expect(store.state.selection).toBeNull();
        store.select({ kind: 'settings' });
        expect(store.state.selection).toEqual({ kind: 'settings' });
    });

    it('applies transient moves and commits or restores them', () => {
        const store = makeStore();
        const original = store.state.definition;
        expect(store.applyTransient(toItems)).toBeNull();
        expect(store.applyTransient({ op: 'remove', id: 'missing' })).toBe('unknown_node');
        store.restore();
        expect(store.state.definition).toBe(original);
        store.restore();
        store.applyTransient(toItems);
        store.commit('Move Kunde');
        store.commit('again');
        expect(store.state.history.past).toHaveLength(1);
        expect(store.undo()).toBe('Move Kunde');
        expect(store.state.definition).toBe(original);
    });

    it('merges inspector updates to the same node within one second', () => {
        let now = 0;
        const store = makeStore(() => now);
        const update = (label: string) =>
            store.dispatch(
                {
                    op: 'update',
                    kind: 'element',
                    id: ID.customer,
                    props: { id: ID.customer, type: 'text', key: 'customer', label },
                },
                { label: 'Edit', mergeKey: `update:${ID.customer}` },
            );
        update('A');
        now = 500;
        update('B');
        expect(store.state.history.past).toHaveLength(1);
        now = 2000;
        update('C');
        expect(store.state.history.past).toHaveLength(2);
    });

    it('tracks locked ids and auto-key ids', () => {
        const store = makeStore();
        store.setLockedIds([ID.qty]);
        expect([...store.state.lockedIds]).toEqual([ID.qty]);
        store.setAutoKey(ID.day, true);
        expect(store.state.autoKeyIds.has(ID.day)).toBe(true);
        store.setAutoKey(ID.day, false);
        expect(store.state.autoKeyIds.size).toBe(0);
    });
});
