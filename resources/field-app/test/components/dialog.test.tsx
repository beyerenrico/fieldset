import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState, type ReactNode } from 'react';
import { describe, expect, it } from 'vitest';
import { Dialog } from '../../src/components/ui/dialog.tsx';

function Harness({ children }: { readonly children?: ReactNode }): ReactNode {
    const [open, setOpen] = useState(false);
    return (
        <>
            <button
                type="button"
                onClick={() => {
                    setOpen(true);
                }}
            >
                Open
            </button>
            <Dialog
                open={open}
                onClose={() => {
                    setOpen(false);
                }}
                title="Plain dialog"
            >
                {children}
            </Dialog>
        </>
    );
}

describe('Dialog', () => {
    it('focuses the panel when it has no focusable content and keeps Tab inside', async () => {
        const user = userEvent.setup();
        render(
            <Harness>
                <p>Only text</p>
            </Harness>,
        );
        await user.click(screen.getByRole('button', { name: 'Open' }));
        const dialog = screen.getByRole('dialog', { name: 'Plain dialog' });
        expect(dialog).not.toHaveAttribute('aria-describedby');
        expect(dialog).toHaveFocus();
        await user.tab();
        expect(dialog).toHaveFocus();
        await user.keyboard('{Escape}');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Open' })).toHaveFocus();
    });

    it('ignores other keys and leaves already inert nodes inert after closing', async () => {
        const user = userEvent.setup();
        const outside = document.createElement('div');
        outside.setAttribute('inert', '');
        document.body.append(outside);
        render(
            <Harness>
                <button type="button">Inside</button>
            </Harness>,
        );
        await user.click(screen.getByRole('button', { name: 'Open' }));
        expect(screen.getByRole('button', { name: 'Inside' })).toHaveFocus();
        await user.keyboard('a');
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        await user.keyboard('{Escape}');
        expect(outside).toHaveAttribute('inert');
        outside.remove();
    });

    it('keeps focus inside on a backdrop click, and Escape still closes', async () => {
        const user = userEvent.setup();
        render(
            <Harness>
                <button type="button">Inside</button>
            </Harness>,
        );
        await user.click(screen.getByRole('button', { name: 'Open' }));
        const inside = screen.getByRole('button', { name: 'Inside' });
        const backdrop = screen.getByRole('dialog').parentElement!;
        await user.click(backdrop);
        expect(inside).toHaveFocus();
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        await user.keyboard('{Escape}');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Open' })).toHaveFocus();
    });

    it('closes on Escape even when focus has left the dialog', async () => {
        const user = userEvent.setup();
        render(
            <Harness>
                <button type="button">Inside</button>
            </Harness>,
        );
        await user.click(screen.getByRole('button', { name: 'Open' }));
        act(() => {
            screen.getByRole('button', { name: 'Inside' }).blur();
        });
        expect(document.body).toHaveFocus();
        await user.keyboard('{Escape}');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('leaves an Escape that a control inside already handled alone', async () => {
        const user = userEvent.setup();
        render(
            <Harness>
                <input
                    aria-label="Search"
                    onKeyDown={(event) => {
                        if (event.key === 'Escape') {
                            event.preventDefault();
                        }
                    }}
                />
            </Harness>,
        );
        await user.click(screen.getByRole('button', { name: 'Open' }));
        expect(screen.getByRole('textbox', { name: 'Search' })).toHaveFocus();
        await user.keyboard('{Escape}');
        expect(screen.getByRole('dialog')).toBeInTheDocument();
    });
});
