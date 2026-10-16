/** Home "Open tasks" (§9.1): review tasks of the user, refreshed on focus and every minute. */
import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { AlertTriangle, ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { Card } from '../../components/ui/card.tsx';
import { openTasksQueryOptions } from '../../hooks/queries.ts';
import { useFormatters } from '../../hooks/useFormatters.ts';
import { useOrganizationId } from '../../hooks/useSession.ts';
import { useT } from '../../i18n/I18nProvider.tsx';
import type { Task } from '../../lib/api/types.ts';

function DueDate({ task }: { readonly task: Task }): ReactNode {
    const t = useT();
    const { dateTime } = useFormatters();
    if (task.due_at === null) {
        return null;
    }
    const date = dateTime(task.due_at);
    if (!task.overdue) {
        return <span className="text-sm text-muted-foreground">{t('tasks.due', { date })}</span>;
    }
    return (
        <span className="flex items-center gap-1 text-sm font-bold text-destructive">
            <AlertTriangle aria-hidden="true" className="size-4 shrink-0" />
            {t('tasks.overdue', { date })}
        </span>
    );
}

function TaskItem({ task }: { readonly task: Task }): ReactNode {
    const t = useT();
    const { submission } = task;
    const details = [
        submission.number === null ? null : t('tasks.number', { number: submission.number }),
        submission.submitted_by === null
            ? null
            : t('tasks.submittedBy', { name: submission.submitted_by.name }),
    ].filter((part): part is string => part !== null);
    return (
        <Card className={task.overdue ? 'border-destructive hover:bg-muted' : 'hover:bg-muted'}>
            <Link
                to="/submissions/$uuid"
                params={{ uuid: submission.uuid }}
                className="flex min-h-14 items-center justify-between gap-2 p-4 focus-visible:outline-3 focus-visible:outline-ring"
            >
                <span className="flex min-w-0 flex-col gap-0.5">
                    <span className="font-semibold">
                        {t('tasks.reviewOf', { form: submission.form.name })}
                    </span>
                    {details.length === 0 ? null : (
                        <span className="text-sm text-muted-foreground">{details.join(' · ')}</span>
                    )}
                    <DueDate task={task} />
                </span>
                <ChevronRight aria-hidden="true" className="size-5 shrink-0" />
            </Link>
        </Card>
    );
}

/** Hidden while loading, when empty and when the list cannot be loaded (no noise for workers). */
export function OpenTasks(): ReactNode {
    const t = useT();
    const org = useOrganizationId();
    const tasks = useQuery(openTasksQueryOptions(org));
    if (tasks.data === undefined || tasks.data.length === 0) {
        return null;
    }
    return (
        <section aria-labelledby="home-tasks" className="flex flex-col gap-3">
            <h2 id="home-tasks" className="text-xl font-bold">
                {t('tasks.title')}
            </h2>
            <ul className="flex flex-col gap-2">
                {tasks.data.map((task) => (
                    <li key={task.id}>
                        <TaskItem task={task} />
                    </li>
                ))}
            </ul>
        </section>
    );
}
