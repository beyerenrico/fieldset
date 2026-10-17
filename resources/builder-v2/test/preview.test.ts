import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import * as protocol from '../../field-app/src/preview/protocol.ts';
import {
    PREVIEW_INTERVAL_MS,
    PREVIEW_MESSAGE,
    PREVIEW_READY,
    PreviewFeed,
} from '../src/preview.ts';

let frame: HTMLIFrameElement;
let target: { postMessage: ReturnType<typeof vi.fn> };
let definition: Record<string, unknown>;
let feed: PreviewFeed;

function ready(source: unknown, origin = 'https://app.test'): void {
    window.dispatchEvent(
        new MessageEvent('message', {
            data: { type: PREVIEW_READY },
            origin,
            source: source as Window,
        }),
    );
}

beforeEach(() => {
    vi.useFakeTimers();
    document.body.innerHTML = '<iframe data-builder-preview></iframe>';
    frame = document.querySelector('iframe')!;
    target = { postMessage: vi.fn() };
    Object.defineProperty(frame, 'contentWindow', { value: target });
    definition = { pages: [], locale: 'de-DE' };
    feed = new PreviewFeed(frame, {
        getDefinition: () => definition as never,
        origin: 'https://app.test',
    });
});

afterEach(() => {
    feed.destroy();
    vi.useRealTimers();
});

describe('preview feed', () => {
    it('mirrors the Field App protocol constants', () => {
        expect(PREVIEW_MESSAGE).toBe(protocol.PREVIEW_MESSAGE);
        expect(PREVIEW_READY).toBe(protocol.PREVIEW_READY);
    });

    it('sends once at start and again after a trusted ready message', () => {
        expect(target.postMessage).toHaveBeenCalledWith(
            { type: PREVIEW_MESSAGE, definition },
            'https://app.test',
        );
        ready(target);
        expect(feed.ready).toBe(true);
        expect(target.postMessage).toHaveBeenCalledTimes(2);
    });

    it('ignores ready messages from other windows or origins', () => {
        ready(window);
        ready(target, 'https://evil.test');
        ready(null);
        expect(feed.ready).toBe(false);
        expect(target.postMessage).toHaveBeenCalledTimes(1);
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
            'https://app.test',
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
});
