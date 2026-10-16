import { act, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { AttachmentAdapter } from '../src/types.ts';
import { definition, el, page } from './fixtures/definitions.ts';
import { renderForm } from './helpers.tsx';

const pad = vi.hoisted(() => ({
    stroke: (): void => undefined,
    release: (): void => undefined,
}));

vi.mock('../src/media/signature-pad.ts', () => ({
    createSignaturePad: (_canvas: HTMLCanvasElement, onStroke: () => void) => {
        pad.stroke = onStroke;
        return {
            clear: vi.fn(),
            isEmpty: () => false,
            // Slow encoder: the PNG is ready only when the test releases it.
            toBlob: () =>
                new Promise<Blob>((resolve) => {
                    const previous = pad.release;
                    pad.release = () => {
                        previous();
                        resolve(new Blob(['png'], { type: 'image/png' }));
                    };
                }),
            setDisabled: vi.fn(),
            destroy: vi.fn(),
        };
    },
}));

const form = definition([page('p', [el({ type: 'signature', key: 'sig', label: 'Signature' })])]);

describe('signature capture', () => {
    it('uploads only once when confirm is tapped twice quickly', async () => {
        const upload = vi.fn<AttachmentAdapter['upload']>(() =>
            Promise.resolve({ id: 'sig-1', url: 'blob:sig' }),
        );
        const { user, lastData } = renderForm(form, {}, { attachments: { upload } });
        await user.type(screen.getByLabelText('Name of the signer'), 'Max');
        act(() => {
            pad.stroke();
        });
        const confirm = screen.getByRole('button', { name: 'Confirm signature' });
        await user.dblClick(confirm);
        expect(confirm).toBeDisabled();
        await act(async () => {
            pad.release();
            await Promise.resolve();
        });
        await waitFor(() => {
            expect(lastData()['sig']).toMatchObject({ attachment: 'sig-1', signerName: 'Max' });
        });
        expect(upload).toHaveBeenCalledOnce();
    });
});
