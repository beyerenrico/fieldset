/** MSW handlers of the M4 endpoints for the in-memory backend (tasks, notifications, prefs). */
import { http } from 'msw';
import type {
    AppNotification,
    NotificationPreference,
    Submission,
    Task,
} from '../../src/lib/api/types.ts';
import { fail, ok } from './backend.ts';

export interface WorkflowState {
    submissions: Map<string, Submission>;
    tasks: Task[];
    taskCalls: string[];
    completions: { id: number; key: string | null; body: unknown }[];
    notifications: AppNotification[];
    notificationCalls: number;
    reads: unknown[];
    preferences: readonly NotificationPreference[];
    preferenceSaves: unknown[];
}

interface CompleteBody {
    readonly outcome?: string;
    readonly comment?: string;
}

const REVIEWER = { id: 4, name: 'Rita Reviewer' };

function decide(state: WorkflowState, task: Task, body: CompleteBody) {
    const approved = body.outcome === 'approve';
    const done: Task = {
        ...task,
        status: 'completed',
        outcome: approved ? 'approved' : 'rejected',
        comment: body.comment ?? null,
    };
    state.tasks = state.tasks.map((candidate) => (candidate.id === task.id ? done : candidate));
    const uuid = task.submission.uuid;
    const existing = state.submissions.get(uuid);
    if (existing === undefined) {
        return { task: done, submission: null };
    }
    const submission: Submission = {
        ...existing,
        status: approved ? 'approved' : 'changes_requested',
        open_task_id: null,
        review: {
            outcome: done.outcome ?? 'approved',
            comment: done.comment,
            by: REVIEWER,
            at: '2026-10-16T11:00:00+02:00',
        },
    };
    state.submissions.set(uuid, submission);
    return { task: done, submission };
}

function unreadOf(state: WorkflowState): number {
    return state.notifications.filter((item) => item.read_at === null).length;
}

export function workflowHandlers(state: WorkflowState) {
    /** Idempotency-Key → body + answer of the first successful completion (replayed). */
    const replays = new Map<string, { body: string; answer: unknown }>();
    return [
        http.get('*/api/v1/tasks', ({ request }) => {
            const status = new URL(request.url).searchParams.get('status') ?? 'open';
            state.taskCalls.push(status);
            const items = state.tasks.filter((task) => task.status === status);
            return ok(items, {}, { current_page: 1, last_page: 1, total: items.length });
        }),
        http.post('*/api/v1/tasks/:id/complete', async ({ params, request }) => {
            const id = Number(params.id);
            const key = request.headers.get('Idempotency-Key');
            const body = (await request.json()) as CompleteBody;
            state.completions.push({ id, key, body });
            const replay = key === null ? undefined : replays.get(key);
            if (replay !== undefined) {
                return replay.body === JSON.stringify(body)
                    ? ok(replay.answer, { headers: { 'Idempotent-Replayed': 'true' } })
                    : fail(409, 'idempotency_key_reused');
            }
            const task = state.tasks.find((candidate) => candidate.id === id);
            if (task === undefined) {
                return fail(404, 'not_found');
            }
            if (task.status !== 'open') {
                return fail(409, 'task_closed');
            }
            if (body.outcome === 'reject' && (body.comment ?? '').trim() === '') {
                return fail(422, 'validation_failed', [{ pointer: '/comment', code: 'required' }]);
            }
            const answer = decide(state, task, body);
            if (key !== null) {
                replays.set(key, { body: JSON.stringify(body), answer });
            }
            return ok(answer);
        }),
        http.get('*/api/v1/notifications', () => {
            state.notificationCalls++;
            const items = state.notifications;
            return ok(
                items,
                {},
                { current_page: 1, last_page: 1, total: items.length, unread: unreadOf(state) },
            );
        }),
        http.post('*/api/v1/notifications/read', async ({ request }) => {
            const body = (await request.json()) as { ids?: string[] };
            state.reads.push(body);
            const now = '2026-10-16T12:00:00+02:00';
            state.notifications = state.notifications.map((item) =>
                body.ids === undefined || body.ids.includes(item.id)
                    ? { ...item, read_at: item.read_at ?? now }
                    : item,
            );
            return ok({ unread: unreadOf(state) });
        }),
        http.get('*/api/v1/me/notification-preferences', () => ok({ events: state.preferences })),
        http.put('*/api/v1/me/notification-preferences', async ({ request }) => {
            const body = (await request.json()) as { events: NotificationPreference[] };
            state.preferenceSaves.push(body);
            state.preferences = body.events;
            return ok({ events: state.preferences });
        }),
    ];
}
