import { screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { definition, el, inputsForm, page } from './fixtures/definitions.ts';
import { renderForm } from './helpers.tsx';

const wizard = definition(
    [
        page('one', [
            el({ type: 'text', key: 'name', label: 'Name', required: true }),
            el({ type: 'toggle', key: 'details', label: 'More details' }),
        ]),
        page('two', [el({ type: 'text', key: 'extra', label: 'Extra' })], {
            visibleIf: { var: 'details' },
            description: 'Optional',
        }),
        page(
            'three',
            [
                el({ type: 'checkbox', key: 'skip', label: 'Skip me' }),
                el({
                    type: 'text',
                    key: 'note',
                    label: 'Note',
                    visibleIf: { '!': { var: 'skip' } },
                }),
            ],
            {
                visibleIf: { '!': { var: 'skip' } },
            },
        ),
        page('four', [el({ type: 'text', key: 'last', label: 'Last', required: true })]),
    ],
    { settings: { layout: 'wizard' } },
);

describe('wizard layout', () => {
    it('blocks Next while the page has errors and shows a page summary', async () => {
        const { user } = renderForm(wizard);
        expect(screen.getByText('Step 1 of 3')).toBeInTheDocument();
        expect(screen.getByRole('progressbar')).toHaveAttribute('value', '1');
        expect(screen.queryByRole('button', { name: 'Back' })).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Next' }));
        const summary = screen.getByRole('alert');
        expect(summary).toHaveTextContent('Name: This field is required.');
        await waitFor(() => {
            expect(summary).toHaveFocus();
        });
        expect(screen.getByLabelText(/^Name/)).toHaveAttribute('aria-invalid', 'true');
    });

    it('navigates visible pages, skips invisible ones and ends with the primary action', async () => {
        const onPageChange = vi.fn();
        const submit = vi.fn();
        const { user } = renderForm(
            wizard,
            { name: 'A' },
            { onPageChange, primaryAction: { label: 'Review', onClick: submit } },
        );
        expect(onPageChange).toHaveBeenLastCalledWith(0, 3);
        await user.click(screen.getByRole('button', { name: 'Next' }));
        const heading = screen.getByRole('heading', { name: 'Page three' });
        await waitFor(() => {
            expect(heading).toHaveFocus();
        });
        expect(onPageChange).toHaveBeenLastCalledWith(1, 3);
        await user.click(screen.getByRole('button', { name: 'Back' }));
        await user.click(screen.getByRole('switch', { name: 'More details' }));
        expect(screen.getByText('Step 1 of 4')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Next' }));
        expect(screen.getByText('Optional')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Next' }));
        await user.click(screen.getByRole('button', { name: 'Next' }));
        expect(screen.getByRole('heading', { name: 'Page four' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Next' })).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Review' }));
        expect(submit).toHaveBeenCalledTimes(1);
    });

    it('moves on when the current page disappears', async () => {
        const { user } = renderForm(wizard, { name: 'A' });
        await user.click(screen.getByRole('button', { name: 'Next' }));
        await user.click(screen.getByLabelText('Skip me'));
        expect(screen.getByRole('heading', { name: 'Page four' })).toBeInTheDocument();
        expect(screen.getByText('Step 2 of 2')).toBeInTheDocument();
    });

    it('switches to the page of an error chosen in the summary', async () => {
        const { user } = renderForm(wizard, { name: 'A' }, { showErrors: true });
        const summary = screen.getByRole('alert');
        await user.click(within(summary).getByRole('link', { name: /^Last/ }));
        await waitFor(() => {
            expect(screen.getByLabelText(/^Last/)).toHaveFocus();
        });
        expect(screen.getByText('Step 3 of 3')).toBeInTheDocument();
    });

    it('can be forced to scroll layout', () => {
        renderForm(wizard, {}, { layout: 'scroll' });
        expect(screen.queryByText(/^Step/)).not.toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Page four' })).toBeInTheDocument();
    });
});

describe('scroll layout, modes and read-only', () => {
    it('renders a sticky primary action', async () => {
        const onClick = vi.fn();
        const { user } = renderForm(inputsForm, {}, { primaryAction: { label: 'Save', onClick } });
        await user.click(screen.getByRole('button', { name: 'Save' }));
        expect(onClick).toHaveBeenCalled();
    });

    it('disables a busy primary action', () => {
        renderForm(
            inputsForm,
            {},
            { primaryAction: { label: 'Save', onClick: vi.fn(), busy: true } },
        );
        const button = screen.getByRole('button', { name: 'Save' });
        expect(button).toBeDisabled();
        expect(button).toHaveAttribute('aria-busy', 'true');
    });

    it('shows the preview banner in preview mode', () => {
        renderForm(inputsForm, {}, { mode: 'preview' });
        expect(screen.getByRole('note')).toHaveTextContent('Preview – entries are not saved.');
    });

    it('disables inputs when read-only except the listed types', () => {
        const form = definition([
            page('p', [
                el({ type: 'text', key: 'a', label: 'A' }),
                el({ type: 'signature', key: 's', label: 'Sign' }),
            ]),
        ]);
        renderForm(form, {}, { readOnly: true, readOnlyExcept: ['signature'] });
        expect(screen.getByLabelText('A')).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Confirm signature' })).toBeInTheDocument();
    });

    it('renders empty read-only media values', () => {
        const form = definition([
            page('p', [
                el({ type: 'signature', key: 's', label: 'Sign' }),
                el({ type: 'location', key: 'l', label: 'Where' }),
            ]),
        ]);
        renderForm(form, {}, { readOnly: true });
        expect(screen.getAllByText('—')).toHaveLength(2);
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });
});
