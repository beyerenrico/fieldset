import type { AttachmentAdapter, AttachmentInfo, AttachmentUpload } from '../types.ts';

export interface MemoryAttachmentAdapter extends AttachmentAdapter {
    /** Stored files by attachment id. */
    readonly files: ReadonlyMap<string, Blob>;
    /** Revokes every object URL created by the adapter. */
    dispose(): void;
}

function objectUrl(blob: Blob): string | undefined {
    return typeof URL.createObjectURL === 'function' ? URL.createObjectURL(blob) : undefined;
}

/** Keeps attachments in memory (builder preview, tests). Nothing leaves the page. */
export function createMemoryAttachmentAdapter(): MemoryAttachmentAdapter {
    const files = new Map<string, Blob>();
    const infos = new Map<string, AttachmentInfo>();
    return {
        files,
        upload(request: AttachmentUpload): Promise<AttachmentInfo> {
            const id = crypto.randomUUID();
            const url = objectUrl(request.file);
            const info: AttachmentInfo = {
                id,
                fileName: request.fileName,
                mime: request.file.type,
                size: request.file.size,
                ...(url === undefined ? {} : { url }),
            };
            files.set(id, request.file);
            infos.set(id, info);
            return Promise.resolve(info);
        },
        get(id: string): AttachmentInfo | undefined {
            return infos.get(id);
        },
        remove(id: string): void {
            const url = infos.get(id)?.url;
            if (url !== undefined) {
                URL.revokeObjectURL(url);
            }
            files.delete(id);
            infos.delete(id);
        },
        dispose(): void {
            for (const info of infos.values()) {
                if (info.url !== undefined) {
                    URL.revokeObjectURL(info.url);
                }
            }
            files.clear();
            infos.clear();
        },
    };
}
