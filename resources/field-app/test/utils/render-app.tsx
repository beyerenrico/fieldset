import { createMemoryHistory } from '@tanstack/react-router';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll } from 'vitest';
import { App, createAppQueryClient } from '../../src/App.tsx';

/** Module transforms of the lazy screens can take seconds when the machine is busy. */
const PRELOAD_TIMEOUT_MS = 60_000;

export function renderApp(path: string) {
    const history = createMemoryHistory({ initialEntries: [`/app${path}`] });
    const queryClient = createAppQueryClient();
    const user = userEvent.setup();
    const result = render(<App queryClient={queryClient} history={history} />);
    return { ...result, history, queryClient, user, screen };
}

/**
 * Imports the on-demand route screens (routes/router.tsx) once before the first test of a file.
 * Otherwise that test pays for transforming them inside its `find*` timeout, which made the first
 * test of a file time out when the whole suite (or another process) kept the CPU busy.
 * Module mocks of the test file (`vi.mock`) apply to these imports as well.
 */
export function preloadLazyScreens(): void {
    beforeAll(async () => {
        await Promise.all([
            import('../../src/screens/FillScreen.tsx'),
            import('../../src/screens/ReviewScreen.tsx'),
            import('../../src/screens/SubmissionDetailScreen.tsx'),
        ]);
    }, PRELOAD_TIMEOUT_MS);
}
