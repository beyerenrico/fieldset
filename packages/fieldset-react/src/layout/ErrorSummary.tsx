/** Error summary with links that reveal and focus the fields. */
import { useEffect, useRef, type ReactElement } from 'react';
import { useRenderer } from '../context.tsx';
import { pointerId } from '../data/path.ts';
import { errorMessage } from '../i18n/errors.ts';
import { interpolate } from '../i18n/messages.ts';
import type { ErrorEntry } from '../state/errors.ts';

export interface ErrorSummaryProps {
    readonly entries: readonly ErrorEntry[];
    /** Changes whenever the summary should take focus again. */
    readonly focusKey: number;
}

export function ErrorSummary({ entries, focusKey }: ErrorSummaryProps): ReactElement | null {
    const context = useRenderer();
    const { messages, idBase } = context;
    const box = useRef<HTMLDivElement>(null);
    const visible = entries.length > 0;

    useEffect(() => {
        if (visible && focusKey > 0) {
            box.current?.focus();
        }
    }, [focusKey, visible]);

    if (!visible) {
        return null;
    }
    const title =
        entries.length === 1
            ? messages.errorSummaryTitleOne
            : interpolate(messages.errorSummaryTitle, { count: entries.length });
    return (
        <div
            ref={box}
            className="fsr-summary"
            role="alert"
            tabIndex={-1}
            aria-labelledby={`${idBase}-summary-title`}
        >
            <h2 id={`${idBase}-summary-title`} className="fsr-summary__title">
                {title}
            </h2>
            <ul className="fsr-summary__list">
                {entries.map((entry) => {
                    const id = pointerId(idBase, entry.pointer);
                    return (
                        <li key={entry.pointer}>
                            <a
                                href={`#${id}`}
                                onClick={(event) => {
                                    event.preventDefault();
                                    context.reveal.reveal(entry.pointer, id);
                                }}
                            >
                                {entry.label}: {errorMessage(entry.error, messages, context.locale)}
                            </a>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
