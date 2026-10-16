/** Button: `aria-disabled` (kept focusable on purpose) must look exactly like `disabled`. */
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Button, buttonVariants } from '../../src/components/ui/button.tsx';

const classesOf = (element: HTMLElement): string[] => element.className.split(/\s+/);

describe('Button', () => {
    it('styles aria-disabled="true" like the disabled attribute', () => {
        const base = buttonVariants().split(/\s+/);
        const disabledStyles = base.filter((name) => name.startsWith('disabled:'));
        expect(disabledStyles.length).toBeGreaterThan(0);
        for (const name of disabledStyles) {
            expect(base).toContain(name.replace('disabled:', 'aria-disabled:'));
        }
    });

    it('keeps an aria-disabled button focusable and carries the dimmed style for every variant', () => {
        render(
            <>
                <Button aria-disabled>Default</Button>
                <Button aria-disabled variant="outline">
                    Outline
                </Button>
                <Button aria-disabled variant="ghost">
                    Ghost
                </Button>
            </>,
        );
        for (const name of ['Default', 'Outline', 'Ghost']) {
            const button = screen.getByRole('button', { name });
            expect(button).toHaveAttribute('aria-disabled', 'true');
            expect(button).toBeEnabled();
            expect(classesOf(button)).toContain('aria-disabled:opacity-50');
        }
    });
});
