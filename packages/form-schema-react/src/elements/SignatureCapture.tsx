/** Drawing step of a signature: signer name, canvas, clear and confirm. */
import { useEffect, useRef, useState, type ReactElement } from 'react';
import { createSignaturePad, type SignaturePadHandle } from '../media/signature-pad.ts';
import type { FieldState } from './useField.ts';

export interface SignatureCaptureProps {
    readonly field: FieldState;
    readonly initialName: string;
    readonly busy: boolean;
    /** Receives the PNG encoding still in progress, so the parent turns busy in the same tap. */
    readonly onConfirm: (encoding: Promise<Blob | null>, signerName: string) => void;
}

export function SignatureCapture({
    field,
    initialName,
    busy,
    onConfirm,
}: SignatureCaptureProps): ReactElement {
    const { messages } = field.context;
    const canvas = useRef<HTMLCanvasElement>(null);
    const pad = useRef<SignaturePadHandle | null>(null);
    const [name, setName] = useState(initialName);
    const [hasStrokes, setHasStrokes] = useState(false);
    const nameId = `${field.id}-signer`;

    useEffect(() => {
        if (canvas.current === null) {
            return undefined;
        }
        const handle = createSignaturePad(canvas.current, () => {
            setHasStrokes(true);
        });
        pad.current = handle;
        return () => {
            handle.destroy();
            pad.current = null;
        };
    }, []);

    const clear = (): void => {
        pad.current?.clear();
        setHasStrokes(false);
    };
    const confirm = (): void => {
        if (pad.current !== null) {
            onConfirm(pad.current.toBlob(), name.trim());
        }
    };
    const ready = hasStrokes && name.trim() !== '' && !busy;
    return (
        <div className="fsr-signature">
            <label htmlFor={nameId} className="fsr-label fsr-label--small">
                {messages.signerName}
            </label>
            <input
                id={nameId}
                className="fsr-input"
                type="text"
                autoComplete="name"
                value={name}
                onChange={(event) => {
                    setName(event.target.value);
                }}
            />
            <p className="fsr-help">{messages.signHere}</p>
            <canvas
                ref={canvas}
                className="fsr-signature__canvas"
                role="img"
                tabIndex={0}
                aria-label={messages.signatureCanvas}
                id={field.id}
                aria-describedby={field.describedBy}
            />
            <div className="fsr-actions">
                <button type="button" className="fsr-button fsr-button--secondary" onClick={clear}>
                    {messages.clearSignature}
                </button>
                <button
                    type="button"
                    className="fsr-button fsr-button--primary"
                    disabled={!ready}
                    onClick={confirm}
                >
                    {messages.confirmSignature}
                </button>
            </div>
        </div>
    );
}
