import type { FormRendererProps } from '@fieldset/fieldset-react';
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactElement } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { PreviewApp } from '../src/preview/PreviewApp.tsx';
import {
    isPreviewReady,
    parsePreviewMessage,
    PREVIEW_MESSAGE,
    PREVIEW_READY,
    PREVIEW_SENDER_ORIGIN,
    PREVIEW_TARGET_ORIGIN,
} from '../src/preview/protocol.ts';
import { sampleContext } from '../src/preview/sample-context.ts';
import { previewTexts } from '../src/preview/texts.ts';

vi.mock('@fieldset/fieldset-react', async (importActual) => {
    const actual = await importActual<typeof import('@fieldset/fieldset-react')>();
    function FakeRenderer(props: FormRendererProps): ReactElement {
        const definition = props.definition as { title: string };
        if (definition.title === 'boom') {
            throw new Error('broken definition');
        }
        return (
            <div>
                <p data-testid="renderer-title">{definition.title}</p>
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

/** In jsdom the preview is its own parent: messages from the builder come from `window`. */
function send(data: unknown, origin = ORIGIN, source: MessageEventSource | null = window): void {
    act(() => {
        window.dispatchEvent(new MessageEvent('message', { data, origin, source }));
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
        expect(
            screen.getByRole('heading', { level: 1, name: 'Service report' }),
        ).toBeInTheDocument();
        expect(screen.getByTestId('renderer-title')).toHaveTextContent('Service report');
        expect(screen.getByTestId('mode')).toHaveTextContent('preview');
        expect(screen.getByTestId('user')).toHaveTextContent('Max Mustermann');
        expect(document.documentElement.lang).toBe('en-GB');
    });

    it('ignores messages from other origins and of other types', () => {
        setup();
        send({ type: PREVIEW_MESSAGE, definition: definition('Evil') }, 'https://evil.example');
        send({ type: 'something-else', definition: definition('Other') });
        send('plain string');
        send({ type: PREVIEW_MESSAGE, definition: definition('Sibling') }, ORIGIN, null);
        send(
            { type: PREVIEW_MESSAGE, definition: definition('Port') },
            ORIGIN,
            new MessageChannel().port1,
        );
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
                source: window,
                data: {
                    type: PREVIEW_MESSAGE,
                    definition: definition('X'),
                    data: { a: 'b' },
                    context: { today: '2026-01-02' },
                },
            },
            ORIGIN,
            window,
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
            {
                origin: ORIGIN,
                source: window,
                data: { type: PREVIEW_MESSAGE, definition: definition('X'), data: [] },
            },
            ORIGIN,
            window,
        );
        expect(result).toEqual({ kind: 'update', update: { definition: definition('X') } });
    });

    it('ignores same-origin messages that do not come from the parent window', () => {
        const message = { type: PREVIEW_MESSAGE, definition: definition('X') };
        const other = new MessageChannel().port1;
        expect(
            parsePreviewMessage({ origin: ORIGIN, source: other, data: message }, ORIGIN, window),
        ).toEqual({ kind: 'ignore' });
        expect(
            parsePreviewMessage({ origin: ORIGIN, source: null, data: message }, ORIGIN, window),
        ).toEqual({ kind: 'ignore' });
    });
});

describe('opaque-origin contract (ADR 0040)', () => {
    const frameWindow = new MessageChannel().port1;
    const readyMessage = { type: PREVIEW_READY };

    it('names the target and sender origins of a sandboxed preview', () => {
        expect(PREVIEW_TARGET_ORIGIN).toBe('*');
        expect(PREVIEW_SENDER_ORIGIN).toBe('null');
    });

    it('lets the builder trust a ready message only from its iframe window with the opaque origin', () => {
        expect(
            isPreviewReady(
                { source: frameWindow, origin: 'null', data: readyMessage },
                frameWindow,
            ),
        ).toBe(true);
    });

    it('refuses ready messages from an unsandboxed preview, other windows or of other types', () => {
        const other = new MessageChannel().port1;
        const refused: Pick<MessageEvent, 'origin' | 'data' | 'source'>[] = [
            { source: frameWindow, origin: ORIGIN, data: readyMessage },
            { source: other, origin: 'null', data: readyMessage },
            { source: null, origin: 'null', data: readyMessage },
            { source: frameWindow, origin: 'null', data: { type: PREVIEW_MESSAGE } },
            { source: frameWindow, origin: 'null', data: PREVIEW_READY },
        ];
        for (const event of refused) {
            expect(isPreviewReady(event, frameWindow)).toBe(false);
        }
        expect(isPreviewReady({ source: null, origin: 'null', data: readyMessage }, null)).toBe(
            false,
        );
    });

    it('still accepts updates from the builder origin inside the sandbox (location.origin)', () => {
        const message = { type: PREVIEW_MESSAGE, definition: definition('X') };
        expect(
            parsePreviewMessage({ origin: ORIGIN, source: window, data: message }, ORIGIN, window)
                .kind,
        ).toBe('update');
        expect(
            parsePreviewMessage({ origin: 'null', source: window, data: message }, ORIGIN, window)
                .kind,
        ).toBe('ignore');
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
