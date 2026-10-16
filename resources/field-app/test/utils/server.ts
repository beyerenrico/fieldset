import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { afterAll, afterEach, beforeAll, beforeEach } from 'vitest';
import { reloadOrganizationId } from '../../src/lib/session/org-store.ts';
import { wipeAll } from '../../src/lib/storage/kv.ts';

/** Default: the CSRF cookie endpoint always answers 204. */
export const server = setupServer(
    http.get('*/sanctum/csrf-cookie', () => new HttpResponse(null, { status: 204 })),
);

/** MSW lifecycle + clean storage for every test. */
export function setupTestServer(): void {
    beforeAll(() => {
        server.listen({ onUnhandledFrame: 'error' });
    });
    beforeEach(async () => {
        localStorage.clear();
        sessionStorage.clear();
        await wipeAll();
        reloadOrganizationId();
        document.cookie = 'XSRF-TOKEN=xsrf%3Dtoken; path=/';
    });
    afterEach(() => {
        server.resetHandlers();
    });
    afterAll(() => {
        server.close();
    });
}
