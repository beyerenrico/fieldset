/** Thin wrapper around signature_pad (mockable in tests). */
import SignaturePad from 'signature_pad';

export interface SignaturePadHandle {
    clear(): void;
    isEmpty(): boolean;
    /** PNG of the drawing. */
    toBlob(): Promise<Blob | null>;
    setDisabled(disabled: boolean): void;
    destroy(): void;
}

function fitToDevicePixels(canvas: HTMLCanvasElement): void {
    const ratio = Math.max(window.devicePixelRatio || 1, 1);
    const width = canvas.offsetWidth || 600;
    const height = canvas.offsetHeight || 200;
    canvas.width = width * ratio;
    canvas.height = height * ratio;
    canvas.getContext('2d')?.scale(ratio, ratio);
}

const INERT: SignaturePadHandle = {
    clear: () => undefined,
    isEmpty: () => true,
    toBlob: () => Promise.resolve(null),
    setDisabled: () => undefined,
    destroy: () => undefined,
};

/** Creates a pad on the canvas; `onStroke` fires after every finished stroke. */
export function createSignaturePad(
    canvas: HTMLCanvasElement,
    onStroke: () => void,
): SignaturePadHandle {
    if (canvas.getContext('2d') === null) {
        return INERT;
    }
    fitToDevicePixels(canvas);
    const pad = new SignaturePad(canvas, {
        backgroundColor: 'rgb(255, 255, 255)',
        penColor: 'rgb(0, 0, 0)',
    });
    pad.addEventListener('endStroke', onStroke);
    return {
        clear: () => {
            pad.clear();
        },
        isEmpty: () => pad.isEmpty(),
        toBlob: () =>
            new Promise((resolve) => {
                canvas.toBlob(resolve, 'image/png');
            }),
        setDisabled: (disabled) => {
            if (disabled) {
                pad.off();
            } else {
                pad.on();
            }
        },
        destroy: () => {
            pad.removeEventListener('endStroke', onStroke);
            pad.off();
        },
    };
}
