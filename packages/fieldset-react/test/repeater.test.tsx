import { act, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { definition, el, orderForm, page, tableForm } from './fixtures/definitions.ts';
import { plain, renderForm } from './helpers.tsx';

describe('repeater cards', () => {
    it('adds rows within maxItems and focuses the new row', async () => {
        const { user, lastData } = renderForm(orderForm, { items: [{ title: 'A' }] });
        const add = screen.getByRole('button', { name: 'Add item' });
        await user.click(add);
        expect(lastData()['items']).toEqual([{ title: 'A' }, {}]);
        await waitFor(() => {
            expect(screen.getAllByLabelText(/Title/)[1]).toHaveFocus();
        });
        expect(screen.getByRole('group', { name: 'Item 2' })).toBeInTheDocument();
        await user.click(add);
        expect(add).toBeDisabled();
    });

    it('does not pull focus back to the new row once the user typed into another field', async () => {
        const { lastData } = renderForm(orderForm, { items: [{ title: 'A' }] });
        fireEvent.click(screen.getByRole('button', { name: 'Add item' }));
        // The new row is not in the DOM when `add` runs, so the focus move is retried later.
        // A fast user (or Playwright's fill) is already in the next field by then.
        const title = screen.getAllByLabelText(/Title/)[1]!;
        const qty = screen.getAllByLabelText('Qty')[1]!;
        act(() => {
            title.focus();
        });
        fireEvent.input(title, { target: { value: 'Pos 2' } });
        act(() => {
            qty.focus();
        });
        fireEvent.input(qty, { target: { value: '1.5' } });
        await act(async () => {
            await new Promise((resolve) => setTimeout(resolve, 80));
        });
        expect(qty).toHaveFocus();
        expect(lastData()['items']).toEqual([{ title: 'A' }, { title: 'Pos 2', qty: '1.5' }]);
    });

    it('removes rows but never below minItems', async () => {
        const { user, lastData } = renderForm(orderForm, {
            items: [{ title: 'A' }, { title: 'B' }],
        });
        await user.click(screen.getByRole('button', { name: 'Remove entry 1' }));
        expect(lastData()['items']).toEqual([{ title: 'B' }]);
        expect(screen.queryByRole('button', { name: /Remove entry/ })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add item' })).toHaveFocus();
    });

    it('focuses the add button after removing a row from a full repeater', async () => {
        const { user, lastData } = renderForm(orderForm, {
            items: [{ title: 'A' }, { title: 'B' }, { title: 'C' }],
        });
        const add = screen.getByRole('button', { name: 'Add item' });
        expect(add).toBeDisabled();
        await user.click(screen.getByRole('button', { name: 'Remove entry 2' }));
        expect(lastData()['items']).toEqual([{ title: 'A' }, { title: 'C' }]);
        expect(add).toBeEnabled();
        expect(add).toHaveFocus();
    });

    it('computes row-level calculations and aggregates live', async () => {
        const { user } = renderForm(orderForm, {
            items: [{ title: 'A', qty: '2', price: '1.50' }],
        });
        expect(plain(screen.getByRole('status', { name: 'Sum' }).textContent)).toBe('€3.00');
        await user.click(screen.getByRole('button', { name: 'Add item' }));
        await waitFor(() => {
            expect(screen.getAllByLabelText(/Title/)[1]).toHaveFocus();
        });
        const qty = screen.getAllByLabelText('Qty')[1]!;
        await user.type(qty, '4');
        await user.type(screen.getAllByLabelText('Unit price')[1]!, '0.25');
        expect(plain(screen.getAllByRole('status', { name: 'Line total' })[1]!.textContent)).toBe(
            '€1.00',
        );
        expect(plain(screen.getByRole('status', { name: 'Sum' }).textContent)).toBe('€4.00');
    });

    it('shows min_items at the repeater and an empty state', () => {
        renderForm(orderForm, {}, { showErrors: true });
        expect(screen.getByText('No entries yet.')).toBeInTheDocument();
        const group = screen.getByRole('group', { name: 'Items' });
        expect(within(group).getByText('Add at least 1 entries.')).toBeInTheDocument();
    });

    it('shows row defaults and materializes them on the next edit', async () => {
        const form = definition([
            page('p', [
                el({
                    type: 'repeater',
                    key: 'rows',
                    label: 'Rows',
                    elements: [
                        el({ type: 'text', key: 'unit', label: 'Unit', default: 'pcs' }),
                        el({ type: 'text', key: 'v', label: 'Value' }),
                    ],
                }),
            ]),
        ]);
        const { user, lastData } = renderForm(form, { rows: [{}] });
        expect(screen.getByLabelText('Unit')).toHaveValue('pcs');
        await user.type(screen.getByLabelText('Value'), 'x');
        expect(lastData()['rows']).toEqual([{ unit: 'pcs', v: 'x' }]);
    });

    it('hides add and remove buttons when read-only', () => {
        renderForm(orderForm, { items: [{ title: 'A' }, { title: 'B' }] }, { readOnly: true });
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
        expect(screen.getAllByLabelText(/Title/)[0]).toBeDisabled();
    });
});

describe('repeater table', () => {
    it('renders a table with headers and hidden cell labels', async () => {
        const { user, lastData } = renderForm(tableForm(), { lines: [{ item: 'X', amount: '1' }] });
        const table = screen.getByRole('table');
        expect(within(table).getByRole('columnheader', { name: 'Item' })).toBeInTheDocument();
        expect(within(table).getByRole('columnheader', { name: 'Amount' })).toBeInTheDocument();
        expect(within(table).queryByText('ignored in table')).not.toBeInTheDocument();
        await user.type(within(table).getByLabelText('Amount'), '5');
        expect(lastData()['lines']).toEqual([{ item: 'X', amount: '15' }]);
        await user.click(screen.getByRole('button', { name: 'Remove entry 1' }));
        expect(lastData()['lines']).toEqual([]);
    });

    it('virtualizes long tables', () => {
        const lines = Array.from({ length: 200 }, (_, index) => ({ item: `Row ${String(index)}` }));
        renderForm(tableForm(200), { lines });
        const rendered = screen.getAllByLabelText('Item');
        expect(rendered.length).toBeGreaterThan(0);
        expect(rendered.length).toBeLessThan(200);
    });
});

describe('virtualized cards', () => {
    it('renders only a window of 200 rows', () => {
        const items = Array.from({ length: 200 }, (_, index) => ({ title: `T${String(index)}` }));
        const form = definition([
            page('p', [
                el({
                    type: 'repeater',
                    key: 'items',
                    label: 'Items',
                    elements: [el({ type: 'text', key: 'title', label: 'Title' })],
                }),
            ]),
        ]);
        renderForm(form, { items });
        const rendered = screen.getAllByLabelText('Title');
        expect(rendered.length).toBeGreaterThan(0);
        expect(rendered.length).toBeLessThan(200);
        expect(rendered[0]).toHaveValue('T0');
    });

    it('scrolls a far row into view when its error is chosen in the summary', async () => {
        const items = Array.from({ length: 200 }, (_, index) => ({
            title: index === 150 ? '' : 'ok',
        }));
        const form = definition([
            page('p', [
                el({
                    type: 'repeater',
                    key: 'items',
                    label: 'Items',
                    elements: [el({ type: 'text', key: 'title', label: 'Title', required: true })],
                }),
            ]),
        ]);
        const { user } = renderForm(form, { items }, { showErrors: true });
        const scrollTo = vi.spyOn(window, 'scrollTo').mockImplementation(() => undefined);
        await user.click(screen.getByRole('link', { name: /Entry 151/ }));
        expect(scrollTo).toHaveBeenCalled();
    });
});
