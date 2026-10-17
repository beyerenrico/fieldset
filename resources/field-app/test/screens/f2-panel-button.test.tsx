/** Busy panel buttons keep focus (aria-disabled, like the update banner) and ignore clicks. */
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { PanelButton } from '../../src/screens/review/PanelButton.tsx';

describe('PanelButton', () => {
    it('stays focusable and ignores clicks while busy; labels may wrap', async () => {
        const onClick = vi.fn();
        const { rerender } = render(
            <PanelButton busy onClick={onClick}>
                In aktuelle Version kopieren
            </PanelButton>,
        );
        const button = screen.getByRole('button', { name: 'In aktuelle Version kopieren' });
        expect(button).toHaveAttribute('aria-disabled', 'true');
        expect(button).not.toBeDisabled();
        expect(button).toHaveClass('whitespace-normal');
        expect(button).not.toHaveClass('whitespace-nowrap');
        button.focus();
        await userEvent.keyboard('{Enter}');
        expect(onClick).not.toHaveBeenCalled();
        expect(button).toHaveFocus();

        rerender(
            <PanelButton busy={false} onClick={onClick}>
                In aktuelle Version kopieren
            </PanelButton>,
        );
        expect(button).not.toHaveAttribute('aria-disabled');
        await userEvent.keyboard('{Enter}');
        expect(onClick).toHaveBeenCalledTimes(1);
    });
});
