/** "Document (PDF)" state: open link, "being created" with polling, or a localized error. */
import { FileText } from 'lucide-react';
import type { ReactNode } from 'react';
import { Alert, AlertTitle } from '../../components/ui/alert.tsx';
import { Button } from '../../components/ui/button.tsx';
import {
    isDocumentNotReady,
    useSubmissionDocument,
    type SubmissionDocumentState,
} from '../../hooks/useSubmissionDocument.ts';
import { useT } from '../../i18n/I18nProvider.tsx';
import type { MessageKey } from '../../i18n/en.ts';
import { NetworkError } from '../../lib/api/errors.ts';

/** Text of the always-mounted live region; `null` on failure (the alert speaks). */
function statusMessage(pdf: SubmissionDocumentState['query']): MessageKey | null {
    if (pdf.data !== undefined) {
        return 'document.ready';
    }
    if (pdf.isPending) {
        // Offline at mount: the query waits (paused) until the connection is back.
        return pdf.fetchStatus === 'paused' ? 'document.offline' : 'document.loading';
    }
    return isDocumentNotReady(pdf.error) ? 'document.notReady' : null;
}

export function DocumentStatus({ uuid }: { readonly uuid: string }): ReactNode {
    const t = useT();
    const { query: pdf, checkAgain } = useSubmissionDocument(uuid);
    const message = statusMessage(pdf);
    const ready = pdf.data !== undefined;
    const notReady = message === 'document.notReady';

    return (
        <>
            {/* Always mounted, so screen readers announce each change ("being created" → ready). */}
            <p role="status" className={ready || message === null ? 'sr-only' : undefined}>
                {message === null ? null : t(message)}
            </p>
            {pdf.data === undefined ? null : (
                <Button asChild variant="outline" size="lg" className="self-start">
                    <a href={pdf.data.url} target="_blank" rel="noopener noreferrer">
                        <FileText aria-hidden="true" />
                        {t('document.open')} <span className="sr-only">{t('common.newTab')}</span>
                    </a>
                </Button>
            )}
            {notReady ? (
                <Button variant="outline" className="self-start" onClick={checkAgain}>
                    {t('document.checkAgain')}
                </Button>
            ) : null}
            {message === null ? (
                <Alert variant="destructive" role="alert">
                    <AlertTitle>
                        {pdf.error instanceof NetworkError
                            ? t('document.offline')
                            : t('document.error')}
                    </AlertTitle>
                    <Button variant="outline" className="mt-2" onClick={checkAgain}>
                        {t('common.retry')}
                    </Button>
                </Alert>
            ) : null}
        </>
    );
}
