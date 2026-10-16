import { screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { definition, el, orderForm, page } from './fixtures/definitions.ts';
import { renderForm } from './helpers.tsx';

const form = definition([
    page('p', [
        el({ type: 'text', key: 'name', label: 'Name', required: true }),
        el({ type: 'email', key: 'mail', label: 'Email' }),
        el({ type: 'checkbox', key: 'company', label: 'Company' }),
        el({
            type: 'text',
            key: 'vat',
            label: 'VAT id',
            visibleIf: { var: 'company' },
            requiredIf: { var: 'company' },
        }),
        el({ type: 'text', key: 'country', label: 'Country', default: 'DE' }),
        el({ type: 'text', key: 'locked', label: 'Locked', readOnlyIf: true }),
    ]),
]);

describe('client errors', () => {
    it('shows an error after the field was touched', async () => {
        const { user } = renderForm(form);
        const mail = screen.getByLabelText('Email');
        await user.type(mail, 'nope');
        expect(mail).not.toHaveAttribute('aria-invalid');
        await user.tab();
        expect(mail).toHaveAttribute('aria-invalid', 'true');
        expect(mail).toHaveAccessibleDescription('Enter a valid email address.');
    });

    it('shows every error and a summary with showErrors', async () => {
        const { user, update } = renderForm(form);
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        update({ showErrors: true });
        const summary = screen.getByRole('alert');
        expect(summary).toHaveTextContent('Please check 1 field');
        await waitFor(() => {
            expect(summary).toHaveFocus();
        });
        await user.click(
            within(summary).getByRole('link', { name: 'Name: This field is required.' }),
        );
        await waitFor(() => {
            expect(screen.getByLabelText(/^Name/)).toHaveFocus();
        });
    });

    it('marks required fields, including requiredIf while empty', async () => {
        const { user } = renderForm(form);
        expect(screen.getByLabelText(/^Name/)).toHaveAttribute('aria-required', 'true');
        expect(screen.queryByLabelText(/VAT id/)).not.toBeInTheDocument();
        await user.click(screen.getByLabelText('Company'));
        const vat = screen.getByLabelText(/VAT id/);
        expect(vat).toHaveAttribute('aria-required', 'true');
        await user.type(vat, 'DE1');
        await user.click(screen.getByLabelText('Company'));
        expect(screen.queryByLabelText(/VAT id/)).not.toBeInTheDocument();
    });

    it('keeps values of fields that become invisible', async () => {
        const { user, lastData } = renderForm(form);
        await user.click(screen.getByLabelText('Company'));
        await user.type(screen.getByLabelText(/VAT id/), 'X');
        await user.click(screen.getByLabelText('Company'));
        expect(lastData()).toMatchObject({ company: false, vat: 'X' });
    });
});

describe('defaults and read-only hints', () => {
    it('shows defaults and materializes them on the next edit', async () => {
        const { user, lastData } = renderForm(form);
        expect(screen.getByLabelText('Country')).toHaveValue('DE');
        await user.type(screen.getByLabelText(/^Name/), 'A');
        expect(lastData()).toMatchObject({ name: 'A', country: 'DE' });
    });

    it('disables inputs with readOnlyIf: true', () => {
        renderForm(form);
        expect(screen.getByLabelText('Locked')).toBeDisabled();
    });
});

describe('external errors', () => {
    it('always shows errors passed by pointer, including row pointers', () => {
        renderForm(
            orderForm,
            { items: [{ title: 'A' }, { title: 'B' }] },
            {
                errors: [
                    { pointer: '/items/1/title', code: 'pattern' },
                    { pointer: '/items/0/qty', code: 'server_rule', message: 'Out of stock' },
                    { pointer: '/unknown', code: 'custom' },
                ],
            },
        );
        const titles = screen.getAllByLabelText(/Title/);
        expect(titles[1]).toHaveAccessibleDescription('The format is not valid.');
        expect(screen.getAllByLabelText('Qty')[0]).toHaveAccessibleDescription('Out of stock');
    });

    it('lists external errors in the summary in document order', () => {
        renderForm(
            orderForm,
            { items: [{ title: 'A' }, { title: '' }] },
            { showErrors: true, errors: [{ pointer: '/unknown', code: 'custom' }] },
        );
        const links = within(screen.getByRole('alert')).getAllByRole('link');
        expect(links.map((link) => link.textContent)).toEqual([
            'Title (Item 2): This field is required.',
            '/unknown: This value is not valid.',
        ]);
    });
});

describe('messages', () => {
    it('uses German texts for a German UI locale and applies overrides', () => {
        renderForm(
            form,
            {},
            { uiLocale: 'de', showErrors: true, messages: { errorSummaryTitleOne: 'Ein Problem' } },
        );
        expect(screen.getByRole('alert')).toHaveTextContent('Ein Problem');
        expect(
            screen.getByText('Dieses Feld ist erforderlich.', { selector: '.fsr-error' }),
        ).toBeInTheDocument();
    });

    it('reports every evaluation result', () => {
        const onEvaluate = vi.fn();
        renderForm(form, { name: 'x' }, { onEvaluate });
        expect(onEvaluate).toHaveBeenCalledWith(expect.objectContaining({ errors: [] }));
    });
});
