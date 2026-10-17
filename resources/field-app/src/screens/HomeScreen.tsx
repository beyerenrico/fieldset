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
import type { FormSummary, Submission } from '../lib/api/types.ts';
import type { DraftSync } from '../lib/db/schema.ts';
import { mergeDrafts } from './home/merge.ts';
import { OpenTasks } from './home/OpenTasks.tsx';

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
    readonly draft: Submission;
    readonly formName: string;
    readonly sync: DraftSync | null;
    readonly updatedAt: number;
}

function DraftItem({ draft, formName, sync, updatedAt }: DraftItemProps): ReactNode {
    const t = useT();
    const { dateTime } = useFormatters();
    return (
        <Card className="hover:bg-muted">
            <Link
                to="/fill/$uuid"
                params={{ uuid: draft.uuid }}
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
                {draft.status === 'changes_requested' ? (
                    <ChangesRequested review={draft.review} />
                ) : null}
            </Link>
        </Card>
    );
}

function Drafts(): ReactNode {
    const t = useT();
    const org = useOrganizationId();
    const drafts = useQuery(draftsQueryOptions(org));
    const local = useLocalDrafts(useDraftScope());
    const forms = useQuery(formsQueryOptions(org));
    const items = mergeDrafts(drafts.data, local);
    if (local === undefined || (drafts.isPending && items.length === 0)) {
        return <Loading />;
    }
    if (drafts.isError && items.length === 0) {
        return <ErrorState error={drafts.error} onRetry={() => void drafts.refetch()} />;
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
                            draft={item.submission}
                            formName={nameOf(item.submission.form_id)}
                            sync={item.sync}
                            updatedAt={item.updatedAt}
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
