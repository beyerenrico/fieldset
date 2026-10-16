/** photo and file: native file input (camera for photos), compression, upload via the adapter. */
import type { ElementNode, JsonValue } from '@form-schema-app/form-schema-js';
import { useEffect, useRef, useState, type ChangeEvent, type ReactElement } from 'react';
import type { DataPath } from '../data/path.ts';
import { compressImage } from '../media/compress-image.ts';
import type { PendingEdit } from '../state/pending-edits.ts';
import type { AttachmentInfo, AttachmentKind } from '../types.ts';
import { attachmentIds, uploadErrorText } from './attachment-ids.ts';
import { AttachmentList } from './AttachmentList.tsx';
import { Field } from './Field.tsx';
import { inputAria, useField, type FieldState } from './useField.ts';

function acceptOf(node: ElementNode): string | undefined {
    const validation = node.raw['validation'];
    const accept =
        typeof validation === 'object' && validation !== null
            ? (validation as { accept?: unknown }).accept
            : undefined;
    if (Array.isArray(accept) && accept.length > 0) {
        return accept.filter((item): item is string => typeof item === 'string').join(',');
    }
    return node.type === 'photo' ? 'image/*' : undefined;
}

function maxFilesOf(node: ElementNode): number {
    const validation = node.raw['validation'];
    const maxFiles =
        typeof validation === 'object' && validation !== null
            ? (validation as { maxFiles?: unknown }).maxFiles
            : undefined;
    return typeof maxFiles === 'number' && maxFiles > 0 ? maxFiles : Number.POSITIVE_INFINITY;
}

function useLocalUrls(): (blob: Blob) => string | undefined {
    const urls = useRef<string[]>([]);
    useEffect(
        () => () => {
            urls.current.forEach((url) => {
                URL.revokeObjectURL(url);
            });
        },
        [],
    );
    return (blob) => {
        if (typeof URL.createObjectURL !== 'function') {
            return undefined;
        }
        const url = URL.createObjectURL(blob);
        urls.current.push(url);
        return url;
    };
}

function useUploader(field: FieldState, multiple: boolean) {
    const { attachments, messages } = field.context;
    const kind = field.node.type as AttachmentKind;
    const [infos, setInfos] = useState<ReadonlyMap<string, AttachmentInfo>>(new Map());
    const [pending, setPending] = useState(0);
    const [failure, setFailure] = useState<string | null>(null);
    const localUrl = useLocalUrls();
    const request = { kind, elementKey: field.node.key, pointer: field.pointer };

    const uploadOne = async (file: File, edit: PendingEdit): Promise<void> => {
        const prepared =
            kind === 'photo'
                ? await compressImage(file, file.name)
                : { blob: file, fileName: file.name };
        const info = await attachments.upload({
            ...request,
            file: prepared.blob,
            fileName: prepared.fileName,
        });
        const url =
            info.url ??
            (prepared.blob.type.startsWith('image/') ? localUrl(prepared.blob) : undefined);
        const stored: AttachmentInfo = {
            mime: prepared.blob.type,
            fileName: prepared.fileName,
            ...info,
            ...(url === undefined ? {} : { url }),
        };
        setInfos((current) => new Map(current).set(info.id, stored));
        let replaced: string | undefined;
        const applied = edit.update((current: JsonValue | undefined) => {
            if (multiple) {
                return [...attachmentIds(current), info.id];
            }
            replaced = attachmentIds(current)[0];
            return info.id;
        });
        if (applied && replaced !== undefined && replaced !== info.id) {
            void attachments.remove?.(replaced, request);
        }
    };

    const upload = async (files: readonly File[]): Promise<void> => {
        if (files.length === 0) {
            return;
        }
        setFailure(null);
        // One tracked edit per batch, started before compression (hosts wait for it).
        const edit = field.context.startEdit(field.path);
        try {
            for (const file of files) {
                setPending((count) => count + 1);
                try {
                    await uploadOne(file, edit);
                } catch (error: unknown) {
                    setFailure(uploadErrorText(error, messages.uploadFailed));
                } finally {
                    setPending((count) => count - 1);
                }
            }
            field.touch();
        } finally {
            edit.finish();
        }
    };

    const remove = (id: string): void => {
        field.context.updateValue(field.path, (current) => {
            const rest = attachmentIds(current).filter((candidate) => candidate !== id);
            return multiple ? (rest.length === 0 ? null : rest) : null;
        });
        void attachments.remove?.(id, request);
        field.touch();
    };

    const describe = (id: string): AttachmentInfo =>
        infos.get(id) ?? attachments.get?.(id) ?? { id };
    return { upload, remove, describe, pending, failure };
}

export function AttachmentField({
    node,
    base,
}: {
    readonly node: ElementNode;
    readonly base: DataPath;
}): ReactElement {
    const field = useField(node, base);
    const multiple = node.raw['multiple'] === true;
    const ids = attachmentIds(field.value);
    const { upload, remove, describe, pending, failure } = useUploader(field, multiple);
    const { messages } = field.context;
    const remaining = multiple ? maxFilesOf(node) - ids.length : 1;
    const onChange = (event: ChangeEvent<HTMLInputElement>): void => {
        const files = Array.from(event.target.files ?? []).slice(0, Math.max(remaining, 0));
        event.target.value = '';
        void upload(files);
    };
    const canAdd = !field.disabled && remaining > 0;
    return (
        <Field field={field}>
            <AttachmentList
                items={ids.map(describe)}
                messages={messages}
                onRemove={field.disabled ? null : remove}
            />
            {canAdd && (
                <div className="fsr-file">
                    <input
                        {...inputAria(field)}
                        className="fsr-file__input"
                        type="file"
                        accept={acceptOf(node)}
                        capture={node.type === 'photo' ? 'environment' : undefined}
                        multiple={multiple}
                        onChange={onChange}
                    />
                    <label
                        htmlFor={field.id}
                        className="fsr-button fsr-file__button"
                        aria-hidden="true"
                    >
                        {node.type === 'photo' ? messages.takePhoto : messages.chooseFile}
                    </label>
                </div>
            )}
            {pending > 0 && (
                <p className="fsr-status" role="status">
                    {messages.uploading}
                </p>
            )}
            {failure !== null && (
                <p className="fsr-error" role="alert">
                    {failure}
                </p>
            )}
        </Field>
    );
}
