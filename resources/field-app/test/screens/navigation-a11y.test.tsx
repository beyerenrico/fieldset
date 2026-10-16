/** WCAG 2.4.2 / focus order: localized page titles and focus on the new page after navigation. */
import { screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { writeJson } from '../../src/lib/storage/local.ts';
import { createBackend } from '../utils/backend.ts';
import { renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';

setupTestServer();

describe('navigation', () => {
    it('titles every page and moves focus to its heading, but not on first load', async () => {
        server.use(...createBackend().handlers);
        const { user } = renderApp('/');
        await screen.findByRole('heading', { level: 1, name: /Hello/ });
        await waitFor(() => {
            expect(document.title).toBe('Home – Field App');
        });
        expect(document.body).toHaveFocus();
        await user.click(screen.getByRole('link', { name: 'Settings' }));
        const heading = await screen.findByRole('heading', { level: 1, name: 'Settings' });
        await waitFor(() => {
            expect(heading).toHaveFocus();
        });
        expect(document.title).toBe('Settings – Field App');
        await user.click(screen.getByRole('link', { name: 'Forms' }));
        await waitFor(() => {
            expect(screen.getByRole('heading', { level: 1, name: 'Forms' })).toHaveFocus();
        });
        expect(document.title).toBe('Forms – Field App');
    });

    it('titles the not-found page', async () => {
        server.use(...createBackend().handlers);
        renderApp('/nope/nothing');
        await screen.findByRole('heading', { name: 'Page not found' });
        await waitFor(() => {
            expect(document.title).toBe('Page not found – Field App');
        });
    });

    it('uses the German titles', async () => {
        writeJson('settings', { language: 'de', largeText: false });
        server.use(...createBackend({ loggedIn: false }).handlers);
        renderApp('/login');
        await screen.findByRole('heading', { level: 1, name: 'Anmelden' });
        await waitFor(() => {
            expect(document.title).toBe('Anmelden – Field App');
        });
    });
});
