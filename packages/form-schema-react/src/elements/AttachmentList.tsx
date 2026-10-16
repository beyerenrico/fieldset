/** Thumbnails / file chips of attached files with remove buttons. */
import type { ReactElement } from 'react';
import { interpolate, type RendererMessages } from '../i18n/messages.ts';
import type { AttachmentInfo } from '../types.ts';

export interface AttachmentListProps {
    readonly items: readonly AttachmentInfo[];
    readonly messages: RendererMessages;
    readonly onRemove: ((id: string) => void) | null;
}

function AttachmentPreview({
    info,
    name,
}: {
    readonly info: AttachmentInfo;
    readonly name: string;
}): ReactElement {
    const isImage =
        info.url !== undefined && (info.mime === undefined || info.mime.startsWith('image/'));
    if (isImage) {
        return <img className="fsr-attachment__thumb" src={info.url} alt={name} />;
    }
    return (
        <span className="fsr-attachment__file" aria-hidden="true">
            📄
        </span>
    );
}

export function AttachmentList({
    items,
    messages,
    onRemove,
}: AttachmentListProps): ReactElement | null {
    if (items.length === 0) {
        return null;
    }
    return (
        <ul className="fsr-attachments">
            {items.map((info) => {
                const name = info.fileName ?? messages.attachment;
                return (
                    <li key={info.id} className="fsr-attachment">
                        <AttachmentPreview info={info} name={name} />
                        <span className="fsr-attachment__name">{name}</span>
                        {onRemove !== null && (
                            <button
                                type="button"
                                className="fsr-button fsr-button--quiet"
                                aria-label={interpolate(messages.removeAttachment, { name })}
                                onClick={() => {
                                    onRemove(info.id);
                                }}
                            >
                                ✕
                            </button>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}
