import { act, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest';
import type { AttachmentAdapter, AttachmentUpload } from '../src/types.ts';
import { definition, el, page } from './fixtures/definitions.ts';
import { renderForm } from './helpers.tsx';

const pad = vi.hoisted(() => ({ stroke: (): void => undefined, empty: true }));

vi.mock('../src/media/compress-image.ts', () => ({
    compressImage: vi.fn((_file: Blob, fileName: string) =>
        Promise.resolve({
            blob: new Blob(['jpeg'], { type: 'image/jpeg' }),
            fileName: fileName.replace(/\.\w+$/, '.jpg'),
        }),
    ),
}));

vi.mock('../src/media/signature-pad.ts', () => ({
    createSignaturePad: (_canvas: HTMLCanvasElement, onStroke: () => void) => {
        pad.stroke = onStroke;
        return {
            clear: vi.fn(),
            isEmpty: () => pad.empty,
            toBlob: () => Promise.resolve(new Blob(['png'], { type: 'image/png' })),
            setDisabled: vi.fn(),
            destroy: vi.fn(),
        };
    },
}));

type TestAdapter = Omit<AttachmentAdapter, 'remove'> & {
    uploads: AttachmentUpload[];
    remove: Mock<NonNullable<AttachmentAdapter['remove']>>;
};

function adapter(): TestAdapter {
    const uploads: AttachmentUpload[] = [];
    let next = 0;
    return {
        uploads,
        upload: vi.fn((request: AttachmentUpload) => {
            uploads.push(request);
            next += 1;
            return Promise.resolve({
                id: `id-${String(next)}`,
                fileName: request.fileName,
                url: 'blob:thumb',
                mime: request.file.type,
            });
        }),
        remove: vi.fn<NonNullable<AttachmentAdapter['remove']>>(),
    };
}

const mediaForm = definition([
    page('p', [
        el({
            type: 'photo',
            key: 'photos',
            label: 'Photos',
            multiple: true,
            validation: { maxFiles: 2 },
        }),
        el({
            type: 'file',
            key: 'doc',
            label: 'Document',
            validation: { accept: ['application/pdf'] },
        }),
        el({ type: 'text', key: 'customer', label: 'Customer' }),
        el({ type: 'signature', key: 'sig', label: 'Signature', signerNameFrom: 'customer' }),
        el({ type: 'location', key: 'loc', label: 'Location' }),
    ]),
]);

describe('photo and file', () => {
    it('compresses photos and uploads them through the adapter', async () => {
        const files = adapter();
        const { user, lastData } = renderForm(mediaForm, {}, { attachments: files });
        const input = screen.getByLabelText('Photos');
        expect(input).toHaveAttribute('capture', 'environment');
        expect(input).toHaveAttribute('accept', 'image/*');
        await user.upload(input, [
            new File(['a'], 'a.png', { type: 'image/png' }),
            new File(['b'], 'b.png', { type: 'image/png' }),
        ]);
        await waitFor(() => {
            expect(lastData()['photos']).toEqual(['id-1', 'id-2']);
        });
        expect(files.uploads[0]).toMatchObject({
            kind: 'photo',
            elementKey: 'photos',
            pointer: '/photos',
            fileName: 'a.jpg',
        });
        expect(files.uploads[0]?.file.type).toBe('image/jpeg');
        expect(screen.getAllByRole('img', { name: /a\.jpg|b\.jpg/ })).toHaveLength(2);
        expect(screen.queryByLabelText('Photos', { selector: 'input' })).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Remove a.jpg' }));
        expect(lastData()['photos']).toEqual(['id-2']);
        expect(files.remove).toHaveBeenCalledWith(
            'id-1',
            expect.objectContaining({ kind: 'photo' }),
        );
    });

    it('uploads files unchanged and replaces a single file', async () => {
        const files = adapter();
        const { user, lastData } = renderForm(mediaForm, {}, { attachments: files });
        const input = screen.getByLabelText('Document');
        expect(input).toHaveAttribute('accept', 'application/pdf');
        expect(input).not.toHaveAttribute('capture');
        await user.upload(input, new File(['%PDF'], 'x.pdf', { type: 'application/pdf' }));
        await waitFor(() => {
            expect(lastData()['doc']).toBe('id-1');
        });
        expect(files.uploads[0]?.fileName).toBe('x.pdf');
        expect(screen.getByText('x.pdf')).toBeInTheDocument();
    });

    it('shows the adapter rejection message', async () => {
        const files = adapter();
        files.upload = vi.fn(() => Promise.reject(new Error('File type not allowed')));
        const { user } = renderForm(mediaForm, {}, { attachments: files });
        await user.upload(
            screen.getByLabelText('Document'),
            new File(['x'], 'x.pdf', { type: 'application/pdf' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent('File type not allowed');
    });

    it('falls back to the generic upload error', async () => {
        const files = adapter();
        files.upload = vi.fn(() => Promise.reject(new Error('')));
        const { user } = renderForm(mediaForm, {}, { attachments: files, uiLocale: 'en' });
        await user.upload(
            screen.getByLabelText('Document'),
            new File(['x'], 'y.pdf', { type: 'application/pdf' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'The upload failed. Please try again.',
        );
    });
});

describe('signature', () => {
    it('captures name, drawing and timestamp, then uploads a PNG', async () => {
        const files = adapter();
        const { user, lastData } = renderForm(
            mediaForm,
            { customer: 'Max Muster' },
            { attachments: files },
        );
        const name = screen.getByLabelText('Name of the signer');
        expect(name).toHaveValue('Max Muster');
        const confirm = screen.getByRole('button', { name: 'Confirm signature' });
        expect(confirm).toBeDisabled();
        act(() => {
            pad.stroke();
        });
        expect(confirm).toBeEnabled();
        await user.click(confirm);
        await waitFor(() => {
            expect(lastData()['sig']).toMatchObject({
                attachment: 'id-1',
                signerName: 'Max Muster',
            });
        });
        const value = lastData()['sig'] as { signedAt: string };
        expect(value.signedAt).toMatch(/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/);
        expect(files.uploads[0]).toMatchObject({ kind: 'signature', fileName: 'signature.png' });
        expect(files.uploads[0]?.file.type).toBe('image/png');
        expect(screen.getByText(/Signed by Max Muster on/)).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Sign again' }));
        await user.click(screen.getByRole('button', { name: 'Clear' }));
        expect(screen.getByRole('button', { name: 'Confirm signature' })).toBeDisabled();
    });
});

describe('signature in a repeater row', () => {
    it('prefills the signer name from an outer scope', () => {
        const form = definition([
            page('p', [
                el({ type: 'text', key: 'boss', label: 'Boss' }),
                el({
                    type: 'repeater',
                    key: 'rows',
                    label: 'Rows',
                    elements: [
                        el({
                            type: 'signature',
                            key: 'sig',
                            label: 'Row signature',
                            signerNameFrom: 'boss',
                        }),
                    ],
                }),
            ]),
        ]);
        renderForm(form, { boss: 'Erika', rows: [{}] });
        expect(screen.getByLabelText('Name of the signer')).toHaveValue('Erika');
    });
});

describe('location', () => {
    const original = Object.getOwnPropertyDescriptor(navigator, 'geolocation');
    let getCurrentPosition: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        getCurrentPosition = vi.fn();
        Object.defineProperty(navigator, 'geolocation', {
            configurable: true,
            value: { getCurrentPosition },
        });
    });
    afterEach(() => {
        if (original === undefined) {
            Reflect.deleteProperty(navigator, 'geolocation');
        } else {
            Object.defineProperty(navigator, 'geolocation', original);
        }
    });

    it('captures only after the button is pressed', async () => {
        getCurrentPosition.mockImplementation((success: PositionCallback) => {
            success({
                coords: { latitude: 52.52, longitude: 13.405, accuracy: 12.4 },
                timestamp: Date.UTC(2026, 9, 16, 7, 41),
            } as GeolocationPosition);
        });
        const { user, lastData } = renderForm(mediaForm);
        expect(getCurrentPosition).not.toHaveBeenCalled();
        await user.click(screen.getByRole('button', { name: 'Capture location' }));
        expect(lastData()['loc']).toEqual({
            lat: 52.52,
            lng: 13.405,
            accuracy: 12.4,
            capturedAt: '2026-10-16T09:41:00+02:00',
        });
        expect(screen.getByText('52.52, 13.405 (±12 m)')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Remove location' }));
        expect(lastData()['loc']).toBeNull();
    });

    it.each([
        [1, 'Location access was denied.'],
        [2, 'The location is not available.'],
        [3, 'Locating took too long. Please try again.'],
    ])('reports error code %i', async (code, message) => {
        getCurrentPosition.mockImplementation(
            (_success: PositionCallback, failure: PositionErrorCallback) => {
                failure({
                    code,
                    PERMISSION_DENIED: 1,
                    POSITION_UNAVAILABLE: 2,
                    TIMEOUT: 3,
                    message: '',
                });
            },
        );
        const { user } = renderForm(mediaForm);
        await user.click(screen.getByRole('button', { name: 'Capture location' }));
        expect(screen.getByRole('alert')).toHaveTextContent(message);
    });

    it('reports unavailable without the geolocation API', async () => {
        Reflect.deleteProperty(navigator, 'geolocation');
        const { user } = renderForm(mediaForm);
        await user.click(screen.getByRole('button', { name: 'Capture location' }));
        expect(screen.getByRole('alert')).toHaveTextContent('The location is not available.');
    });
});
