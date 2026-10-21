/** Submission detail: "Document (PDF)" and "Send to customer" (§9.1, §9.2). */
import type { FormDefinition } from '@fieldset/fieldset-js';
import { useId, type ReactNode } from 'react';
import { useT } from '../../i18n/I18nProvider.tsx';
import type { Submission } from '../../lib/api/types.ts';
import { DocumentStatus } from './DocumentStatus.tsx';
import { SendToCustomer } from './SendToCustomer.tsx';

export interface DocumentSectionProps {
    readonly submission: Submission;
    readonly definition: FormDefinition | undefined;
    /** The submit waits in the outbox: a send can be queued behind it when the form allows it. */
    readonly queuedSubmit?: boolean;
}

export function DocumentSection({
    submission,
    definition,
    queuedSubmit = false,
}: DocumentSectionProps): ReactNode {
    const t = useT();
    const headingId = useId();
    // Drafts have no rendition yet; the PDF is rendered after submit.
    const hasDocument = submission.status !== 'draft' && !queuedSubmit;
    const canSend =
        submission.can_send || (queuedSubmit && definition?.settings?.sendToCustomer === true);
    if (!hasDocument && !canSend) {
        return null;
    }
    return (
        <section
            aria-labelledby={headingId}
            className="flex flex-col gap-3 rounded-lg border-2 border-border bg-card p-4"
        >
            <h2 id={headingId} className="text-lg font-semibold">
                {t('document.title')}
            </h2>
            {hasDocument ? <DocumentStatus uuid={submission.uuid} /> : null}
            {canSend ? (
                <SendToCustomer
                    uuid={submission.uuid}
                    definition={definition}
                    data={submission.data}
                />
            ) : null}
        </section>
    );
}
