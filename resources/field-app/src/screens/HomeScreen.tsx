import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { ReviewNote } from '../components/ReviewNote.tsx';
import { ErrorState, Loading } from '../components/RouteError.tsx';
import { StatusBadge } from '../components/StatusBadge.tsx';
import { Button } from '../components/ui/button.tsx';
import { Card } from '../components/ui/card.tsx';
import { draftsQueryOptions, formsQueryOptions } from '../hooks/queries.ts';
import { useFormatters } from '../hooks/useFormatters.ts';
import { useDraftScope, useLocalDrafts } from '../hooks/useLocalDrafts.ts';
import { useOrganizationId, useSession } from '../hooks/useSession.ts';
import { useShortcuts, useStartForm } from '../hooks/useShortcuts.ts';
import { useT } from '../i18n/I18nProvider.tsx';
import { NetworkError } from '../lib/api/errors.ts';
import type { FormSummary, Submission } from '../lib/api/types.ts';
import { ItemLink } from './home/ItemLink.tsx';
import { mergeDrafts, type DraftItem as DraftEntry } from './home/merge.ts';
import { OpenTasks } from './home/OpenTasks.tsx';
import { useAttachmentProblems, type AttachmentProblem } from './review/attachment-problems.ts';
import { FlashNotice } from './review/flash.tsx';
import { useRefusalText } from './review/RefusalNotice.tsx';

function FormShortcuts({
    title,
    forms,
}: {
    readonly title: string;
    readonly forms: readonly FormSummary[];
}): ReactNode {
    const t = useT();
    const start = useStartForm();
    if (forms.length === 0) {
        return null;
    }
    return (
        <div className="flex flex-col gap-2">
            <h3 className="font-semibold text-muted-foreground">{title}</h3>
            <ul className="flex flex-col gap-2">
                {forms.map((form) => (
                    <li key={form.id}>
                        <Button
                            variant="outline"
                            className="w-full justify-between"
                            aria-label={t('catalog.start', { name: form.name })}
                            onClick={() => {
                                start(form);
                            }}
                        >
                            <span className="truncate">{form.name}</span>
                            <ChevronRight aria-hidden="true" />
                        </Button>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** Inside the draft's link: the reviewer comment and the call to action (one target per draft). */
function ChangesRequested({ review }: { readonly review: Submission['review'] }): ReactNode {
    const t = useT();
    return (
        <>
            <ReviewNote review={review} compact />
            <span className="mt-1 inline-flex min-h-11 items-center gap-1 self-start font-semibold text-primary underline underline-offset-4">
                {t('changes.editResubmit')}
                <ChevronRight aria-hidden="true" className="size-5" />
            </span>
        </>
    );
}

interface DraftItemProps {
    readonly item: DraftEntry;
    readonly formName: string;
    readonly attachment: AttachmentProblem | undefined;
}

function DraftItem({ item, formName, attachment }: DraftItemProps): ReactNode {
    const t = useT();
    const refusalText = useRefusalText();
    const { dateTime } = useFormatters();
    const { submission: draft, sync, updatedAt, refusal } = item;
    return (
        <Card className="hover:bg-muted">
            <ItemLink
                uuid={draft.uuid}
                // A decision or a refused submit: straight to the review, where its panel is.
                target={item.attention ? 'review' : 'fill'}
                className="flex min-h-14 flex-col gap-1 p-4 focus-visible:outline-3 focus-visible:outline-ring"
            >
                <span className="flex items-center justify-between gap-2">
                    <span className="flex flex-col">
                        <span className="font-semibold">{formName}</span>
                        <span className="text-sm text-muted-foreground">
                            {t('home.updated', {
                                date: dateTime(new Date(updatedAt).toISOString()),
                            })}
                        </span>
                    </span>
                    <span className="flex flex-wrap justify-end gap-1">
                        {sync === null || sync === 'synced' ? null : <StatusBadge sync={sync} />}
                        {draft.status === 'draft' ? null : <StatusBadge status={draft.status} />}
                    </span>
                </span>
                {refusal === null ? null : (
                    <span className="text-sm font-semibold text-destructive">
                        {refusalText(refusal, attachment)}
                    </span>
                )}
                {draft.status === 'changes_requested' ? (
                    <ChangesRequested review={draft.review} />
                ) : null}
            </ItemLink>
        </Card>
    );
}

function Drafts(): ReactNode {
    const t = useT();
    const org = useOrganizationId();
    const drafts = useQuery(draftsQueryOptions(org));
    const scope = useDraftScope();
    const local = useLocalDrafts(scope);
    const forms = useQuery(formsQueryOptions(org));
    const items = mergeDrafts(drafts.data, local);
    const blockedUploads = (local ?? [])
        .filter((draft) => draft.row.blockedBy === 'attachment_blocked')
        .map((draft) => draft.row.uuid);
    const problems = useAttachmentProblems(blockedUploads, scope);
    if (local === undefined || (drafts.isPending && items.length === 0)) {
        return <Loading />;
    }
    if (drafts.isError && items.length === 0) {
        // Offline is no error: there is just nothing stored on this device.
        return drafts.error instanceof NetworkError ? (
            <p className="text-muted-foreground">{t('home.offlineEmpty')}</p>
        ) : (
            <ErrorState error={drafts.error} onRetry={() => void drafts.refetch()} />
        );
    }
    if (items.length === 0) {
        return <p className="text-muted-foreground">{t('home.noDrafts')}</p>;
    }
    const nameOf = (id: number): string =>
        forms.data?.find((form) => form.id === id)?.name ?? t('submissions.formFallback', { id });
    return (
        <>
            {drafts.isError ? (
                <p role="status" className="text-sm text-muted-foreground">
                    {t('sync.home.offline')}
                </p>
            ) : null}
            <ul className="flex flex-col gap-2">
                {items.map((item) => (
                    <li key={item.submission.uuid}>
                        <DraftItem
                            item={item}
                            formName={nameOf(item.submission.form_id)}
                            attachment={problems.get(item.submission.uuid)}
                        />
                    </li>
                ))}
            </ul>
        </>
    );
}

export function HomeScreen(): ReactNode {
    const t = useT();
    const { me } = useSession();
    const org = useOrganizationId();
    const forms = useQuery(formsQueryOptions(org));
    const { favorites, recent } = useShortcuts();
    const pick = (ids: readonly number[]): FormSummary[] =>
        ids.flatMap((id) => forms.data?.filter((form) => form.id === id) ?? []);
    const favoriteForms = pick(favorites);
    const recentForms = pick(recent);

    return (
        <div className="flex flex-col gap-8">
            <FlashNotice />
            <h1 className="text-2xl font-bold">{t('home.greeting', { name: me.user.name })}</h1>
            <OpenTasks />
            <section aria-labelledby="home-drafts" className="flex flex-col gap-3">
                <h2 id="home-drafts" className="text-xl font-bold">
                    {t('home.drafts')}
                </h2>
                <Drafts />
            </section>
            <section aria-labelledby="home-start" className="flex flex-col gap-3">
                <h2 id="home-start" className="text-xl font-bold">
                    {t('home.startNew')}
                </h2>
                <FormShortcuts title={t('home.favorites')} forms={favoriteForms} />
                <FormShortcuts title={t('home.recent')} forms={recentForms} />
                {favoriteForms.length + recentForms.length === 0 ? (
                    <p className="text-muted-foreground">{t('home.noShortcuts')}</p>
                ) : null}
                <Button asChild size="lg">
                    <Link to="/forms">{t('home.allForms')}</Link>
                </Button>
            </section>
        </div>
    );
}
