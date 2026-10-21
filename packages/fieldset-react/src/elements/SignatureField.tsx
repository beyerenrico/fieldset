/** signature: captured drawing uploaded as PNG plus signer name and timestamp. */
import type { ElementNode, JsonObject, JsonValue } from '@fieldset/fieldset-js';
import { useRef, useState, type ReactElement } from 'react';
import { isJsonObject, type DataPath } from '../data/path.ts';
import { formatDateTime } from '../format/intl.ts';
import { nowRfc3339 } from '../format/datetime.ts';
import { interpolate } from '../i18n/messages.ts';
import { uploadErrorText } from './attachment-ids.ts';
import { Field } from './Field.tsx';
import { SignatureCapture } from './SignatureCapture.tsx';
import { signerNameDefault } from './signer-name.ts';
import { useField } from './useField.ts';

interface SignatureValue {
    readonly attachment: string;
    readonly signerName: string;
    readonly signedAt: string;
}

function signatureOf(value: JsonValue | undefined): SignatureValue | null {
    if (!isJsonObject(value)) {
        return null;
    }
    const { attachment, signerName, signedAt } = value;
    return typeof attachment === 'string' &&
        typeof signerName === 'string' &&
        typeof signedAt === 'string'
        ? { attachment, signerName, signedAt }
        : null;
}

export function SignatureField({
    node,
    base,
}: {
    readonly node: ElementNode;
    readonly base: DataPath;
}): ReactElement {
    const field = useField(node, base);
    const { attachments, messages, locale } = field.context;
    const current = signatureOf(field.value);
    const [editing, setEditing] = useState(false);
    const [busy, setBusy] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);
    const [localUrl, setLocalUrl] = useState<string | null>(null);
    /** Guards against a second confirm before the busy state rendered (double tap). */
    const running = useRef(false);

    /** Uploads the PNG and returns the field value. */
    const save = async (blob: Blob, signerName: string): Promise<JsonObject> => {
        const png = blob.type === 'image/png' ? blob : new Blob([blob], { type: 'image/png' });
        const info = await attachments.upload({
            file: png,
            fileName: 'signature.png',
            kind: 'signature',
            elementKey: node.key,
            pointer: field.pointer,
        });
        setLocalUrl(info.url ?? null);
        return { attachment: info.id, signerName, signedAt: nowRfc3339() };
    };

    const confirm = async (encoding: Promise<Blob | null>, signerName: string): Promise<void> => {
        if (running.current) {
            return;
        }
        running.current = true;
        const edit = field.context.startEdit(field.path);
        setBusy(true);
        setFailure(null);
        try {
            const blob = await encoding;
            if (blob !== null) {
                const value = await save(blob, signerName);
                edit.update(() => value);
                setEditing(false);
            }
        } catch (error: unknown) {
            setFailure(uploadErrorText(error, messages.uploadFailed));
        } finally {
            running.current = false;
            setBusy(false);
            field.touch();
            edit.finish();
        }
    };

    const showCapture = !field.disabled && (current === null || editing);
    const imageUrl =
        current === null ? null : (attachments.get?.(current.attachment)?.url ?? localUrl);
    return (
        <Field field={field} group>
            {current !== null && !showCapture && (
                <div className="fsr-signature__value">
                    {imageUrl !== null && (
                        <img
                            className="fsr-signature__image"
                            src={imageUrl}
                            alt={current.signerName}
                        />
                    )}
                    <p>
                        {interpolate(messages.signedBy, {
                            name: current.signerName,
                            date: formatDateTime(current.signedAt, locale),
                        })}
                    </p>
                    {!field.disabled && (
                        <button
                            type="button"
                            className="fsr-button fsr-button--secondary"
                            onClick={() => {
                                setEditing(true);
                            }}
                        >
                            {messages.signAgain}
                        </button>
                    )}
                </div>
            )}
            {showCapture && (
                <SignatureCapture
                    field={field}
                    initialName={
                        current?.signerName ?? signerNameDefault(node, base, field.context)
                    }
                    busy={busy}
                    onConfirm={(encoding, name) => {
                        void confirm(encoding, name);
                    }}
                />
            )}
            {current === null && field.disabled && <p className="fsr-muted">{messages.empty}</p>}
            {failure !== null && (
                <p className="fsr-error" role="alert">
                    {failure}
                </p>
            )}
        </Field>
    );
}
