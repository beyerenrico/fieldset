/**
 * The canvas page skeleton as resources/views/filament/forms/builder-v2/page.blade.php renders it
 * (same data attributes), plus a fake BuilderWire and the strings payload.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { vi, type Mock } from 'vitest';
import type { BuilderConfig, BuilderWire, SyncResult } from '../../src/contract.ts';
import { contractConfig } from './fixtures.ts';

const repo = resolve(import.meta.dirname, '../../../..');

/** English UI strings parsed from lang/en/builder_v2.php (keeps tests honest about real keys). */
export function englishStrings(): {
    ui: Record<string, unknown>;
    lint: Record<string, string>;
    types: Record<string, string>;
} {
    const php = readFileSync(resolve(repo, 'lang/en/builder_v2.php'), 'utf8');
    const body = php.slice(php.indexOf('return [') + 'return '.length, php.lastIndexOf('];') + 1);
    const json = body
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/'((?:[^'\\]|\\.)*)'/g, (_match, text: string) =>
            JSON.stringify(text.replace(/\\'/g, "'")),
        )
        .replace(/=>/g, ':')
        .replace(/\[/g, '{')
        .replace(/\]/g, '}')
        .replace(/,(\s*})/g, '$1');
    return {
        ui: JSON.parse(json) as Record<string, unknown>,
        lint: {
            duplicate_key: 'Key is used twice in the same scope',
            label_missing: 'Label missing',
        },
        types: {
            page: 'Page',
            text: 'Text',
            number: 'Number',
            date: 'Date',
            section: 'Section',
            repeater: 'Repeater',
            columns: 'Columns',
        },
    };
}

const TOOLBAR = [
    'undo',
    'redo',
    'save',
    'publish',
    'preview-pdf',
    'export',
    'preview-toggle',
    'json',
    'classic',
];

export function skeleton(config: BuilderConfig = contractConfig()): {
    root: HTMLElement;
    inspector: HTMLElement;
} {
    document.body.innerHTML = `
<div class="bv2-layout">
  <div class="bv2-root" data-builder-root data-builder-sync-state="idle" data-builder-preview-size="phone">
    <script type="application/json" data-builder-config></script>
    <template data-builder-icons><span data-icon="heroicon-o-pencil"><svg viewBox="0 0 24 24"><path d="M0 0"/></svg></span></template>
    <p id="bv2-handle-help" class="bv2-sr-only">help</p>
    <div class="bv2-sr-only" data-builder-live aria-live="polite" aria-atomic="true"></div>
    <div class="bv2-toolbar" role="toolbar">
      ${TOOLBAR.map((command) => `<button type="button" data-builder-toolbar="${command}">${command}</button>`).join('')}
      <span data-builder-sync-label></span>
    </div>
    <div data-builder-banner hidden></div>
    <nav data-builder-palette aria-label="Elements"></nav>
    <section class="bv2-canvas">
      <button type="button" data-builder-settings aria-pressed="false">Settings</button>
      <div data-builder-lint><h2><span data-builder-lint-count></span></h2><ul data-builder-lint-list></ul></div>
      <div class="bv2-tree" role="tree" aria-label="Form structure" data-builder-tree data-builder-list data-builder-container="pages"></div>
      <button type="button" data-builder-add-page>Add page</button>
    </section>
    <section class="bv2-preview"><iframe data-builder-preview data-size="phone" src="about:blank" title="Preview"></iframe></section>
    <dialog data-builder-json><pre data-builder-json-content></pre><button type="button" data-builder-json-close>Close</button></dialog>
  </div>
  <aside data-builder-inspector><input data-test-input type="text" /></aside>
</div>`;
    const root = document.querySelector<HTMLElement>('[data-builder-root]')!;
    root.querySelector('script[data-builder-config]')!.textContent = JSON.stringify(config);
    return { root, inspector: document.querySelector<HTMLElement>('[data-builder-inspector]')! };
}

export function syncResult(overrides: Partial<SyncResult> = {}): SyncResult {
    return {
        status: 'saved',
        checksum: 'b'.repeat(64),
        lint: { schema_errors: [], findings: [] },
        savedAt: null,
        ...overrides,
    };
}

export interface FakeWire {
    readonly sync: Mock<BuilderWire['sync']>;
    readonly save: Mock<BuilderWire['save']>;
    readonly inspect: Mock<BuilderWire['inspect']>;
    readonly commitInspector: Mock<BuilderWire['commitInspector']>;
    readonly cloneNode: Mock<BuilderWire['cloneNode']>;
    readonly mountAction: Mock<BuilderWire['mountAction']>;
}

/** A BuilderWire made of typed vi.fn() spies resolving to sensible defaults. */
export function fakeWire(): FakeWire {
    return {
        sync: vi.fn<BuilderWire['sync']>(() =>
            Promise.resolve(
                syncResult({ status: 'unchanged', checksum: contractConfig().checksum }),
            ),
        ),
        save: vi.fn<BuilderWire['save']>(() => Promise.resolve(syncResult())),
        inspect: vi.fn<BuilderWire['inspect']>(() => Promise.resolve()),
        commitInspector: vi.fn<BuilderWire['commitInspector']>(() => Promise.resolve()),
        cloneNode: vi.fn<BuilderWire['cloneNode']>((node) => {
            const parsed = JSON.parse(node) as Record<string, unknown>;
            const copy = {
                ...parsed,
                id: '0192f1c4-0000-7000-8000-0000000c1011',
                key: `${String(parsed.key)}_copy`,
            };
            return Promise.resolve(JSON.stringify(copy));
        }),
        mountAction: vi.fn<BuilderWire['mountAction']>(() => Promise.resolve()),
    };
}

export function key(target: Element, keyName: string, init: KeyboardEventInit = {}): KeyboardEvent {
    const event = new KeyboardEvent('keydown', {
        key: keyName,
        bubbles: true,
        cancelable: true,
        ...init,
    });
    target.dispatchEvent(event);
    return event;
}

/** Lets queued promise callbacks run. */
export async function settle(): Promise<void> {
    for (let round = 0; round < 5; round++) {
        await Promise.resolve();
    }
}
