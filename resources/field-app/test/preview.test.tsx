import type { FormRendererProps } from '@form-schema-app/form-schema-react';
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactElement } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { PreviewApp } from '../src/preview/PreviewApp.tsx';
import { parsePreviewMessage, PREVIEW_MESSAGE, PREVIEW_READY } from '../src/preview/protocol.ts';
import { sampleContext } from '../src/preview/sample-context.ts';
import { previewTexts } from '../src/preview/texts.ts';

vi.mock('@form-schema-app/form-schema-react', async (importActual) => {
    const actual = await importActual<typeof import('@form-schema-app/form-schema-react')>();
    function FakeRenderer(props: FormRendererProps): ReactElement {
        const definition = props.definition as { title: string };
        if (definition.title === 'boom') {
            throw new Error('broken definition');
        }
        return (
            <div>
                <h1>{definition.title}</h1>
                <p data-testid="mode">{props.mode}</p>
                <p data-testid="data">{JSON.stringify(props.data)}</p>
                <p data-testid="errors">{String(props.showErrors)}</p>
                <p data-testid="user">{props.context?.user?.name}</p>
                <button
                    type="button"
                    onClick={() => props.onChange?.({ note: 'typed' }, undefined as never)}
                >
                    type
                </button>
                <button type="button" onClick={props.primaryAction?.onClick}>
                    {props.primaryAction?.label}
                </button>
            </div>
        );
    }
    return { ...actual, FormRenderer: FakeRenderer };
});

const ORIGIN = window.location.origin;

function definition(title: string, locale = 'en-GB'): Record<string, unknown> {
    return { specVersion: '1.0.0', key: 'demo', title, locale, pages: [] };
}

function send(data: unknown, origin = ORIGIN): void {
    act(() => {
        window.dispatchEvent(new MessageEvent('message', { data, origin }));
    });
}

function setup(): { postMessage: ReturnType<typeof vi.fn> } {
    const postMessage = vi.fn();
    render(<PreviewApp target={{ postMessage }} origin={ORIGIN} />);
    return { postMessage };
}

describe('PreviewApp', () => {
    it('announces readiness to the parent with its own origin as target', () => {
        const { postMessage } = setup();
        expect(postMessage).toHaveBeenCalledWith({ type: PREVIEW_READY }, ORIGIN);
        expect(screen.getByRole('status')).toHaveTextContent(previewTexts('en').waiting);
    });

    it('renders a definition sent from its own origin in preview mode', () => {
        setup();
        send({ type: PREVIEW_MESSAGE, definition: definition('Service report') });
        expect(screen.getByRole('heading', { name: 'Service report' })).toBeInTheDocument();
        expect(screen.getByTestId('mode')).toHaveTextContent('preview');
        expect(screen.getByTestId('user')).toHaveTextContent('Max Mustermann');
        expect(document.documentElement.lang).toBe('en-GB');
    });

    it('ignores messages from other origins and of other types', () => {
        setup();
        send({ type: PREVIEW_MESSAGE, definition: definition('Evil') }, 'https://evil.example');
        send({ type: 'something-else', definition: definition('Other') });
        send('plain string');
        expect(screen.queryByRole('heading')).not.toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent(previewTexts('en').waiting);
    });

    it('reports an invalid definition and recovers with the next valid one', () => {
        setup();
        send({ type: PREVIEW_MESSAGE, definition: { title: 'no pages' } });
        expect(screen.getByRole('status')).toHaveTextContent(previewTexts('en').invalid);
        send({ type: PREVIEW_MESSAGE, definition: definition('Fixed') });
        expect(screen.getByRole('heading', { name: 'Fixed' })).toBeInTheDocument();
    });

    it('keeps entered data across definition updates unless data is sent', async () => {
        setup();
        send({ type: PREVIEW_MESSAGE, definition: definition('A'), data: { note: 'given' } });
        expect(screen.getByTestId('data')).toHaveTextContent('{"note":"given"}');
        await userEvent.click(screen.getByRole('button', { name: 'type' }));
        expect(screen.getByTestId('data')).toHaveTextContent('{"note":"typed"}');
        send({ type: PREVIEW_MESSAGE, definition: definition('B') });
        expect(screen.getByRole('heading', { name: 'B' })).toBeInTheDocument();
        expect(screen.getByTestId('data')).toHaveTextContent('{"note":"typed"}');
    });

    it('shows errors on "check" and clears entries on reset (German texts)', async () => {
        setup();
        const de = previewTexts('de-DE');
        send({ type: PREVIEW_MESSAGE, definition: definition('Bericht', 'de-DE'), data: { a: 1 } });
        expect(screen.getByTestId('errors')).toHaveTextContent('false');
        await userEvent.click(screen.getByRole('button', { name: de.check }));
        expect(screen.getByTestId('errors')).toHaveTextContent('true');
        await userEvent.click(screen.getByRole('button', { name: de.reset }));
        expect(screen.getByTestId('errors')).toHaveTextContent('false');
        expect(screen.getByTestId('data')).toHaveTextContent('{}');
    });

    it('survives a definition that crashes the renderer', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
        const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        setup();
        send({ type: PREVIEW_MESSAGE, definition: definition('boom') });
        expect(screen.getByRole('alert')).toHaveTextContent(previewTexts('en').invalid);
        send({ type: PREVIEW_MESSAGE, definition: definition('Back again') });
        expect(screen.getByRole('heading', { name: 'Back again' })).toBeInTheDocument();
        warn.mockRestore();
        error.mockRestore();
    });
});

describe('parsePreviewMessage', () => {
    it('passes data and context through', () => {
        const result = parsePreviewMessage(
            {
                origin: ORIGIN,
                data: {
                    type: PREVIEW_MESSAGE,
                    definition: definition('X'),
                    data: { a: 'b' },
                    context: { today: '2026-01-02' },
                },
            },
            ORIGIN,
        );
        expect(result).toEqual({
            kind: 'update',
            update: {
                definition: definition('X'),
                data: { a: 'b' },
                context: { today: '2026-01-02' },
            },
        });
    });

    it('ignores non-object data and context', () => {
        const result = parsePreviewMessage(
            { origin: ORIGIN, data: { type: PREVIEW_MESSAGE, definition: definition('X'), data: [] } },
            ORIGIN,
        );
        expect(result).toEqual({ kind: 'update', update: { definition: definition('X') } });
    });
});

describe('sampleContext', () => {
    it('formats today and now as RFC 3339 in the device time zone', () => {
        const context = sampleContext(new Date(2026, 9, 16, 9, 41, 5));
        expect(context.today).toBe('2026-10-16');
        expect(context.now).toMatch(/^2026-10-16T09:41:05[+-]\d{2}:\d{2}$/);
        expect(context.formVersion).toBe(1);
    });
});
