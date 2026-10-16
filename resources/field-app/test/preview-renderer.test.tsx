import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { PreviewApp } from '../src/preview/PreviewApp.tsx';
import { PREVIEW_MESSAGE } from '../src/preview/protocol.ts';

/** Integration with the real renderer (no mocks): what the builder iframe shows. */
const definition = {
    specVersion: '1.0.0',
    key: 'service_report',
    title: 'Service report',
    locale: 'de-DE',
    pages: [
        {
            key: 'main',
            title: 'Auftrag',
            elements: [
                {
                    id: '00000000-0000-4000-8000-000000000001',
                    type: 'text',
                    key: 'customer',
                    label: 'Kunde',
                    required: true,
                },
                {
                    id: '00000000-0000-4000-8000-000000000002',
                    type: 'number',
                    key: 'hours',
                    label: 'Stunden',
                },
                {
                    id: '00000000-0000-4000-8000-000000000003',
                    type: 'calculated',
                    key: 'labour',
                    label: 'Arbeitskosten',
                    format: 'money',
                    currency: 'EUR',
                    expression: { 'dec.mul': [{ var: 'hours' }, '80.00'] },
                },
                {
                    id: '00000000-0000-4000-8000-000000000004',
                    type: 'text',
                    key: 'by',
                    label: 'Erfasst von',
                    default: { var: '$user.name' },
                },
            ],
        },
    ],
};

function send(message: unknown): void {
    act(() => {
        window.dispatchEvent(
            new MessageEvent('message', { data: message, origin: window.location.origin }),
        );
    });
}

describe('PreviewApp with the real renderer', () => {
    it('renders the definition in preview mode with live calculations and sample context', async () => {
        render(<PreviewApp target={{ postMessage: vi.fn() }} />);
        send({ type: PREVIEW_MESSAGE, definition });

        expect(screen.getByText('Vorschau – Eingaben werden nicht gespeichert.')).toBeVisible();
        expect(screen.getByLabelText(/Erfasst von/)).toHaveValue('Max Mustermann');

        await userEvent.type(screen.getByLabelText(/Stunden/), '1,5');
        const output = document.querySelector('output');
        expect(output?.textContent.replace(/\s/g, ' ')).toBe('120,00 €');
    });

    it('shows the error summary when the designer checks the entries', async () => {
        render(<PreviewApp target={{ postMessage: vi.fn() }} />);
        send({ type: PREVIEW_MESSAGE, definition });

        await userEvent.click(screen.getByRole('button', { name: 'Eingaben prüfen' }));
        const summary = screen.getByRole('alert');
        expect(within(summary).getByRole('link', { name: /Kunde/ })).toBeInTheDocument();
        expect(screen.getByLabelText(/Kunde/)).toHaveAttribute('aria-invalid', 'true');
    });
});
