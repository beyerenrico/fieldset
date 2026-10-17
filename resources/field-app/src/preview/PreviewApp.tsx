import type { JsonObject } from '@form-schema-app/form-schema-js';
import { createMemoryAttachmentAdapter, FormRenderer } from '@form-schema-app/form-schema-react';
import { useEffect, useMemo, useState, type ReactElement } from 'react';
import { PreviewErrorBoundary } from './PreviewErrorBoundary.tsx';
import { parsePreviewMessage, PREVIEW_READY, type PreviewUpdate } from './protocol.ts';
import { sampleContext } from './sample-context.ts';
import { previewTexts } from './texts.ts';

export interface PreviewAppProps {
    /** Window that receives the ready message (the builder); defaults to `window.parent`. */
    readonly target?: Pick<Window, 'postMessage'>;
    /**
     * The builder's origin: the only origin accepted for incoming messages and the target origin of
     * the ready message. Defaults to `location.origin`, which inside the sandbox (opaque document
     * origin, ADR 0040) is still the origin of the preview URL, i.e. the builder's origin.
     */
    readonly origin?: string;
    /** The only window accepted as sender; defaults to `window.parent` (the builder). */
    readonly source?: MessageEventSource;
}

const EMPTY: JsonObject = {};

/**
 * Builder preview: renders the unsaved definition it receives via postMessage with the shared
 * renderer in preview mode. In-memory attachments only; no router, storage or service worker —
 * the sandbox gives it an opaque origin, where storage APIs throw anyway.
 */
export function PreviewApp({
    target = window.parent,
    origin = window.location.origin,
    source = window.parent,
}: PreviewAppProps): ReactElement {
    const [update, setUpdate] = useState<PreviewUpdate | null>(null);
    const [invalid, setInvalid] = useState(false);
    const [data, setData] = useState<JsonObject>(EMPTY);
    const [showErrors, setShowErrors] = useState(false);
    const adapter = useMemo(() => createMemoryAttachmentAdapter(), []);

    useEffect(
        () => () => {
            adapter.dispose();
        },
        [adapter],
    );

    useEffect(() => {
        const onMessage = (event: MessageEvent): void => {
            const parsed = parsePreviewMessage(event, origin, source);
            if (parsed.kind === 'ignore') {
                return;
            }
            setInvalid(parsed.kind === 'invalid');
            if (parsed.kind === 'update') {
                setUpdate(parsed.update);
                if (parsed.update.data !== undefined) {
                    setData(parsed.update.data);
                }
            }
        };
        window.addEventListener('message', onMessage);
        target.postMessage({ type: PREVIEW_READY }, origin);
        return () => {
            window.removeEventListener('message', onMessage);
        };
    }, [origin, source, target]);

    const definition = update?.definition;
    const locale = definition?.locale ?? navigator.language;
    const texts = previewTexts(locale);
    const context = useMemo(() => update?.context ?? sampleContext(), [update]);

    useEffect(() => {
        document.documentElement.lang = locale;
    }, [locale]);

    if (invalid || definition === undefined) {
        return (
            <main className="preview-shell">
                <p className="preview-status" role="status">
                    {invalid ? texts.invalid : texts.waiting}
                </p>
            </main>
        );
    }

    return (
        <main className="preview-shell">
            <h1 className="preview-title">{definition.title}</h1>
            <PreviewErrorBoundary
                resetKey={definition}
                fallback={
                    <p className="preview-status" role="alert">
                        {texts.invalid}
                    </p>
                }
            >
                <FormRenderer
                    definition={definition}
                    data={data}
                    context={context}
                    onChange={setData}
                    mode="preview"
                    attachments={adapter}
                    uiLocale={locale}
                    showErrors={showErrors}
                    primaryAction={{
                        label: texts.check,
                        onClick: () => {
                            setShowErrors(true);
                        },
                    }}
                />
            </PreviewErrorBoundary>
            <button
                type="button"
                className="preview-reset"
                onClick={() => {
                    setData(EMPTY);
                    setShowErrors(false);
                }}
            >
                {texts.reset}
            </button>
        </main>
    );
}
