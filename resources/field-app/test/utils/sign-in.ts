/** The email-first sign-in (M7, ADR 0036): email → Continue → password → Sign in. */
import { screen } from '@testing-library/react';
import type { UserEvent } from '@testing-library/user-event';

export async function enterEmail(user: UserEvent, email = 'worker@demo.test'): Promise<void> {
    await user.type(await screen.findByLabelText('Email'), email);
    await user.click(screen.getByRole('button', { name: 'Continue' }));
}

export async function signIn(
    user: UserEvent,
    email = 'worker@demo.test',
    password = 'secret',
): Promise<void> {
    await enterEmail(user, email);
    await user.type(await screen.findByLabelText('Password'), password);
    await user.click(screen.getByRole('button', { name: 'Sign in' }));
}
