import { screen, waitFor } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import * as endpoints from '../../src/lib/api/endpoints.ts';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { createBackend } from '../utils/backend.ts';
import { makeSubmission } from '../utils/fixtures.ts';
import { renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';

vi.mock('@form-schema-app/form-schema-react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@form-schema-app/form-schema-react')>();
    const { FakeRenderer } = await import('../utils/fake-renderer.tsx');
    return { ...actual, FormRenderer: FakeRenderer };
});

setupTestServer();

let counter = 0;
const createObjectURL = vi.fn(() => `blob:preview-${String(++counter)}`);
const revokeObjectURL = vi.fn();
const UUID = '0192f1c4-0000-7000-8000-0000000000dd';
const PHOTO = {
    uuid: '0192f1c4-0000-7000-8000-0000000000ee',
    kind: 'photo' as const,
    element_key: 'photos',
    mime: 'image/jpeg',
    size: 3,
    url: `/api/v1/submissions/${UUID}/attachments/x`,
};

beforeEach(() => {
    counter = 0;
    Object.assign(URL, { createObjectURL, revokeObjectURL });
    setOrganizationId(1);
});
afterEach(() => {
    createObjectURL.mockClear();
    revokeObjectURL.mockClear();
});

function setup(status: 'draft' | 'submitted') {
    const backend = createBackend();
    backend.state.submissions.set(
        UUID,
        makeSubmission({
            uuid: UUID,
            status,
            can_edit: status === 'draft',
            data: { customer: 'ACME', photos: PHOTO.uuid },
            attachments: [PHOTO],
        }),
    );
    server.use(...backend.handlers);
    const orgHeaders: (string | null)[] = [];
    server.use(
        http.get('*/api/v1/submissions/:uuid/attachments/:attachment', ({ request }) => {
            orgHeaders.push(request.headers.get('X-Organization-Id'));
            return new HttpResponse(new Uint8Array([1, 2, 3]), {
                headers: { 'Content-Type': 'image/jpeg' },
            });
        }),
    );
    return orgHeaders;
}

describe('attachment previews', () => {
    it('downloads existing images with X-Organization-Id and re-renders with blob URLs (detail)', async () => {
        const orgHeaders = setup('submitted');
        const { unmount } = renderApp(`/submissions/${UUID}`);
        const image = await screen.findByRole('img', { name: 'photo preview' });
        expect(image).toHaveAttribute('src', 'blob:preview-1');
        expect(orgHeaders).toEqual(['1']);
        unmount();
        expect(revokeObjectURL).toHaveBeenCalledWith('blob:preview-1');
    });

    it('shows previews on the fill screen and uses the local blob for fresh uploads', async () => {
        const orgHeaders = setup('draft');
        const upload = vi
            .spyOn(endpoints, 'uploadAttachment')
            .mockImplementation((_uuid, input) =>
                Promise.resolve({ ...PHOTO, uuid: input.attachmentUuid }),
            );
        const { user } = renderApp(`/fill/${UUID}`);
        expect(await screen.findByRole('img', { name: 'photo preview' })).toHaveAttribute(
            'src',
            'blob:preview-1',
        );
        await user.click(screen.getByRole('button', { name: 'fake-upload' }));
        await waitFor(() => {
            expect(screen.getByRole('img', { name: 'photo preview' })).toHaveAttribute(
                'src',
                'blob:preview-2',
            );
        });
        expect(createObjectURL).toHaveBeenLastCalledWith(expect.any(Blob));
        expect(orgHeaders).toEqual(['1']);
        upload.mockRestore();
    });
});
