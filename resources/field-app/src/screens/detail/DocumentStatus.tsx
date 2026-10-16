/** "Document (PDF)" state: open link, "being created" with polling, or a localized error. */
import { FileText } from 'lucide-react';
import { useEffect, useRef, type ReactNode, type RefObject } from 'react';
import { Alert, AlertTitle } from '../../components/ui/alert.tsx';
import { Button } from '../../components/ui/button.tsx';
import {
    documentFailureMessage,
    isDocumentFailed,
    isDocumentNotReady,
    useSubmissionDocument,
    type SubmissionDocumentState,
} from '../../hooks/useSubmissionDocument.ts';
import { useT, type Translate } from '../../i18n/I18nProvider.tsx';
import type { MessageKey } from '../../i18n/en.ts';
import { NetworkError } from '../../lib/api/errors.ts';

type DocumentQuery = SubmissionDocumentState['query'];

/** Text of the always-mounted live region; `null` on failure (the alert speaks). */
function statusMessage(pdf: DocumentQuery): MessageKey | null {
    // A permanent failure wins over an older link: the current PDF cannot be created.
    if (isDocumentFailed(pdf.error)) {
        return null;
    }
    if (pdf.data !== undefined) {
        return 'document.ready';
    }
    if (pdf.isPending) {
        // Offline at mount: the query waits (paused) until the connection is back.
        return pdf.fetchStatus === 'paused' ? 'document.offline' : 'document.loading';
    }
    return isDocumentNotReady(pdf.error) ? 'document.notReady' : null;
}

function problemTitle(error: Error | null, t: Translate): string {
    if (isDocumentFailed(error)) {
        return documentFailureMessage(error) ?? t('document.failed');
    }
    return error instanceof NetworkError ? t('document.offline') : t('document.error');
}

/**
 * Wraps a manual check. When the pressed control disappears with the answer (focus fell back to
 * <body>), focus moves to the first control now shown in `area`: "Open PDF" or a button.
 */
function useFocusAfterCheck(
    area: RefObject<HTMLElement | null>,
    pdf: DocumentQuery,
    check: () => void,
): () => void {
    const pending = useRef(false);
    const fetching = pdf.isFetching;
    useEffect(() => {
        if (!pending.current || fetching) {
            return;
        }
        pending.current = false;
        const active = document.activeElement;
        if (active === null || active === document.body) {
            area.current?.querySelector<HTMLElement>('a[href], button')?.focus();
        }
        // The answer timestamps re-run this even if the "fetching" render was batched away.
    }, [area, fetching, pdf.dataUpdatedAt, pdf.errorUpdatedAt]);
    return () => {
        pending.current = true;
        check();
    };
}

export function DocumentStatus({ uuid }: { readonly uuid: string }): ReactNode {
    const t = useT();
    const { query: pdf, checkAgain } = useSubmissionDocument(uuid);
    const area = useRef<HTMLDivElement>(null);
    const check = useFocusAfterCheck(area, pdf, checkAgain);
    const message = statusMessage(pdf);
    const url = message === 'document.ready' ? pdf.data?.url : undefined;

    return (
        <div ref={area} className="flex flex-col gap-3">
            {/* Always mounted, so screen readers announce each change ("being created" → ready). */}
            <p
                role="status"
                className={message === 'document.ready' || message === null ? 'sr-only' : undefined}
            >
                {message === null ? null : t(message)}
            </p>
            {url === undefined ? null : (
                <Button asChild variant="outline" size="lg" className="self-start">
                    <a href={url} target="_blank" rel="noopener noreferrer">
                        <FileText aria-hidden="true" />
                        {t('document.open')} <span className="sr-only">{t('common.newTab')}</span>
                    </a>
                </Button>
            )}
            {message === 'document.notReady' ? (
                <Button variant="outline" className="self-start" onClick={check}>
                    {t('document.checkAgain')}
                </Button>
            ) : null}
            {message === null ? (
                <Alert variant="destructive" role="alert">
                    <AlertTitle>{problemTitle(pdf.error, t)}</AlertTitle>
                    {/* A permanent failure is not retried automatically; the user may ask again. */}
                    <Button variant="outline" className="mt-2" onClick={check}>
                        {isDocumentFailed(pdf.error) ? t('document.checkAgain') : t('common.retry')}
                    </Button>
                </Alert>
            ) : null}
        </div>
    );
}
