/** M4 endpoints: tasks, task completion, notifications, preferences; task idempotency keys. */
import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { paths } from '../../src/lib/api/paths.ts';
import { nextPage } from '../../src/lib/api/pagination.ts';
import {
    completeTask,
    fetchNotificationPreferences,
    listNotifications,
    listTasks,
    markNotificationsRead,
    saveNotificationPreferences,
} from '../../src/lib/api/workflow.ts';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import {
    clearTaskIdempotencyKey,
    getTaskIdempotencyKey,
} from '../../src/lib/submit/idempotency.ts';
import { fail, ok } from '../utils/backend.ts';
import { makeNotification, makeSubmission, makeTask } from '../utils/fixtures.ts';
import { server, setupTestServer } from '../utils/server.ts';

setupTestServer();

describe('task endpoints', () => {
    it('lists open tasks by default with the tenant header and pagination meta', async () => {
        setOrganizationId(1);
        let url = '';
        let org: string | null = null;
        server.use(
            http.get('*/api/v1/tasks', ({ request }) => {
                url = request.url;
                org = request.headers.get('X-Organization-Id');
                return ok([makeTask()], {}, { current_page: 1, last_page: 2, total: 3 });
            }),
        );
        const page = await listTasks();
        expect(new URL(url).search).toBe('?status=open');
        expect(org).toBe('1');
        expect(page.items[0]?.submission.form.name).toBe('Service report');
        expect(page.meta).toEqual({ current_page: 1, last_page: 2, total: 3 });
        expect(nextPage(page.meta)).toBe(2);
        await listTasks({ status: 'completed', page: 2 });
        expect(new URL(url).search).toBe('?status=completed&page=2');
    });

    it('tolerates a list without data or meta', async () => {
        server.use(http.get('*/api/v1/tasks', () => ok(null)));
        const page = await listTasks();
        expect(page).toEqual({ items: [], meta: { current_page: 1, last_page: 1, total: 0 } });
        expect(nextPage(page.meta)).toBeUndefined();
    });

    it('completes a task with the Idempotency-Key and returns task + submission', async () => {
        let key: string | null = null;
        let body: unknown = null;
        server.use(
            http.post('*/api/v1/tasks/:id/complete', async ({ params, request }) => {
                key = request.headers.get('Idempotency-Key');
                body = await request.json();
                return ok({
                    task: makeTask({ id: Number(params.id), status: 'completed' }),
                    submission: makeSubmission({ status: 'changes_requested' }),
                });
            }),
        );
        const result = await completeTask(
            12,
            { outcome: 'reject', comment: 'Seriennummer fehlt' },
            'k-1',
        );
        expect(key).toBe('k-1');
        expect(body).toEqual({ outcome: 'reject', comment: 'Seriennummer fehlt' });
        expect(result.task.id).toBe(12);
        expect(result.submission.status).toBe('changes_requested');
        expect(paths.completeTask(12)).toBe('/api/v1/tasks/12/complete');
    });

    it('maps task_closed and validation errors', async () => {
        server.use(
            http.post('*/api/v1/tasks/:id/complete', ({ params }) =>
                params.id === '1'
                    ? fail(409, 'task_closed')
                    : fail(422, 'validation_failed', [{ pointer: '/comment', code: 'required' }]),
            ),
        );
        await expect(completeTask(1, { outcome: 'approve' }, 'k')).rejects.toMatchObject({
            status: 409,
            code: 'task_closed',
        });
        await expect(completeTask(2, { outcome: 'reject' }, 'k')).rejects.toMatchObject({
            status: 422,
            details: [{ pointer: '/comment', code: 'required' }],
        });
    });
});

describe('task idempotency keys', () => {
    it('reuses the key for the same decision and rotates it for another one', () => {
        const first = getTaskIdempotencyKey(5, 'approve');
        expect(getTaskIdempotencyKey(5, 'approve')).toBe(first);
        expect(getTaskIdempotencyKey(6, 'approve')).not.toBe(first);
        const other = getTaskIdempotencyKey(5, 'reject:x');
        expect(other).not.toBe(first);
        expect(getTaskIdempotencyKey(5, 'reject:x')).toBe(other);
        clearTaskIdempotencyKey(5);
        expect(getTaskIdempotencyKey(5, 'reject:x')).not.toBe(other);
    });
});

describe('notification endpoints', () => {
    it('lists notifications with the unread count from meta', async () => {
        let url = '';
        server.use(
            http.get('*/api/v1/notifications', ({ request }) => {
                url = request.url;
                return ok(
                    [makeNotification()],
                    {},
                    { current_page: 2, last_page: 2, total: 21, unread: 4 },
                );
            }),
        );
        const page = await listNotifications(2);
        expect(new URL(url).search).toBe('?page=2');
        expect(page.unread).toBe(4);
        expect(page.meta).toEqual({ current_page: 2, last_page: 2, total: 21 });
    });

    it('counts unread items when meta.unread is missing', async () => {
        server.use(
            http.get('*/api/v1/notifications', () =>
                ok([
                    makeNotification({ id: 'a' }),
                    makeNotification({ id: 'b', read_at: '2026-10-16T10:00:00Z' }),
                ]),
            ),
        );
        expect((await listNotifications()).unread).toBe(1);
        server.use(http.get('*/api/v1/notifications', () => ok(null)));
        expect((await listNotifications()).items).toEqual([]);
    });

    it('marks given or all notifications as read without the tenant header', async () => {
        setOrganizationId(1);
        const bodies: unknown[] = [];
        const orgs: (string | null)[] = [];
        server.use(
            http.post('*/api/v1/notifications/read', async ({ request }) => {
                bodies.push(await request.json());
                orgs.push(request.headers.get('X-Organization-Id'));
                return ok({ unread: bodies.length === 1 ? 2 : 0 });
            }),
        );
        expect(await markNotificationsRead(['n1'])).toBe(2);
        expect(await markNotificationsRead()).toBe(0);
        expect(bodies).toEqual([{ ids: ['n1'] }, {}]);
        expect(orgs).toEqual([null, null]);
        server.use(
            http.post('*/api/v1/notifications/read', () => new HttpResponse(null, { status: 204 })),
        );
        expect(await markNotificationsRead()).toBe(0);
    });
});

describe('notification preferences', () => {
    it('reads and saves preferences, dropping malformed entries', async () => {
        let saved: unknown = null;
        server.use(
            http.get('*/api/v1/me/notification-preferences', () =>
                ok({
                    events: [
                        { event: 'review_assigned', channels: { database: true, mail: false } },
                        { event: 'form_available', channels: null },
                        { channels: { database: true } },
                        'junk',
                    ],
                }),
            ),
            http.put('*/api/v1/me/notification-preferences', async ({ request }) => {
                saved = await request.json();
                return ok(saved);
            }),
        );
        expect(await fetchNotificationPreferences()).toEqual([
            { event: 'review_assigned', channels: { database: true, mail: false } },
            { event: 'form_available', channels: { database: false, mail: false } },
        ]);
        const events = [{ event: 'review_assigned', channels: { database: false, mail: true } }];
        expect(await saveNotificationPreferences(events)).toEqual(events);
        expect(saved).toEqual({ events });
        server.use(http.get('*/api/v1/me/notification-preferences', () => ok(null)));
        expect(await fetchNotificationPreferences()).toEqual([]);
    });

    it('surfaces 422 for unknown events', async () => {
        server.use(
            http.put('*/api/v1/me/notification-preferences', () =>
                fail(422, 'validation_failed', [{ pointer: '/events/0/event', code: 'in' }]),
            ),
        );
        await expect(
            saveNotificationPreferences([
                { event: 'nope', channels: { database: true, mail: true } },
            ]),
        ).rejects.toMatchObject({ status: 422, code: 'validation_failed' });
    });
});
