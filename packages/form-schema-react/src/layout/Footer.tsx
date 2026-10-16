/** Sticky footer holding the screen's primary action. */
import type { ReactElement, ReactNode } from 'react';
import type { PrimaryAction } from '../types.ts';

export function Footer({ children }: { readonly children: ReactNode }): ReactElement {
    return <div className="fsr-footer">{children}</div>;
}

export function PrimaryButton({ action }: { readonly action: PrimaryAction }): ReactElement {
    const busy = action.busy === true;
    return (
        <button
            type="button"
            className="fsr-button fsr-button--primary fsr-footer__primary"
            disabled={action.disabled === true || busy}
            aria-busy={busy ? true : undefined}
            onClick={action.onClick}
        >
            {action.label}
        </button>
    );
}
