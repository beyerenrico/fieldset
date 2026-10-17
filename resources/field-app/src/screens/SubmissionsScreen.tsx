import { useInfiniteQuery, useQuery } from '@tanstack/react-query';
import { getRouteApi, Link, useNavigate } from '@tanstack/react-router';
import { useId, type ReactNode } from 'react';
import { ErrorState, Loading } from '../components/RouteError.tsx';
import { StatusBadge } from '../components/StatusBadge.tsx';
import { Button } from '../components/ui/button.tsx';
import { Card } from '../components/ui/card.tsx';
import { Label } from '../components/ui/label.tsx';
import { formsQueryOptions } from '../hooks/queries.ts';
import { retryUnlessOffline } from '../hooks/queries/submissions.ts';
import { useFormatters } from '../hooks/useFormatters.ts';
import { useOrganizationId } from '../hooks/useSession.ts';
import { useT } from '../i18n/I18nProvider.tsx';
import { listSubmissions } from '../lib/api/endpoints.ts';
import { EDITABLE_STATUSES, SUBMISSION_STATUSES, type Submission } from '../lib/api/types.ts';
import { parseSubmissionsSearch } from '../routes/search.ts';
import { LocalSubmissions } from './home/LocalSubmissions.tsx';

const route = getRouteApi('/_auth/submissions');

function SubmissionItem({
    submission,
    formName,
}: {
    readonly submission: Submission;
    readonly formName: string;
}): ReactNode {
    const t = useT();
    const { dateTime } = useFormatters();
    const editable = submission.can_edit && EDITABLE_STATUSES.includes(submission.status);
    const body = (
        <span className="flex w-full items-center justify-between gap-2">
            <span className="flex min-w-0 flex-col">
                <span className="truncate font-semibold">{formName}</span>
                <span className="text-sm text-muted-foreground">
                    {submission.number ?? t('submissions.noNumber')} ·{' '}
                    {t('submissions.updated', { date: dateTime(submission.updated_at) })}
                </span>
            </span>
            <StatusBadge status={submission.status} />
        </span>
    );
    const className =
        'flex min-h-14 items-center p-4 focus-visible:outline-3 focus-visible:outline-ring';
    return (
        <Card className="hover:bg-muted">
            {editable ? (
                <Link to="/fill/$uuid" params={{ uuid: submission.uuid }} className={className}>
                    {body}
                </Link>
            ) : (
                <Link
                    to="/submissions/$uuid"
                    params={{ uuid: submission.uuid }}
                    className={className}
                >
                    {body}
                </Link>
            )}
        </Card>
    );
}

export function SubmissionsScreen(): ReactNode {
    const t = useT();
    const id = useId();
    const org = useOrganizationId();
    const { status } = route.useSearch();
    const navigate = useNavigate();
    const forms = useQuery(formsQueryOptions(org));
    const list = useInfiniteQuery({
        queryKey: ['submissions', org, 'list', status ?? 'all'],
        queryFn: ({ pageParam }) => listSubmissions({ status, page: pageParam }),
        networkMode: 'always',
        retry: retryUnlessOffline,
        initialPageParam: 1,
        getNextPageParam: (last) =>
            last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined,
    });
    const items = list.data?.pages.flatMap((page) => page.items) ?? [];
    const nameOf = (formId: number): string =>
        forms.data?.find((form) => form.id === formId)?.name ??
        t('submissions.formFallback', { id: formId });

    return (
        <div className="flex flex-col gap-4">
            <h1 className="text-2xl font-bold">{t('submissions.title')}</h1>
            <div className="flex flex-col gap-2">
                <Label htmlFor={`${id}-status`}>{t('submissions.filter')}</Label>
                <select
                    id={`${id}-status`}
                    className="min-h-11 rounded-md border-2 border-input bg-background px-3 text-base focus-visible:outline-3 focus-visible:outline-ring"
                    value={status ?? ''}
                    onChange={(event) => {
                        void navigate({
                            to: '/submissions',
                            search: parseSubmissionsSearch({ status: event.target.value }),
                        });
                    }}
                >
                    <option value="">{t('submissions.all')}</option>
                    {SUBMISSION_STATUSES.map((value) => (
                        <option key={value} value={value}>
                            {t(`status.${value}`)}
                        </option>
                    ))}
                </select>
            </div>
            <LocalSubmissions nameOf={nameOf} />
            {list.isPending ? <Loading /> : null}
            {list.isError ? (
                <ErrorState error={list.error} onRetry={() => void list.refetch()} />
            ) : null}
            {list.isSuccess && items.length === 0 ? (
                <p className="text-muted-foreground">{t('submissions.empty')}</p>
            ) : null}
            <ul className="flex flex-col gap-2">
                {items.map((submission) => (
                    <li key={submission.uuid}>
                        <SubmissionItem
                            submission={submission}
                            formName={nameOf(submission.form_id)}
                        />
                    </li>
                ))}
            </ul>
            {list.hasNextPage ? (
                <Button
                    variant="outline"
                    disabled={list.isFetchingNextPage}
                    onClick={() => void list.fetchNextPage()}
                >
                    {list.isFetchingNextPage ? t('common.loading') : t('submissions.loadMore')}
                </Button>
            ) : null}
        </div>
    );
}
