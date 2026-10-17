import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { afterAll, afterEach, beforeAll, beforeEach } from 'vitest';
import { disposeDraftSessions } from '../../src/lib/drafts/draft-session.ts';
import { resetAuthNotice } from '../../src/lib/session/auth-events.ts';
import { reloadOrganizationId } from '../../src/lib/session/org-store.ts';
import { clearAllTables } from '../../src/lib/db/db.ts';

/** Defaults: the CSRF cookie endpoint answers 204; discovery (M7) answers password mode. */
export const server = setupServer(
    http.get('*/sanctum/csrf-cookie', () => new HttpResponse(null, { status: 204 })),
    http.post('*/auth/sso/discover', () => HttpResponse.json({ mode: 'password' })),
);

/** MSW lifecycle + clean storage, auth notices and draft sessions for every test. */
export function setupTestServer(): void {
    beforeAll(() => {
        server.listen({ onUnhandledFrame: 'error' });
    });
    beforeEach(async () => {
        localStorage.clear();
        sessionStorage.clear();
        await clearAllTables();
        reloadOrganizationId();
        resetAuthNotice();
        disposeDraftSessions();
        document.cookie = 'XSRF-TOKEN=xsrf%3Dtoken; path=/';
    });
    afterEach(() => {
        server.resetHandlers();
    });
    afterAll(() => {
        server.close();
    });
}
