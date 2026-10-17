import { describe, expect, it, vi } from 'vitest';
import builderV2 from '../src/main.ts';
import type { LivewireProxy } from '../src/sync/wire.ts';
import { englishStrings, fakeWire, settle, skeleton } from './support/dom.ts';

describe('Alpine entry', () => {
    it('mounts on $el with $wire, publishes the factory globally and cleans up', async () => {
        const { root } = skeleton();
        const wire = fakeWire();
        const component = builderV2(englishStrings());
        const context = { $el: root, $wire: wire as unknown as LivewireProxy, ready: false };
        component.init.call(context);
        await settle();
        expect(context.ready).toBe(true);
        expect(root.querySelectorAll('[data-builder-node]').length).toBeGreaterThan(0);
        expect(wire.sync).toHaveBeenCalled();
        expect(window.FormSchemaBuilderV2).toBe(builderV2);
        component.destroy();
        component.destroy();
    });

    it('refuses to run outside a Livewire component', () => {
        const { root } = skeleton();
        const component = builderV2();
        expect(() => {
            component.init.call({ $el: root, ready: false });
        }).toThrow(/Livewire/);
        vi.restoreAllMocks();
    });
});
