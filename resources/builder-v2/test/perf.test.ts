import { describe, expect, it } from 'vitest';
import { TypeIcons } from '../src/canvas/icons.ts';
import { CanvasRenderer, type ViewState } from '../src/canvas/render.ts';
import { Translator, toStrings } from '../src/i18n.ts';
import { BuilderStore } from '../src/store/store.ts';
import { indexTree } from '../src/store/tree.ts';
import { syntheticDefinition } from './support/fixtures.ts';

const VIEW: ViewState = {
    selection: null,
    lockedIds: new Set(),
    markers: new Map(),
    collapsed: new Set(),
    grabbedId: null,
};

describe('performance smoke (300 elements)', () => {
    it('renders and applies 50 moves within a generous budget', () => {
        document.body.innerHTML =
            '<div role="tree" data-builder-tree data-builder-list data-builder-container="pages"></div>';
        const tree = document.querySelector<HTMLElement>('[data-builder-tree]')!;
        const renderer = new CanvasRenderer(tree, {
            tr: new Translator(toStrings({})),
            icons: new TypeIcons(null, new Map()),
        });
        const store = new BuilderStore(syntheticDefinition(300), { maxContainerDepth: 3 });
        const started = performance.now();
        renderer.render(store.state.definition, VIEW);
        const firstRender = performance.now() - started;
        expect(tree.querySelectorAll('[data-builder-node]').length).toBeGreaterThanOrEqual(300);

        const entries = [...indexTree(store.state.definition).nodes.values()].filter(
            (entry) => entry.kind === 'element' && entry.container.kind === 'children',
        );
        const durations: number[] = [];
        for (const entry of entries.slice(0, 50)) {
            const target = entries[(entries.indexOf(entry) * 7 + 3) % entries.length]!.container;
            const begin = performance.now();
            store.dispatch({ op: 'move', id: entry.id, target, index: 0 }, { label: 'move' });
            renderer.render(store.state.definition, VIEW);
            durations.push(performance.now() - begin);
        }
        durations.sort((a, b) => a - b);
        const p95 = durations[Math.floor(durations.length * 0.95)]!;
        // ADR target is 50 ms p95 in a browser; jsdom is far slower, so the budget here is generous.
        expect(firstRender).toBeLessThan(3000);
        expect(p95).toBeLessThan(250);
        expect(tree.querySelectorAll('[data-builder-node]').length).toBe(
            indexTree(store.state.definition).nodes.size - 7,
        );
    });
});
