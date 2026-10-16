/** "Changes requested" (§6.5): the reviewer's comment, who asked and when, plus an optional action. */
import { MessageSquareWarning } from 'lucide-react';
import { useId, type ReactNode } from 'react';
import { useFormatters } from '../hooks/useFormatters.ts';
import { useT } from '../i18n/I18nProvider.tsx';
import type { ReviewDecision } from '../lib/api/types.ts';
import { Alert } from './ui/alert.tsx';

export interface ReviewNoteProps {
    readonly review: ReviewDecision | null | undefined;
    /** Inside a link (Home): plain text only, no heading or interactive content. */
    readonly compact?: boolean;
    readonly children?: ReactNode;
}

function Byline({ review }: { readonly review: ReviewDecision }): ReactNode {
    const t = useT();
    const { dateTime } = useFormatters();
    const name = review.by?.name ?? t('changes.unknownReviewer');
    return (
        <span className="block text-sm text-muted-foreground">
            {review.at === null ? name : t('changes.by', { name, date: dateTime(review.at) })}
        </span>
    );
}

function Comment({ review }: { readonly review: ReviewDecision | null | undefined }): ReactNode {
    const t = useT();
    const comment = review?.comment?.trim() ?? '';
    if (comment === '') {
        return <span className="block italic">{t('changes.noComment')}</span>;
    }
    return <span className="block whitespace-pre-line break-words font-medium">{comment}</span>;
}

export function ReviewNote({ review, compact = false, children }: ReviewNoteProps): ReactNode {
    const t = useT();
    const titleId = useId();
    if (compact) {
        return (
            <span className="mt-2 block rounded-md border-l-4 border-warning bg-warning/15 px-3 py-2">
                <Comment review={review} />
                {review === null || review === undefined ? null : <Byline review={review} />}
            </span>
        );
    }
    return (
        <Alert variant="warning">
            <section aria-labelledby={titleId} className="flex flex-col gap-2">
                <h2 id={titleId} className="flex items-center gap-2 text-lg font-bold">
                    <MessageSquareWarning aria-hidden="true" className="size-5 shrink-0" />
                    {t('changes.title')}
                </h2>
                <blockquote className="border-l-4 border-warning pl-3">
                    <Comment review={review} />
                    {review === null || review === undefined ? null : <Byline review={review} />}
                </blockquote>
                {children}
            </section>
        </Alert>
    );
}
