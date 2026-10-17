import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mountBuilder, type BuilderApp } from '../src/app.ts';
import { nodeEntry } from '../src/store/tree.ts';
import { contractConfig, ID } from './support/fixtures.ts';
import { englishStrings, fakeWire, key, settle, skeleton, syncResult } from './support/dom.ts';

let app: BuilderApp | null = null;

function mount(
    overrides: { confirm?: (m: string) => boolean; navigate?: (url: string) => void } = {},
) {
    const { root, inspector } = skeleton();
    const wire = fakeWire();
    app = mountBuilder(root, { wire, strings: englishStrings(), ...overrides });
    return { root, inspector, wire, app };
}

function node(root: HTMLElement, id: string): HTMLElement {
    return root.querySelector<HTMLElement>(
        `[data-builder-node="${id}"], [data-builder-page="${id}"]`,
    )!;
}

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    app?.destroy();
    app = null;
    vi.useRealTimers();
});

describe('mountBuilder', () => {
    it('renders pages, nodes, lists and the palette with the contract data attributes', async () => {
        const { root, wire } = mount();
        await settle();
        expect(root.querySelectorAll('[data-builder-page]')).toHaveLength(2);
        expect(node(root, ID.pageStart).dataset.builderPageKey).toBe('start');
        expect(node(root, ID.customer).dataset.builderType).toBe('text');
        expect(node(root, ID.customer).dataset.builderKey).toBe('customer');
        expect(node(root, ID.customer).hasAttribute('data-builder-locked')).toBe(true);
        const containers = [...root.querySelectorAll<HTMLElement>('[data-builder-list]')].map(
            (el) => el.dataset.builderContainer,
        );
        expect(containers).toEqual(
            expect.arrayContaining([
                'pages',
                `page:${ID.pageStart}`,
                `children:${ID.details}`,
                `column:${ID.cols}:0`,
                `column:${ID.cols}:1`,
                `children:${ID.items}`,
            ]),
        );
        expect(
            [...root.querySelectorAll<HTMLElement>('[data-builder-palette-item]')].map(
                (el) => el.dataset.builderPaletteItem,
            ),
        ).toEqual(['text', 'number', 'section', 'repeater', 'columns']);
        expect(wire.sync).toHaveBeenCalledTimes(1);
        expect(root.dataset.builderSyncState).toBe('saved');
    });

    it('inserts from the palette after the selection, selects and announces it', async () => {
        const { root, wire } = mount();
        await settle();
        node(root, ID.customer).click();
        await settle();
        expect(wire.inspect).toHaveBeenCalledWith(
            'element',
            ID.customer,
            expect.any(String),
            expect.any(String),
        );
        root.querySelector<HTMLElement>('[data-builder-palette-item="text"]')!.click();
        const definition = app!.store.state.definition;
        const page = nodeEntry(definition, ID.pageStart)!.node.elements as { key: string }[];
        expect(page.map((element) => element.key)).toEqual(['customer', 'text', 'details']);
        expect(root.querySelector('[data-builder-live]')!.textContent).toBe('Text inserted.');
        expect(root.dataset.builderSyncState).toBe('pending');
        await vi.advanceTimersByTimeAsync(800);
        expect(wire.sync).toHaveBeenCalledTimes(2);
        expect(root.dataset.builderSyncState).toBe('saved');
    });

    it('undoes and redoes from the toolbar and with shortcuts, but not inside text inputs', async () => {
        const { root, inspector } = mount();
        await settle();
        const undo = root.querySelector<HTMLButtonElement>('[data-builder-toolbar="undo"]')!;
        expect(undo.getAttribute('aria-disabled')).toBe('true');
        root.querySelector<HTMLElement>('[data-builder-add-page]')!.click();
        expect(root.querySelectorAll('[data-builder-page]')).toHaveLength(3);
        expect(undo.hasAttribute('aria-disabled')).toBe(false);
        key(inspector.querySelector('[data-test-input]')!, 'z', { ctrlKey: true });
        expect(root.querySelectorAll('[data-builder-page]')).toHaveLength(3);
        key(document.body, 'z', { ctrlKey: true });
        expect(root.querySelectorAll('[data-builder-page]')).toHaveLength(2);
        expect(root.querySelector('[data-builder-live]')!.textContent).toContain('Undone');
        key(document.body, 'z', { metaKey: true, shiftKey: true });
        expect(root.querySelectorAll('[data-builder-page]')).toHaveLength(3);
        undo.click();
        expect(root.querySelectorAll('[data-builder-page]')).toHaveLength(2);
        root.querySelector<HTMLButtonElement>('[data-builder-toolbar="redo"]')!.click();
        expect(root.querySelectorAll('[data-builder-page]')).toHaveLength(3);
    });

    it('flushes before publish and mounts the action with the acknowledged checksum', async () => {
        const { root, wire } = mount();
        await settle();
        root.querySelector<HTMLElement>('[data-builder-add-page]')!.click();
        wire.sync.mockResolvedValueOnce(syncResult({ checksum: 'a'.repeat(64) }));
        root.querySelector<HTMLElement>('[data-builder-toolbar="publish"]')!.click();
        await settle();
        await settle();
        expect(wire.sync).toHaveBeenCalledTimes(2);
        expect(wire.mountAction).toHaveBeenCalledWith('publish', { checksum: 'a'.repeat(64) });
    });

    it('does not run actions when the flush is refused', async () => {
        const { root, wire } = mount();
        await settle();
        root.querySelector<HTMLElement>('[data-builder-add-page]')!.click();
        wire.sync.mockResolvedValueOnce(
            syncResult({
                status: 'invalid',
                checksum: contractConfig().checksum,
                lint: { schema_errors: [{ path: '/pages/2', message: 'bad' }], findings: [] },
            }),
        );
        root.querySelector<HTMLElement>('[data-builder-toolbar="export"]')!.click();
        await settle();
        await settle();
        expect(wire.mountAction).not.toHaveBeenCalled();
        expect(root.dataset.builderSyncState).toBe('invalid');
        expect(root.querySelector<HTMLElement>('[data-builder-banner]')!.hidden).toBe(false);
        expect(
            root
                .querySelector<HTMLButtonElement>('[data-builder-toolbar="publish"]')!
                .getAttribute('aria-disabled'),
        ).toBe('true');
        expect(root.querySelectorAll('[data-builder-lint-item]')).toHaveLength(1);
    });

    it('switches to the classic builder after a flush and prompts before leaving with unsaved changes', async () => {
        const navigate = vi.fn();
        const { root } = mount({ navigate });
        await settle();
        root.querySelector<HTMLElement>('[data-builder-add-page]')!.click();
        const leave = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(leave);
        expect(leave.defaultPrevented).toBe(true);
        root.querySelector<HTMLElement>('[data-builder-toolbar="classic"]')!.click();
        await settle();
        await settle();
        expect(navigate).toHaveBeenCalledWith('/admin/demo/forms/42/edit');
        const after = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(after);
        expect(after.defaultPrevented).toBe(false);
    });

    it('shows conflicts and overwrites with force', async () => {
        const { root, wire } = mount({ confirm: () => true });
        await settle();
        root.querySelector<HTMLElement>('[data-builder-add-page]')!.click();
        wire.sync.mockResolvedValueOnce(
            syncResult({ status: 'conflict', checksum: 'c'.repeat(64) }),
        );
        await vi.advanceTimersByTimeAsync(800);
        expect(root.dataset.builderSyncState).toBe('conflict');
        root.querySelector<HTMLElement>('[data-builder-conflict="overwrite"]')!.click();
        await settle();
        expect(wire.sync).toHaveBeenLastCalledWith(
            expect.any(String),
            contractConfig().checksum,
            true,
        );
        expect(root.dataset.builderSyncState).toBe('saved');
    });

    it('toggles the preview size and opens the read-only JSON view', async () => {
        const { root } = mount();
        await settle();
        const toggle = root.querySelector<HTMLElement>('[data-builder-toolbar="preview-toggle"]')!;
        toggle.click();
        await settle();
        expect(toggle.getAttribute('aria-pressed')).toBe('true');
        expect(
            document.querySelector<HTMLIFrameElement>('iframe[data-builder-preview]')!.dataset.size,
        ).toBe('tablet');
        root.querySelector<HTMLElement>('[data-builder-toolbar="json"]')!.click();
        await settle();
        expect(root.querySelector('[data-builder-json-content]')!.textContent).toContain(
            '"contract_form"',
        );
    });

    it('selects the form settings', async () => {
        const { root, wire } = mount();
        await settle();
        root.querySelector<HTMLElement>('[data-builder-settings]')!.click();
        await settle();
        expect(wire.inspect).toHaveBeenCalledWith(
            'settings',
            null,
            expect.not.stringContaining('"pages"'),
            expect.any(String),
        );
        expect(root.querySelector('[data-builder-settings]')!.getAttribute('aria-pressed')).toBe(
            'true',
        );
    });
});
