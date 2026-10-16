/**
 * Modal dialog (WAI-ARIA APG dialog pattern; jsdom lacks `<dialog>.showModal()`): labelled,
 * focus moves in and is trapped, Escape closes, the rest of the page is inert, and focus returns
 * to the element that opened it.
 */
import { useEffect, useId, useRef, type KeyboardEvent, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { cn } from '../../lib/cn.ts';

const FOCUSABLE = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

export interface DialogProps {
    readonly open: boolean;
    readonly onClose: () => void;
    readonly title: string;
    readonly description?: string;
    readonly children: ReactNode;
    readonly className?: string;
}

function focusables(root: HTMLElement): HTMLElement[] {
    return Array.from(root.querySelectorAll<HTMLElement>(FOCUSABLE));
}

/** Marks every other top-level node inert (pointer, focus and AT); returns the undo. */
function makeOthersInert(keep: HTMLElement): () => void {
    const changed = Array.from(document.body.children).filter(
        (node) => node !== keep && !node.hasAttribute('inert'),
    );
    for (const node of changed) {
        node.setAttribute('inert', '');
    }
    return () => {
        for (const node of changed) {
            node.removeAttribute('inert');
        }
    };
}

/** Tab / Shift+Tab wrap around inside the dialog. */
function trapTab(event: KeyboardEvent<HTMLDivElement>, panel: HTMLElement): void {
    const items = focusables(panel);
    const first = items[0];
    const last = items.at(-1);
    if (first === undefined || last === undefined) {
        event.preventDefault();
        panel.focus();
        return;
    }
    const active = document.activeElement;
    if (event.shiftKey && (active === first || active === panel)) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
    }
}

export function Dialog({
    open,
    onClose,
    title,
    description,
    children,
    className,
}: DialogProps): ReactNode {
    const titleId = useId();
    const descriptionId = useId();
    const layer = useRef<HTMLDivElement>(null);
    const panel = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open || layer.current === null || panel.current === null) {
            return undefined;
        }
        const opener =
            document.activeElement instanceof HTMLElement ? document.activeElement : null;
        const restoreInert = makeOthersInert(layer.current);
        (focusables(panel.current)[0] ?? panel.current).focus();
        return () => {
            restoreInert();
            opener?.focus();
        };
    }, [open]);

    if (!open) {
        return null;
    }
    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>): void => {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            onClose();
        } else if (event.key === 'Tab' && panel.current !== null) {
            trapTab(event, panel.current);
        }
    };
    return createPortal(
        <div
            ref={layer}
            className="fixed inset-0 z-50 flex items-end justify-center bg-black/60 p-4 sm:items-center"
        >
            <div
                ref={panel}
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                aria-describedby={description === undefined ? undefined : descriptionId}
                tabIndex={-1}
                onKeyDown={onKeyDown}
                className={cn(
                    'max-h-[90dvh] w-full max-w-md overflow-y-auto rounded-lg border-2 border-border bg-card p-4 text-card-foreground shadow-lg outline-none sm:p-6',
                    className,
                )}
            >
                <h2 id={titleId} className="text-xl font-bold">
                    {title}
                </h2>
                {description === undefined ? null : (
                    <p id={descriptionId} className="mt-1 text-muted-foreground">
                        {description}
                    </p>
                )}
                <div className="mt-4">{children}</div>
            </div>
        </div>,
        document.body,
    );
}
