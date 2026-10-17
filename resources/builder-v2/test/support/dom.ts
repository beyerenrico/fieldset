/**
 * The canvas page skeleton as resources/views/filament/forms/builder-v2/page.blade.php renders it
 * (same data attributes and order: root, inspector, preview), plus a fake BuilderWire and the
 * strings payload. The inspector inputs carry Filament's `wire:model` bindings.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { vi, type Mock } from 'vitest';
import type { BuilderConfig, BuilderWire, SyncResult } from '../../src/contract.ts';
import { contractConfig } from './fixtures.ts';

const repo = resolve(import.meta.dirname, '../../../..');

/**
 * A PHP `return [...]` array of single-quoted strings as JSON. Brackets, `=>` and comments are only
 * translated outside strings, so plural strings like `{1} one|[2,*] many` survive.
 */
function phpArrayToJson(body: string): string {
    let json = '';
    for (let i = 0; i < body.length; i++) {
        const char = body.charAt(i);
        if (char === "'") {
            let text = '';
            let j = i + 1;
            for (; j < body.length && body.charAt(j) !== "'"; j++) {
                const escaped = body.charAt(j) === '\\' && ["'", '\\'].includes(body.charAt(j + 1));
                text += escaped ? body.charAt(++j) : body.charAt(j);
            }
            json += JSON.stringify(text);
            i = j;
        } else if (char === '/' && body.charAt(i + 1) === '*') {
            i = body.indexOf('*/', i + 2) + 1;
        } else if (char === '=' && body.charAt(i + 1) === '>') {
            json += ':';
            i += 1;
        } else {
            json += char === '[' ? '{' : char === ']' ? '}' : char;
        }
    }
    return json.replace(/,(\s*})/g, '$1');
}

/** UI strings parsed from lang/<locale>/builder_v2.php (keeps tests honest about real keys). */
export function langStrings(locale: 'en' | 'de'): Record<string, unknown> {
    const php = readFileSync(resolve(repo, `lang/${locale}/builder_v2.php`), 'utf8');
    const body = php.slice(php.indexOf('return [') + 'return '.length, php.lastIndexOf('];') + 1);
    return JSON.parse(phpArrayToJson(body)) as Record<string, unknown>;
}

/** English UI strings parsed from lang/en/builder_v2.php (keeps tests honest about real keys). */
export function englishStrings(): {
    ui: Record<string, unknown>;
    lint: Record<string, string>;
    types: Record<string, string>;
} {
    return {
        ui: langStrings('en'),
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
    <div class="bv2-toolbar" role="group" aria-label="Builder actions">
      ${TOOLBAR.map((command) => `<button type="button" data-builder-toolbar="${command}"${command === 'undo' || command === 'redo' ? ' aria-disabled="true"' : ''}>${command}</button>`).join('')}
      <span class="bv2-sr-only" id="bv2-publish-reason" data-builder-publish-reason></span>
      <span data-builder-sync-label></span>
    </div>
    <div data-builder-banner hidden></div>
    <section aria-labelledby="bv2-palette-title"><h2 id="bv2-palette-title">Elements</h2><div data-builder-palette></div></section>
    <section class="bv2-canvas" aria-label="Canvas">
      <button type="button" data-builder-settings aria-pressed="false">Settings</button>
      <button type="button" data-builder-goto-inspector aria-keyshortcuts="Alt+P">Go to properties</button>
      <button type="button" data-builder-help-toggle aria-expanded="false" aria-controls="bv2-help">Keyboard help</button>
      <div id="bv2-help" data-builder-help hidden><dl><div><dt>Alt+P</dt><dd>Go to properties</dd></div></dl></div>
      <div data-builder-lint><h2><span data-builder-lint-count></span></h2><ul data-builder-lint-list></ul></div>
      <div class="bv2-tree" role="tree" aria-label="Form structure" data-builder-tree data-builder-list data-builder-container="pages"></div>
      <button type="button" data-builder-add-page>Add page</button>
    </section>
    <dialog data-builder-json><pre data-builder-json-content></pre><button type="button" data-builder-json-close>Close</button></dialog>
  </div>
  <aside data-builder-inspector><input type="hidden" wire:model="inspectorData._source" /><input data-test-input type="text" wire:model.live.blur="inspectorData.label" /><input data-test-key type="text" wire:model="inspectorData.key" /></aside>
  <div class="bv2-preview-block"><section class="bv2-preview" data-builder-preview-pane data-size="phone"><iframe data-builder-preview data-size="phone" src="about:blank" title="Preview"></iframe></section></div>
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
