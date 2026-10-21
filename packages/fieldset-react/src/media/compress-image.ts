/**
 * Client-side photo compression: decode, scale the long edge to ≤ 2048 px, re-encode as JPEG.
 * Re-encoding through a canvas drops all metadata (EXIF, including GPS).
 */

export interface CompressOptions {
    readonly maxEdge?: number;
    readonly quality?: number;
}

export interface CompressedImage {
    readonly blob: Blob;
    readonly fileName: string;
}

const DECODABLE = /^image\/(jpeg|png|webp|gif|bmp|heic|heif|avif)$/i;

type Source = ImageBitmap | HTMLImageElement;

function loadWithImage(file: Blob): Promise<HTMLImageElement | null> {
    return new Promise((resolve) => {
        const url = URL.createObjectURL(file);
        const image = new Image();
        image.onload = () => {
            URL.revokeObjectURL(url);
            resolve(image);
        };
        image.onerror = () => {
            URL.revokeObjectURL(url);
            resolve(null);
        };
        image.src = url;
    });
}

async function decode(file: Blob): Promise<Source | null> {
    if (typeof createImageBitmap === 'function') {
        try {
            return await createImageBitmap(file, { imageOrientation: 'from-image' });
        } catch {
            // Fall back to <img> decoding below.
        }
    }
    return typeof URL.createObjectURL === 'function' ? loadWithImage(file) : null;
}

function sizeOf(source: Source): { width: number; height: number } {
    return 'naturalWidth' in source
        ? { width: source.naturalWidth, height: source.naturalHeight }
        : { width: source.width, height: source.height };
}

function toBlob(canvas: HTMLCanvasElement, quality: number): Promise<Blob | null> {
    return new Promise((resolve) => {
        canvas.toBlob(resolve, 'image/jpeg', quality);
    });
}

function jpegName(name: string): string {
    const base = name.replace(/\.[^./\\]+$/, '');
    return `${base === '' ? 'photo' : base}.jpg`;
}

/**
 * Returns a JPEG re-encoding of the image, or the original file when it is not an image the
 * browser can decode (the host or server decides what to do with it).
 */
export async function compressImage(
    file: Blob,
    fileName: string,
    options: CompressOptions = {},
): Promise<CompressedImage> {
    const original = { blob: file, fileName };
    if (!DECODABLE.test(file.type)) {
        return original;
    }
    const source = await decode(file);
    if (source === null) {
        return original;
    }
    const { width, height } = sizeOf(source);
    const scale = Math.min(1, (options.maxEdge ?? 2048) / Math.max(width, height, 1));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(width * scale));
    canvas.height = Math.max(1, Math.round(height * scale));
    const context = canvas.getContext('2d');
    if (context === null) {
        return original;
    }
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(source, 0, 0, canvas.width, canvas.height);
    if ('close' in source) {
        source.close();
    }
    const blob = await toBlob(canvas, options.quality ?? 0.85);
    return blob === null ? original : { blob, fileName: jpegName(fileName) };
}
