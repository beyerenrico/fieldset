/** Attachment rows and blob helpers for the attachment tests (agent B). */
import type { AttachmentRow } from '../../src/lib/db/schema.ts';

/** A pending photo of user 7 in organization 1 with the bytes "abc". */
export function attachmentRow(overrides: Partial<AttachmentRow> = {}): AttachmentRow {
    return {
        orgId: 1,
        userId: 7,
        uuid: 'att-1',
        submissionUuid: 'sub-1',
        kind: 'photo',
        elementKey: 'photos',
        fileName: 'photo.jpg',
        mime: 'image/jpeg',
        size: 3,
        bytes: new TextEncoder().encode('abc').buffer,
        state: 'pending',
        error: null,
        record: null,
        createdAt: 1,
        ...overrides,
    };
}

export function readBlob(blob: Blob): Promise<string> {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => {
            resolve(reader.result as string);
        };
        reader.onerror = () => {
            reject(reader.error ?? new Error('read failed'));
        };
        reader.readAsText(blob);
    });
}
