import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import * as protocol from '../../field-app/src/preview/protocol.ts';
import {
    PREVIEW_INTERVAL_MS,
    PREVIEW_MESSAGE,
    PREVIEW_READY,
    PREVIEW_SENDER_ORIGIN,
    PREVIEW_TARGET_ORIGIN,
    PreviewFeed,
} from '../src/preview.ts';

let frame: HTMLIFrameElement;
let target: { postMessage: ReturnType<typeof vi.fn> };
let definition: Record<string, unknown>;
let feed: PreviewFeed;

/** The sandboxed preview (opaque origin) posts its ready message with origin 'null'. */
function ready(source: unknown, origin = 'null', data: unknown = { type: PREVIEW_READY }): void {
    window.dispatchEvent(new MessageEvent('message', { data, origin, source: source as Window }));
}

beforeEach(() => {
    vi.useFakeTimers();
    document.body.innerHTML = '<iframe data-builder-preview></iframe>';
    frame = document.querySelector('iframe')!;
    target = { postMessage: vi.fn() };
    Object.defineProperty(frame, 'contentWindow', { value: target });
    definition = { pages: [], locale: 'de-DE' };
    feed = new PreviewFeed(frame, { getDefinition: () => definition as never });
});

afterEach(() => {
    feed.destroy();
    vi.useRealTimers();
});

describe('preview feed', () => {
    it('mirrors the Field App protocol constants', () => {
        expect(PREVIEW_MESSAGE).toBe(protocol.PREVIEW_MESSAGE);
        expect(PREVIEW_READY).toBe(protocol.PREVIEW_READY);
        expect(PREVIEW_TARGET_ORIGIN).toBe(protocol.PREVIEW_TARGET_ORIGIN);
        expect(PREVIEW_SENDER_ORIGIN).toBe(protocol.PREVIEW_SENDER_ORIGIN);
    });

    it('sends once at start and again after a trusted ready message', () => {
        // The preview's origin is opaque: no origin string names it, the iframe window pins delivery.
        expect(target.postMessage).toHaveBeenCalledWith({ type: PREVIEW_MESSAGE, definition }, '*');
        ready(target);
        expect(feed.ready).toBe(true);
        expect(target.postMessage).toHaveBeenCalledTimes(2);
    });

    it('ignores ready messages from other windows or origins', () => {
        ready(window);
        ready(target, 'https://evil.test');
        ready(null);
        ready(target, 'null', { type: 'something-else' });
        expect(feed.ready).toBe(false);
        expect(target.postMessage).toHaveBeenCalledTimes(1);
    });

    it('refuses a ready message from a same-origin (unsandboxed) preview', () => {
        ready(target, window.location.origin);
        expect(feed.ready).toBe(false);
        expect(target.postMessage).toHaveBeenCalledTimes(1);
    });

    it('trusts exactly the ready messages the shared protocol accepts', () => {
        const cases: [unknown, string, unknown][] = [
            [target, 'null', { type: PREVIEW_READY }],
            [target, window.location.origin, { type: PREVIEW_READY }],
            [window, 'null', { type: PREVIEW_READY }],
            [target, 'null', { type: PREVIEW_MESSAGE }],
            [target, 'null', 'fieldset-preview-ready'],
        ];
        for (const [source, origin, data] of cases) {
            const local = new PreviewFeed(frame, { getDefinition: () => definition as never });
            ready(source, origin, data);
            const expected = protocol.isPreviewReady(
                { source: source as Window, origin, data },
                target as unknown as Window,
            );
            expect(local.ready).toBe(expected);
            local.destroy();
        }
    });

    it('throttles updates to one per 250 ms with a trailing send', () => {
        vi.advanceTimersByTime(PREVIEW_INTERVAL_MS);
        definition = { ...definition, n: 1 };
        feed.notify();
        expect(target.postMessage).toHaveBeenCalledTimes(2);
        definition = { ...definition, n: 2 };
        feed.notify();
        definition = { ...definition, n: 3 };
        feed.notify();
        expect(target.postMessage).toHaveBeenCalledTimes(2);
        vi.advanceTimersByTime(PREVIEW_INTERVAL_MS);
        expect(target.postMessage).toHaveBeenCalledTimes(3);
        expect(target.postMessage).toHaveBeenLastCalledWith(
            { type: PREVIEW_MESSAGE, definition: { pages: [], locale: 'de-DE', n: 3 } },
            '*',
        );
        feed.notify();
        vi.advanceTimersByTime(PREVIEW_INTERVAL_MS);
        expect(target.postMessage).toHaveBeenCalledTimes(3);
    });

    it('resends after the iframe reloads and changes its size attribute only', () => {
        vi.advanceTimersByTime(PREVIEW_INTERVAL_MS);
        frame.dispatchEvent(new Event('load'));
        expect(target.postMessage).toHaveBeenCalledTimes(2);
        feed.setSize('tablet');
        expect(frame.dataset.size).toBe('tablet');
        expect(document.querySelectorAll('iframe')).toHaveLength(1);
    });

    it('marks the iframe while focus is inside it, where CSS :focus never matches (WCAG 2.4.7)', () => {
        const button = document.createElement('button');
        document.body.append(button);
        // Tab into the sandboxed preview: the builder's window blurs and the iframe is the active element.
        frame.tabIndex = 0;
        frame.focus();
        window.dispatchEvent(new Event('blur'));
        vi.advanceTimersByTime(0);
        expect(frame.hasAttribute('data-builder-preview-focused')).toBe(true);

        button.focus(); // focus comes back to the builder
        expect(frame.hasAttribute('data-builder-preview-focused')).toBe(false);

        feed.destroy();
        frame.focus();
        window.dispatchEvent(new Event('blur'));
        vi.advanceTimersByTime(0);
        expect(frame.hasAttribute('data-builder-preview-focused')).toBe(false);
    });
});
