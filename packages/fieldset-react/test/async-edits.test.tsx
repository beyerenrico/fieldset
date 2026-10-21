/** Asynchronous edits (uploads, signature, location): tracking, row shifts, onAsyncWork. */
import { act, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { shiftPath } from '../src/state/pending-edits.ts';
import type { AttachmentAdapter, AttachmentInfo, AttachmentUpload } from '../src/types.ts';
import { definition, el, page } from './fixtures/definitions.ts';
import { renderForm } from './helpers.tsx';

const media = vi.hoisted(() => ({
    stroke: (): void => undefined,
    compress: null as null | (() => Promise<void>),
}));

vi.mock('../src/media/compress-image.ts', () => ({
    compressImage: vi.fn(async (_file: Blob, fileName: string) => {
        await media.compress?.();
        return { blob: new Blob(['jpeg'], { type: 'image/jpeg' }), fileName };
    }),
}));

vi.mock('../src/media/signature-pad.ts', () => ({
    createSignaturePad: (_canvas: HTMLCanvasElement, onStroke: () => void) => {
        media.stroke = onStroke;
        return {
            clear: vi.fn(),
            isEmpty: () => false,
            toBlob: () => Promise.resolve(new Blob(['png'], { type: 'image/png' })),
            setDisabled: vi.fn(),
            destroy: vi.fn(),
        };
    },
}));

interface Deferred<T> {
    readonly promise: Promise<T>;
    readonly resolve: (value: T) => void;
    readonly reject: (error: unknown) => void;
}

function deferred<T>(): Deferred<T> {
    let resolve: (value: T) => void = () => undefined;
    let reject: (error: unknown) => void = () => undefined;
    const promise = new Promise<T>((res, rej) => {
        resolve = res;
        reject = rej;
    });
    return { promise, resolve, reject };
}

/** Adapter whose uploads resolve only when the test says so. */
function manualAdapter() {
    const calls: { request: AttachmentUpload; done: Deferred<AttachmentInfo> }[] = [];
    const adapter: AttachmentAdapter = {
        upload: vi.fn((request: AttachmentUpload) => {
            const done = deferred<AttachmentInfo>();
            calls.push({ request, done });
            return done.promise;
        }),
    };
    const finish = async (index: number, id: string): Promise<void> => {
        await act(async () => {
            calls[index]?.done.resolve({ id, fileName: calls[index].request.fileName });
            await Promise.resolve();
        });
    };
    return { adapter, calls, finish };
}

const rowsForm = definition([
    page('p', [
        el({
            type: 'repeater',
            key: 'rows',
            label: 'Rows',
            elements: [
                el({ type: 'text', key: 'title', label: 'Title' }),
                el({ type: 'file', key: 'doc', label: 'Doc' }),
            ],
        }),
    ]),
]);

const pdf = (name: string): File => new File(['%PDF'], name, { type: 'application/pdf' });

afterEach(() => {
    media.compress = null;
});

describe('async edits in repeater rows', () => {
    it('writes a late upload into its row after an earlier row was removed', async () => {
        const { adapter, calls, finish } = manualAdapter();
        const { user, lastData } = renderForm(
            rowsForm,
            { rows: [{ title: 'A' }, { title: 'B' }] },
            { attachments: adapter },
        );
        await user.upload(screen.getAllByLabelText('Doc')[1]!, pdf('b.pdf'));
        expect(calls).toHaveLength(1);
        expect(calls[0]?.request.pointer).toBe('/rows/1/doc');
        await user.click(screen.getByRole('button', { name: 'Remove entry 1' }));
        expect(lastData()['rows']).toEqual([{ title: 'B' }]);
        await finish(0, 'att-b');
        await waitFor(() => {
            expect(lastData()['rows']).toEqual([{ title: 'B', doc: 'att-b' }]);
        });
    });

    it('drops the result when its own row was removed and never pads rows', async () => {
        const { adapter, finish } = manualAdapter();
        const { user, lastData, onChange } = renderForm(
            rowsForm,
            { rows: [{ title: 'A' }, { title: 'B' }] },
            { attachments: adapter },
        );
        await user.upload(screen.getAllByLabelText('Doc')[1]!, pdf('b.pdf'));
        await user.click(screen.getByRole('button', { name: 'Remove entry 2' }));
        expect(lastData()['rows']).toEqual([{ title: 'A' }]);
        const calls = onChange.mock.calls.length;
        await finish(0, 'att-b');
        await act(async () => {
            await new Promise((resolve) => setTimeout(resolve, 0));
        });
        expect(onChange.mock.calls.length).toBe(calls);
        expect(lastData()['rows']).toEqual([{ title: 'A' }]);
    });

    it('keeps component state with its row (stable keys)', async () => {
        const { adapter } = manualAdapter();
        const { user } = renderForm(
            rowsForm,
            { rows: [{ title: 'A' }, { title: 'B' }] },
            { attachments: adapter },
        );
        await user.upload(screen.getAllByLabelText('Doc')[1]!, pdf('b.pdf'));
        const second = screen.getByRole('group', { name: 'Entry 2' });
        expect(within(second).getByRole('status')).toHaveTextContent('Uploading…');
        await user.click(screen.getByRole('button', { name: 'Remove entry 1' }));
        const remaining = screen.getByRole('group', { name: 'Entry 1' });
        expect(within(remaining).getByLabelText('Title')).toHaveValue('B');
        expect(within(remaining).getByRole('status')).toHaveTextContent('Uploading…');
    });

    it('shifts edits in nested repeaters by the removed outer row', async () => {
        const nested = definition([
            page('p', [
                el({
                    type: 'repeater',
                    key: 'outer',
                    label: 'Outer',
                    itemLabel: 'Outer',
                    elements: [
                        el({
                            type: 'repeater',
                            key: 'inner',
                            label: 'Inner',
                            itemLabel: 'Inner',
                            elements: [el({ type: 'file', key: 'doc', label: 'Doc' })],
                        }),
                    ],
                }),
            ]),
        ]);
        const { adapter, finish } = manualAdapter();
        const { user, lastData } = renderForm(
            nested,
            { outer: [{ inner: [{}] }, { inner: [{}, {}] }] },
            { attachments: adapter },
        );
        await user.upload(screen.getAllByLabelText('Doc')[2]!, pdf('x.pdf'));
        const outerRow = screen.getByRole('group', { name: 'Outer 1' });
        const removes = within(outerRow).getAllByRole('button', { name: 'Remove entry 1' });
        await user.click(removes.at(-1)!);
        expect(lastData()['outer']).toEqual([{ inner: [{}, {}] }]);
        await finish(0, 'att-x');
        await waitFor(() => {
            expect(lastData()['outer']).toEqual([{ inner: [{}, { doc: 'att-x' }] }]);
        });
    });
});

describe('onAsyncWork', () => {
    const form = definition([
        page('p', [
            el({ type: 'photo', key: 'pic', label: 'Picture' }),
            el({ type: 'text', key: 'customer', label: 'Customer' }),
            el({ type: 'signature', key: 'sig', label: 'Signature', signerNameFrom: 'customer' }),
            el({ type: 'location', key: 'loc', label: 'Location' }),
        ]),
    ]);

    function tracked() {
        const order: string[] = [];
        const works: Promise<void>[] = [];
        const onAsyncWork = vi.fn((work: Promise<void>) => {
            works.push(work);
            void work.then(() => order.push('done'));
        });
        return { order, works, onAsyncWork };
    }

    it('tracks a photo from compression until its value was written', async () => {
        const compress = deferred<undefined>();
        media.compress = () => compress.promise;
        const { adapter, calls, finish } = manualAdapter();
        const { order, onAsyncWork } = tracked();
        const { user, onChange } = renderForm(form, {}, { attachments: adapter, onAsyncWork });
        onChange.mockImplementation((data) => {
            if (data['pic'] !== undefined) {
                order.push('written');
            }
        });
        await user.upload(
            screen.getByLabelText('Picture'),
            new File(['p'], 'p.png', { type: 'image/png' }),
        );
        expect(onAsyncWork).toHaveBeenCalledOnce();
        expect(calls).toHaveLength(0);
        await act(async () => {
            compress.resolve(undefined);
            await Promise.resolve();
        });
        await waitFor(() => {
            expect(calls).toHaveLength(1);
        });
        expect(order).toEqual([]);
        await finish(0, 'pic-1');
        await waitFor(() => {
            expect(order).toEqual(['written', 'done']);
        });
    });

    it('resolves the work when the upload fails', async () => {
        const { adapter, calls } = manualAdapter();
        const { works, onAsyncWork } = tracked();
        const { user } = renderForm(form, {}, { attachments: adapter, onAsyncWork });
        await user.upload(
            screen.getByLabelText('Picture'),
            new File(['p'], 'p.png', { type: 'image/png' }),
        );
        await waitFor(() => {
            expect(calls).toHaveLength(1);
        });
        await act(async () => {
            calls[0]?.done.reject(new Error('nope'));
            await Promise.resolve();
        });
        await expect(works[0]).resolves.toBeUndefined();
        expect(await screen.findByRole('alert')).toHaveTextContent('nope');
    });

    it('tracks a signature until it was written', async () => {
        const { adapter, finish } = manualAdapter();
        const { order, onAsyncWork } = tracked();
        const { user, onChange } = renderForm(
            form,
            { customer: 'Max' },
            { attachments: adapter, onAsyncWork },
        );
        onChange.mockImplementation((data) => {
            if (data['sig'] !== undefined) {
                order.push('written');
            }
        });
        act(() => {
            media.stroke();
        });
        await user.click(screen.getByRole('button', { name: 'Confirm signature' }));
        await waitFor(() => {
            expect(onAsyncWork).toHaveBeenCalledOnce();
        });
        expect(order).toEqual([]);
        await finish(0, 'sig-1');
        await waitFor(() => {
            expect(order).toEqual(['written', 'done']);
        });
    });

    describe('location', () => {
        const original = Object.getOwnPropertyDescriptor(navigator, 'geolocation');
        let answer: Deferred<'ok' | 'fail'>;

        beforeEach(() => {
            answer = deferred();
            Object.defineProperty(navigator, 'geolocation', {
                configurable: true,
                value: {
                    getCurrentPosition: (
                        success: PositionCallback,
                        failure: PositionErrorCallback,
                    ) => {
                        void answer.promise.then((kind) => {
                            if (kind === 'ok') {
                                success({
                                    coords: { latitude: 1, longitude: 2, accuracy: 3 },
                                    timestamp: Date.UTC(2026, 9, 16),
                                } as GeolocationPosition);
                            } else {
                                failure({
                                    code: 1,
                                    PERMISSION_DENIED: 1,
                                    POSITION_UNAVAILABLE: 2,
                                    TIMEOUT: 3,
                                    message: '',
                                });
                            }
                        });
                    },
                },
            });
        });
        afterEach(() => {
            if (original === undefined) {
                Reflect.deleteProperty(navigator, 'geolocation');
            } else {
                Object.defineProperty(navigator, 'geolocation', original);
            }
        });

        it('tracks a capture until it was written', async () => {
            const { order, onAsyncWork } = tracked();
            const { user, onChange } = renderForm(form, {}, { onAsyncWork });
            onChange.mockImplementation((data) => {
                if (data['loc'] !== undefined) {
                    order.push('written');
                }
            });
            await user.click(screen.getByRole('button', { name: 'Capture location' }));
            expect(onAsyncWork).toHaveBeenCalledOnce();
            await act(async () => {
                answer.resolve('ok');
                await Promise.resolve();
            });
            await waitFor(() => {
                expect(order).toEqual(['written', 'done']);
            });
        });

        it('resolves the work when locating fails', async () => {
            const { works, onAsyncWork } = tracked();
            const { user } = renderForm(form, {}, { onAsyncWork });
            await user.click(screen.getByRole('button', { name: 'Capture location' }));
            await act(async () => {
                answer.resolve('fail');
                await Promise.resolve();
            });
            await expect(works[0]).resolves.toBeUndefined();
            expect(screen.getByRole('alert')).toHaveTextContent('Location access was denied.');
        });
    });
});

describe('async edits in the wizard', () => {
    it('writes an upload whose page was left meanwhile', async () => {
        const wizard = definition(
            [
                page('one', [el({ type: 'file', key: 'doc', label: 'Doc' })]),
                page('two', [el({ type: 'text', key: 'note', label: 'Note' })]),
            ],
            { settings: { layout: 'wizard' } },
        );
        const { adapter, finish } = manualAdapter();
        const { user, lastData } = renderForm(wizard, {}, { attachments: adapter });
        await user.upload(screen.getByLabelText('Doc'), pdf('a.pdf'));
        await user.click(screen.getByRole('button', { name: 'Next' }));
        expect(screen.getByLabelText('Note')).toBeInTheDocument();
        expect(screen.queryByLabelText('Doc')).not.toBeInTheDocument();
        await finish(0, 'att-a');
        await waitFor(() => {
            expect(lastData()['doc']).toBe('att-a');
        });
    });
});

describe('shiftPath', () => {
    it('moves later rows up, drops the removed row and leaves everything else alone', () => {
        expect(shiftPath(['rows', 3, 'doc'], ['rows'], 1)).toEqual(['rows', 2, 'doc']);
        expect(shiftPath(['rows', 1, 'doc'], ['rows'], 1)).toBeNull();
        expect(shiftPath(['rows', 0, 'doc'], ['rows'], 1)).toEqual(['rows', 0, 'doc']);
        expect(shiftPath(['other', 3, 'doc'], ['rows'], 1)).toEqual(['other', 3, 'doc']);
        expect(shiftPath(['rows'], ['rows'], 0)).toEqual(['rows']);
        expect(shiftPath(['rows', 'x'], ['rows'], 0)).toEqual(['rows', 'x']);
        expect(shiftPath(null, ['rows'], 0)).toBeNull();
    });
});
