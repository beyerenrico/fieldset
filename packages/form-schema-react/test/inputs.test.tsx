import { fireEvent, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { definition, el, inputsForm, page } from './fixtures/definitions.ts';
import { plain, renderForm } from './helpers.tsx';

describe('text inputs', () => {
    it('edits text, textarea, email and phone with the right input types', async () => {
        const { user, lastData } = renderForm(inputsForm);
        const name = screen.getByLabelText('Name');
        expect(name).toHaveAttribute('placeholder', 'Jane');
        expect(name).toHaveAccessibleDescription('Your full name');
        await user.type(name, 'Ann');
        await user.type(screen.getByLabelText('Notes'), 'Line');
        const mail = screen.getByLabelText('Email');
        expect(mail).toHaveAttribute('type', 'email');
        await user.type(mail, 'a@b.de');
        const tel = screen.getByLabelText('Phone');
        expect(tel).toHaveAttribute('type', 'tel');
        expect(tel).toHaveAttribute('inputmode', 'tel');
        await user.type(tel, '+49 1');
        expect(screen.getByLabelText('Notes').tagName).toBe('TEXTAREA');
        expect(screen.getByLabelText('Notes')).toHaveAttribute('rows', '3');
        expect(lastData()).toMatchObject({
            name: 'Ann',
            notes: 'Line',
            mail: 'a@b.de',
            tel: '+49 1',
        });
        await user.clear(name);
        expect(lastData()['name']).toBe('');
    });
});

describe('number and money', () => {
    it('keeps decimal strings and uses inputmode decimal', async () => {
        const { user, lastData } = renderForm(inputsForm);
        const qty = screen.getByLabelText('Quantity');
        expect(qty).toHaveAttribute('inputmode', 'decimal');
        await user.type(qty, '007');
        expect(lastData()['qty']).toBe('7');
        await user.clear(qty);
        expect(lastData()['qty']).toBeNull();
        await user.type(qty, '1x');
        expect(lastData()['qty']).toBe('1x');
    });

    it('accepts a comma in de-DE and normalizes money to its scale on blur', async () => {
        const form = definition(
            [page('p', [el({ type: 'money', key: 'price', label: 'Preis', currency: 'EUR' })])],
            {
                locale: 'de-DE',
            },
        );
        const { user, lastData } = renderForm(form);
        const price = screen.getByLabelText('Preis');
        expect(screen.getByText('€')).toBeInTheDocument();
        await user.type(price, '1,5');
        expect(lastData()['price']).toBe('1.5');
        await user.tab();
        expect(lastData()['price']).toBe('1.50');
        expect(price).toHaveValue('1,50');
    });

    it('shows the currency symbol before the amount in en-US', () => {
        renderForm(inputsForm);
        const affix = screen.getByLabelText('Price').closest('.fsr-affix');
        expect(affix).toHaveClass('fsr-affix--before');
    });
});

describe('date and time inputs', () => {
    it('edits date and time natively', () => {
        const { lastData } = renderForm(inputsForm, { at: '08:15:30' });
        fireEvent.change(screen.getByLabelText('Day'), { target: { value: '2026-10-16' } });
        expect(lastData()['day']).toBe('2026-10-16');
        const time = screen.getByLabelText('Time');
        expect(time).toHaveAttribute('step', '1');
        fireEvent.change(time, { target: { value: '09:41' } });
        expect(lastData()['at']).toBe('09:41');
        fireEvent.change(screen.getByLabelText('Day'), { target: { value: '' } });
        expect(lastData()['day']).toBeNull();
    });

    it('stores datetime with the device offset and shows local wall time', () => {
        const { lastData } = renderForm(inputsForm, { when: '2026-10-16T07:41:00Z' });
        const when = screen.getByLabelText('When');
        expect(when).toHaveValue('2026-10-16T09:41');
        fireEvent.change(when, { target: { value: '2026-01-10T08:00' } });
        expect(lastData()['when']).toBe('2026-01-10T08:00:00+01:00');
        fireEvent.change(when, { target: { value: '' } });
        expect(lastData()['when']).toBeNull();
    });
});

describe('choices', () => {
    it('edits select, radio and multiselect', async () => {
        const { user, lastData } = renderForm(inputsForm);
        await user.selectOptions(screen.getByLabelText('Choice'), 'b');
        expect(lastData()['choice']).toBe('b');
        await user.selectOptions(screen.getByLabelText('Choice'), '');
        expect(lastData()['choice']).toBeNull();
        const pick = screen.getByRole('group', { name: 'Pick' });
        await user.click(within(pick).getByLabelText('Gamma'));
        expect(lastData()['pick']).toBe('c');
        const many = screen.getByRole('group', { name: 'Many' });
        await user.click(within(many).getByLabelText('Gamma'));
        await user.click(within(many).getByLabelText('Alpha'));
        expect(lastData()['many']).toEqual(['a', 'c']);
        await user.click(within(many).getByLabelText('Alpha'));
        await user.click(within(many).getByLabelText('Gamma'));
        expect(lastData()['many']).toBeNull();
    });
});

describe('booleans and rating', () => {
    it('edits checkbox, toggle and rating', async () => {
        const { user, lastData } = renderForm(inputsForm);
        await user.click(screen.getByLabelText('I agree'));
        expect(lastData()['agree']).toBe(true);
        const toggle = screen.getByRole('switch', { name: 'Urgent' });
        await user.click(toggle);
        expect(lastData()['urgent']).toBe(true);
        await user.click(toggle);
        expect(lastData()['urgent']).toBe(false);
        const rating = screen.getByRole('group', { name: 'Rating' });
        expect(within(rating).getAllByRole('radio')).toHaveLength(4);
        await user.click(within(rating).getByRole('radio', { name: '3 of 4' }));
        expect(lastData()['stars']).toBe(3);
    });
});

describe('hidden and calculated', () => {
    it('does not render hidden elements but keeps their default', async () => {
        const { user, lastData } = renderForm(inputsForm);
        expect(screen.queryByLabelText('Secret')).not.toBeInTheDocument();
        await user.type(screen.getByLabelText('Name'), 'A');
        expect(lastData()['secret']).toBe('x');
    });

    it('updates calculated money live with Intl formatting', async () => {
        const form = definition(
            [
                page('p', [
                    el({ type: 'number', key: 'qty', label: 'Menge' }),
                    el({ type: 'money', key: 'price', label: 'Preis', currency: 'EUR' }),
                    el({
                        type: 'calculated',
                        key: 'total',
                        label: 'Summe',
                        format: 'money',
                        currency: 'EUR',
                        expression: { 'dec.mul': [{ var: 'qty' }, { var: 'price' }] },
                    }),
                    el({
                        type: 'calculated',
                        key: 'ratio',
                        label: 'Anteil',
                        scale: 3,
                        expression: { 'dec.div': [{ var: 'qty' }, '3', 3] },
                    }),
                    el({
                        type: 'calculated',
                        key: 'label',
                        label: 'Text',
                        format: 'text',
                        expression: { cat: ['n=', { var: 'qty' }] },
                    }),
                ]),
            ],
            { locale: 'de-DE' },
        );
        const { user } = renderForm(form);
        const total = screen.getByRole('status', { name: 'Summe' });
        expect(total).toHaveTextContent('—');
        await user.type(screen.getByLabelText('Menge'), '1000');
        await user.type(screen.getByLabelText('Preis'), '1,2345');
        expect(screen.getByLabelText('Preis')).not.toHaveAttribute('aria-invalid');
        await user.tab();
        expect(screen.getByText('Höchstens 2 Nachkommastellen verwenden.')).toBeInTheDocument();
        expect(screen.getByLabelText('Preis')).toHaveAttribute('aria-invalid', 'true');
        await user.clear(screen.getByLabelText('Preis'));
        await user.type(screen.getByLabelText('Preis'), '1,23');
        expect(plain(total.textContent)).toBe('1.230,00 €');
        expect(screen.getByRole('status', { name: 'Anteil' })).toHaveTextContent('333,333');
        expect(screen.getByRole('status', { name: 'Text' })).toHaveTextContent('n=1000');
    });
});
