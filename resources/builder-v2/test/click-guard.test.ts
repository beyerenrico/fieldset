import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CLICK_GUARD_MS, DragClickGuard } from '../src/canvas/click-guard.ts';

let guard: DragClickGuard;

beforeEach(() => {
    vi.useFakeTimers();
    guard = new DragClickGuard(document);
});

afterEach(() => {
    guard.destroy();
    vi.useRealTimers();
});

describe('drag click guard', () => {
    it('swallows only the click that ends a drag', () => {
        expect(guard.consume()).toBe(false);
        guard.arm();
        expect(guard.consume()).toBe(true);
        expect(guard.consume()).toBe(false);
    });

    it('lets a deliberate click through right after a drop (it starts with a press)', () => {
        guard.arm();
        document.dispatchEvent(new Event('pointerdown'));
        expect(guard.consume()).toBe(false);
        guard.arm();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
        expect(guard.consume()).toBe(false);
    });

    it('disarms by itself when no click followed the drag', () => {
        guard.arm();
        vi.advanceTimersByTime(CLICK_GUARD_MS);
        expect(guard.consume()).toBe(false);
    });

    it('stops listening when destroyed', () => {
        guard.destroy();
        guard.arm();
        document.dispatchEvent(new Event('pointerdown'));
        expect(guard.consume()).toBe(true);
    });
});
