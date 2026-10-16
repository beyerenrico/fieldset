import { createMemoryHistory } from '@tanstack/react-router';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { App, createAppQueryClient } from '../../src/App.tsx';

export function renderApp(path: string) {
    const history = createMemoryHistory({ initialEntries: [`/app${path}`] });
    const queryClient = createAppQueryClient();
    const user = userEvent.setup();
    const result = render(<App queryClient={queryClient} history={history} />);
    return { ...result, history, queryClient, user, screen };
}
