/** Wizard layout: one visible page at a time with progress, Back/Next and the primary action. */
import { useEffect, useRef, useState, type ReactElement } from 'react';
import { useRenderer } from '../context.tsx';
import { interpolate } from '../i18n/messages.ts';
import type { ErrorEntry } from '../state/errors.ts';
import type { PrimaryAction } from '../types.ts';
import { ErrorSummary } from './ErrorSummary.tsx';
import { Footer, PrimaryButton } from './Footer.tsx';
import { PageView } from './PageView.tsx';

export interface WizardLayoutProps {
    readonly pages: readonly number[];
    readonly entries: readonly ErrorEntry[];
    readonly showAll: boolean;
    readonly summaryFocusKey: number;
    readonly primaryAction: PrimaryAction | undefined;
    readonly pageOf: ReadonlyMap<string, number>;
    readonly pagePointers: (page: number) => string[];
    readonly touchAll: (pointers: Iterable<string>) => void;
    readonly onPageChange: ((index: number, count: number) => void) | undefined;
}

/** The current page, or the nearest visible one when it disappeared. */
function resolveActive(pages: readonly number[], current: number): number | undefined {
    if (pages.includes(current)) {
        return current;
    }
    return pages.find((index) => index > current) ?? pages[pages.length - 1];
}

export function WizardLayout(props: WizardLayoutProps): ReactElement {
    const { pages, entries, showAll, primaryAction, pageOf } = props;
    const { model, messages, idBase, reveal } = useRenderer();
    const [current, setCurrent] = useState(pages[0] ?? 0);
    const [blocked, setBlocked] = useState<number | null>(null);
    const [focusKey, setFocusKey] = useState(0);
    const heading = useRef<HTMLHeadingElement>(null);
    const previous = useRef<number | undefined>(undefined);
    const active = resolveActive(pages, current);
    const position = active === undefined ? 0 : pages.indexOf(active);
    const { onPageChange } = props;

    useEffect(() => {
        if (previous.current !== undefined && previous.current !== active) {
            heading.current?.focus();
        }
        previous.current = active;
    }, [active]);

    useEffect(() => {
        onPageChange?.(position, pages.length);
    }, [position, pages.length, onPageChange]);

    useEffect(
        () =>
            reveal.register((pointer) => {
                const page = pageOf.get(pointer);
                if (page === undefined || page === active || !pages.includes(page)) {
                    return false;
                }
                setCurrent(page);
                return true;
            }),
        [reveal, pageOf, active, pages],
    );

    const goTo = (index: number | undefined): void => {
        if (index !== undefined) {
            setCurrent(index);
            setBlocked(null);
        }
    };
    const next = (): void => {
        if (active === undefined) {
            return;
        }
        if (entries.some((entry) => entry.page === active)) {
            props.touchAll(props.pagePointers(active));
            setBlocked(active);
            setFocusKey((key) => key + 1);
            return;
        }
        goTo(pages[position + 1]);
    };

    const summary = showAll
        ? entries
        : blocked === active
          ? entries.filter((entry) => entry.page === active)
          : [];
    const page = active === undefined ? undefined : model.pages[active];
    const last = position >= pages.length - 1;
    const stepText = interpolate(messages.stepOf, {
        current: position + 1,
        total: Math.max(pages.length, 1),
    });
    return (
        <>
            <div className="fsr-progress">
                <p id={`${idBase}-step`} className="fsr-progress__text">
                    {stepText}
                </p>
                <progress
                    className="fsr-progress__bar"
                    max={Math.max(pages.length, 1)}
                    value={position + 1}
                    aria-labelledby={`${idBase}-step`}
                />
            </div>
            <ErrorSummary
                entries={summary}
                focusKey={showAll ? props.summaryFocusKey + focusKey : focusKey}
            />
            {page !== undefined && (
                <PageView key={page.path} page={page} headingRef={heading} idBase={idBase} />
            )}
            <Footer>
                {position > 0 && (
                    <button
                        type="button"
                        className="fsr-button fsr-button--secondary"
                        onClick={() => {
                            goTo(pages[position - 1]);
                        }}
                    >
                        {messages.back}
                    </button>
                )}
                {!last && (
                    <button
                        type="button"
                        className="fsr-button fsr-button--primary fsr-footer__primary"
                        onClick={next}
                    >
                        {messages.next}
                    </button>
                )}
                {last && primaryAction !== undefined && <PrimaryButton action={primaryAction} />}
            </Footer>
        </>
    );
}
