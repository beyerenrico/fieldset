/** Review tasks, in-app notifications and notification preferences (M4, docs/api/field-app-v1.md). */
import { apiRequest } from './client.ts';
import { readNumber, toMeta } from './pagination.ts';
import { paths } from './paths.ts';
import type {
    AppNotification,
    NotificationPage,
    NotificationPreference,
    Page,
    Task,
    TaskCompletion,
    TaskDecision,
    TaskStatus,
} from './types.ts';

const isRecord = (value: unknown): value is Readonly<Record<string, unknown>> =>
    typeof value === 'object' && value !== null && !Array.isArray(value);

export interface TaskFilter {
    readonly status?: TaskStatus | undefined;
    readonly page?: number | undefined;
}

export async function listTasks(filter: TaskFilter = {}): Promise<Page<Task>> {
    const response = await apiRequest<readonly Task[]>(paths.tasks, {
        query: { status: filter.status ?? 'open', page: filter.page },
    });
    const items: readonly Task[] = Array.isArray(response.data) ? response.data : [];
    return { items, meta: toMeta(response.meta, items.length) };
}

export interface TaskCompletionInput {
    readonly outcome: TaskDecision;
    /** Required for `reject` (max 2000 characters). */
    readonly comment?: string;
}

/** Approve or reject; same idempotency rules as submit (replay on the same key + body). */
export async function completeTask(
    id: number,
    input: TaskCompletionInput,
    idempotencyKey: string,
): Promise<TaskCompletion> {
    const response = await apiRequest<TaskCompletion>(paths.completeTask(id), {
        method: 'POST',
        body: input,
        headers: { 'Idempotency-Key': idempotencyKey },
    });
    return response.data;
}

export async function listNotifications(page = 1): Promise<NotificationPage> {
    const response = await apiRequest<readonly AppNotification[]>(paths.notifications, {
        query: { page },
    });
    const items: readonly AppNotification[] = Array.isArray(response.data) ? response.data : [];
    const unread = readNumber(
        response.meta,
        'unread',
        items.filter((item) => item.read_at === null).length,
    );
    return { items, meta: toMeta(response.meta, items.length), unread };
}

/** Marks the given notifications (all when `ids` is omitted) as read; returns the unread count. */
export async function markNotificationsRead(ids?: readonly string[]): Promise<number> {
    const response = await apiRequest<unknown>(paths.notificationsRead, {
        method: 'POST',
        tenant: false,
        body: ids === undefined ? {} : { ids },
    });
    const unread = isRecord(response.data) ? response.data.unread : undefined;
    return typeof unread === 'number' ? unread : 0;
}

/** Parses `{ events: [{ event, channels }] }` defensively (unknown shapes are dropped). */
function preferencesOf(data: unknown): NotificationPreference[] {
    const events = isRecord(data) && Array.isArray(data.events) ? (data.events as unknown[]) : [];
    return events.flatMap((item): NotificationPreference[] => {
        if (!isRecord(item) || typeof item.event !== 'string') {
            return [];
        }
        const channels: Readonly<Record<string, unknown>> = isRecord(item.channels)
            ? item.channels
            : {};
        return [
            {
                event: item.event,
                channels: { database: channels.database === true, mail: channels.mail === true },
            },
        ];
    });
}

export async function fetchNotificationPreferences(): Promise<NotificationPreference[]> {
    const response = await apiRequest<unknown>(paths.notificationPreferences, { tenant: false });
    return preferencesOf(response.data);
}

export async function saveNotificationPreferences(
    events: readonly NotificationPreference[],
): Promise<NotificationPreference[]> {
    const response = await apiRequest<unknown>(paths.notificationPreferences, {
        method: 'PUT',
        tenant: false,
        body: { events },
    });
    return preferencesOf(response.data);
}
