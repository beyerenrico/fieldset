import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const padInstances = vi.hoisted(
    () => [] as { listeners: Map<string, () => void>; cleared: number; enabled: number }[],
);

vi.mock('signature_pad', () => ({
    default: class {
        listeners = new Map<string, () => void>();
        cleared = 0;
        enabled = 0;
        constructor() {
            padInstances.push(this);
        }
        addEventListener(name: string, fn: () => void): void {
            this.listeners.set(name, fn);
        }
        removeEventListener(name: string): void {
            this.listeners.delete(name);
        }
        clear(): void {
            this.cleared += 1;
        }
        isEmpty(): boolean {
            return this.cleared > 0;
        }
        on(): void {
            this.enabled += 1;
        }
        off(): void {
            this.enabled -= 0;
        }
    },
}));

const { compressImage } = await import('../src/media/compress-image.ts');
const { createSignaturePad } = await import('../src/media/signature-pad.ts');
const { createRevealRegistry } = await import('../src/state/reveal.ts');

function stubCanvas(blob: Blob | null): { drawImage: ReturnType<typeof vi.fn> } {
    const context = { drawImage: vi.fn(), fillRect: vi.fn(), scale: vi.fn(), fillStyle: '' };
    vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(context as never);
    vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation(function (
        callback: BlobCallback,
    ) {
        callback(blob);
    });
    return context;
}

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('compressImage', () => {
    it('keeps non-images unchanged', async () => {
        const file = new Blob(['%PDF'], { type: 'application/pdf' });
        expect(await compressImage(file, 'a.pdf')).toEqual({ blob: file, fileName: 'a.pdf' });
    });

    it('scales the long edge to 2048 px and re-encodes as JPEG', async () => {
        const jpeg = new Blob(['jpeg'], { type: 'image/jpeg' });
        const context = stubCanvas(jpeg);
        const close = vi.fn();
        vi.stubGlobal(
            'createImageBitmap',
            vi.fn(() => Promise.resolve({ width: 4096, height: 1024, close })),
        );
        const result = await compressImage(new Blob(['png'], { type: 'image/png' }), 'shot.png');
        expect(result).toEqual({ blob: jpeg, fileName: 'shot.jpg' });
        expect(context.drawImage).toHaveBeenCalledWith(expect.anything(), 0, 0, 2048, 512);
        expect(close).toHaveBeenCalled();
    });

    const revoke = vi.fn();

    function stubImage(ok: boolean): void {
        vi.stubGlobal(
            'Image',
            class {
                naturalWidth = 300;
                naturalHeight = 200;
                onload: (() => void) | null = null;
                onerror: (() => void) | null = null;
                set src(_value: string) {
                    queueMicrotask(() => {
                        (ok ? this.onload : this.onerror)?.();
                    });
                }
            },
        );
        URL.createObjectURL = vi.fn(() => 'blob:x');
        URL.revokeObjectURL = revoke;
    }

    it('falls back to <img> decoding', async () => {
        const jpeg = new Blob(['jpeg'], { type: 'image/jpeg' });
        const context = stubCanvas(jpeg);
        vi.stubGlobal(
            'createImageBitmap',
            vi.fn(() => Promise.reject(new Error('unsupported'))),
        );
        stubImage(true);
        const result = await compressImage(new Blob(['webp'], { type: 'image/webp' }), 'a.webp');
        expect(result).toEqual({ blob: jpeg, fileName: 'a.jpg' });
        expect(context.drawImage).toHaveBeenCalledWith(expect.anything(), 0, 0, 300, 200);
        expect(revoke).toHaveBeenCalledWith('blob:x');
    });

    it('keeps the original when nothing decodes', async () => {
        stubCanvas(null);
        vi.stubGlobal('createImageBitmap', undefined);
        stubImage(false);
        const original = new Blob(['heic'], { type: 'image/heic' });
        expect((await compressImage(original, 'a.heic')).blob).toBe(original);
    });

    it('returns the original when the canvas cannot encode', async () => {
        stubCanvas(null);
        vi.stubGlobal(
            'createImageBitmap',
            vi.fn(() => Promise.resolve({ width: 10, height: 10, close: vi.fn() })),
        );
        const original = new Blob(['jpg'], { type: 'image/jpeg' });
        expect((await compressImage(original, 'a.jpg')).blob).toBe(original);
    });
});

describe('createSignaturePad', () => {
    beforeEach(() => {
        padInstances.length = 0;
    });

    it('wraps signature_pad', async () => {
        stubCanvas(new Blob(['png'], { type: 'image/png' }));
        const onStroke = vi.fn();
        const handle = createSignaturePad(document.createElement('canvas'), onStroke);
        const instance = padInstances[0]!;
        instance.listeners.get('endStroke')?.();
        expect(onStroke).toHaveBeenCalled();
        handle.clear();
        expect(handle.isEmpty()).toBe(true);
        handle.setDisabled(true);
        handle.setDisabled(false);
        expect(instance.enabled).toBe(1);
        expect((await handle.toBlob())?.type).toBe('image/png');
        handle.destroy();
        expect(instance.listeners.size).toBe(0);
    });

    it('returns an inert pad without a 2D context', async () => {
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(null);
        const handle = createSignaturePad(document.createElement('canvas'), vi.fn());
        expect(handle.isEmpty()).toBe(true);
        expect(await handle.toBlob()).toBeNull();
        handle.clear();
        handle.setDisabled(true);
        handle.destroy();
        expect(padInstances).toHaveLength(0);
    });
});

describe('reveal registry', () => {
    it('focuses after listeners requested a render', async () => {
        const registry = createRevealRegistry();
        const input = document.createElement('input');
        input.id = 'later';
        const unregister = registry.register(() => {
            setTimeout(() => {
                document.body.append(input);
            }, 5);
            return true;
        });
        registry.reveal('/x', 'later');
        await vi.waitFor(() => {
            expect(document.activeElement).toBe(input);
        });
        unregister();
        const field = document.createElement('div');
        field.id = 'grp-field';
        const button = document.createElement('button');
        field.append(button);
        document.body.append(field);
        registry.reveal('/y', 'grp');
        expect(document.activeElement).toBe(button);
        // A missing target retries a few frames, then gives up; run that out here so no timer outlives the file.
        vi.useFakeTimers();
        try {
            registry.reveal('/z', 'never-there');
            vi.runAllTimers();
            expect(vi.getTimerCount()).toBe(0);
            expect(document.activeElement).toBe(button);
        } finally {
            vi.useRealTimers();
        }
        input.remove();
        field.remove();
    });
});
