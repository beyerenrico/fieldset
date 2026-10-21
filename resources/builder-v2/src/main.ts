/**
 * Builder v2 entry (ADR 0037): loaded by async-alpine (`x-load`) on the CanvasBuilder page, which
 * calls `builderV2(strings)` as the Alpine component of the `wire:ignore` root. The app lives in a
 * closure, never in Alpine's reactive data.
 *
 * Vite app builds drop the exports of entry chunks (`preserveEntrySignatures: false`), so the
 * module also publishes the factory on `window`; the page's async-alpine downloader takes
 * `module.default` when present and the global otherwise (see page.blade.php).
 */
import { mountBuilder, type BuilderApp } from './app.ts';
import { createWire, type LivewireProxy } from './sync/wire.ts';

interface AlpineContext {
    readonly $el: HTMLElement;
    readonly $wire?: LivewireProxy;
    ready: boolean;
}

declare global {
    interface Window {
        FieldsetBuilderV2?: typeof builderV2;
    }
}

export interface BuilderV2Component {
    ready: boolean;
    init(this: AlpineContext): void;
    destroy(): void;
}

export default function builderV2(strings: unknown = {}): BuilderV2Component {
    let app: BuilderApp | null = null;
    return {
        ready: false,
        init(this: AlpineContext): void {
            if (this.$wire === undefined) {
                throw new Error('Builder v2 must run inside a Livewire component');
            }
            app = mountBuilder(this.$el, { wire: createWire(this.$wire), strings });
            this.ready = true;
        },
        destroy(): void {
            app?.destroy();
            app = null;
        },
    };
}

window.FieldsetBuilderV2 = builderV2;
