import { screen, waitFor } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { getDeviceRecord, saveDeviceRecord } from '../../src/lib/device/credentials.ts';
import { createBackend, fail, ok } from '../utils/backend.ts';
import { ORG_A, ORG_B, makeMe } from '../utils/fixtures.ts';
import { renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';

setupTestServer();

async function signIn(user: ReturnType<typeof renderApp>['user']) {
    await user.type(await screen.findByLabelText('Email'), 'worker@demo.test');
    await user.type(screen.getByLabelText('Password'), 'secret');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));
}

describe('login', () => {
    it('redirects to login without a session and signs in (device registered)', async () => {
        const backend = createBackend({ loggedIn: false, deviceSecret: 'dev-secret' });
        server.use(...backend.handlers);
        const { user, history } = renderApp('/');
        expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        await signIn(user);
        expect(
            await screen.findByRole('heading', { name: /Hello, Willi Worker/ }),
        ).toBeInTheDocument();
        expect(history.location.pathname).toBe('/app/');
        expect((await getDeviceRecord())?.secret).toBe('dev-secret');
    });

    it('asks for the 2FA code, accepts a recovery code, and rejects wrong codes', async () => {
        const backend = createBackend({ loggedIn: false, twoFactor: true });
        server.use(...backend.handlers);
        const { user } = renderApp('/login');
        await signIn(user);
        expect(
            await screen.findByRole('heading', { name: 'Two-factor authentication' }),
        ).toBeInTheDocument();
        await user.type(screen.getByLabelText('Authentication code'), '000000');
        await user.click(screen.getByRole('button', { name: 'Verify' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('The code is not valid.');
        await user.click(screen.getByRole('button', { name: 'Use a recovery code' }));
        await user.type(screen.getByLabelText('Recovery code'), 'abc-def');
        await user.click(screen.getByRole('button', { name: 'Verify' }));
        expect(await screen.findByRole('heading', { name: /Hello/ })).toBeInTheDocument();
    });

    it.each([
        [
            HttpResponse.json({ message: 'x', errors: { email: ['bad'] } }, { status: 422 }),
            'The email or password is not correct.',
        ],
        [HttpResponse.json({ message: 'slow' }, { status: 429 }), 'Too many attempts'],
        [HttpResponse.error(), 'You are offline'],
        [HttpResponse.json({ message: 'boom' }, { status: 500 }), 'Something went wrong'],
    ])('shows login errors (%#)', async (response, text) => {
        server.use(http.post('*/auth/login', () => response));
        const { user } = renderApp('/login');
        await signIn(user);
        expect(await screen.findByRole('alert')).toHaveTextContent(text);
    });

    it('still signs in when device registration fails', async () => {
        const backend = createBackend({ loggedIn: false });
        server.use(...backend.handlers);
        server.use(http.post('*/api/v1/devices', () => fail(500, 'boom')));
        const { user } = renderApp('/login');
        await signIn(user);
        expect(await screen.findByRole('heading', { name: /Hello/ })).toBeInTheDocument();
    });

    it('shows the session-expired notice and keeps local data', async () => {
        await saveDeviceRecord({ deviceUuid: 'd1', secret: 's1' });
        server.use(...createBackend({ loggedIn: false }).handlers);
        renderApp('/');
        expect(await screen.findByRole('status')).toHaveTextContent('Your session has ended');
        expect(await getDeviceRecord()).toEqual({ deviceUuid: 'd1', secret: 's1' });
    });

    it('wipes the device and explains it', async () => {
        await saveDeviceRecord({ deviceUuid: 'd1', secret: 's1' });
        server.use(...createBackend({ loggedIn: false }).handlers);
        server.use(http.post('*/api/v1/auth/device-status', () => ok({ status: 'wipe' })));
        renderApp('/forms');
        expect(await screen.findByRole('status')).toHaveTextContent('local data was removed');
        await waitFor(async () => {
            expect(await getDeviceRecord()).toBeUndefined();
        });
    });

    it('asks to pick an organization when the user has several', async () => {
        const backend = createBackend({ me: makeMe([ORG_A, ORG_B]) });
        let orgHeader: string | null = null;
        server.use(...backend.handlers);
        server.use(
            http.get('*/api/v1/forms', ({ request }) => {
                orgHeader = request.headers.get('X-Organization-Id');
                return ok([]);
            }),
        );
        const { user } = renderApp('/forms');
        expect(
            await screen.findByRole('heading', { name: 'Choose an organization' }),
        ).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Second AG' }));
        expect(await screen.findByRole('heading', { name: /Hello/ })).toBeInTheDocument();
        await user.click(screen.getByRole('link', { name: 'Forms' }));
        await screen.findByText('No forms found.');
        expect(orgHeader).toBe('2');
    });

    it('renders a not-found page', async () => {
        server.use(...createBackend().handlers);
        renderApp('/nope/nothing');
        expect(await screen.findByRole('heading', { name: 'Page not found' })).toBeInTheDocument();
    });
});
