/** Scroll layout: every visible page on one long screen. */
import type { ReactElement } from 'react';
import { useRenderer } from '../context.tsx';
import type { ErrorEntry } from '../state/errors.ts';
import type { PrimaryAction } from '../types.ts';
import { ErrorSummary } from './ErrorSummary.tsx';
import { Footer, PrimaryButton } from './Footer.tsx';
import { PageView } from './PageView.tsx';

export interface ScrollLayoutProps {
    readonly pages: readonly number[];
    readonly summary: readonly ErrorEntry[];
    readonly summaryFocusKey: number;
    readonly primaryAction: PrimaryAction | undefined;
}

export function ScrollLayout({
    pages,
    summary,
    summaryFocusKey,
    primaryAction,
}: ScrollLayoutProps): ReactElement {
    const { model, idBase } = useRenderer();
    return (
        <>
            <ErrorSummary entries={summary} focusKey={summaryFocusKey} />
            {pages.map((index) => {
                const page = model.pages[index];
                return page === undefined ? null : (
                    <PageView key={page.path} page={page} idBase={idBase} />
                );
            })}
            {primaryAction !== undefined && (
                <Footer>
                    <PrimaryButton action={primaryAction} />
                </Footer>
            )}
        </>
    );
}
