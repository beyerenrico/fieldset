/** Accessibility guarantees of the default stylesheet (WCAG 2.2 AA). */
import { describe, expect, it } from 'vitest';
import base from '../src/styles/base.css?raw';
import layout from '../src/styles/layout.css?raw';
import repeater from '../src/styles/repeater.css?raw';

/** Declarations of the first rule whose selector is exactly `selector`. */
function rule(css: string, selector: string): string {
    const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const match = new RegExp(`(?:^|\\n)${escaped}\\s*\\{([^}]*)\\}`).exec(css);
    if (match === null) {
        throw new Error(`No rule for ${selector}`);
    }
    return match[1] ?? '';
}

function token(declarations: string, name: string): string {
    const match = new RegExp(`${name}:\\s*([^;]+);`).exec(declarations);
    if (match === null) {
        throw new Error(`No token ${name}`);
    }
    return (match[1] ?? '').trim();
}

function luminance(hex: string): number {
    const channels = [1, 3, 5].map((start) => parseInt(hex.slice(start, start + 2), 16) / 255);
    const [r = 0, g = 0, b = 0] = channels.map((value) =>
        value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4,
    );
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrast(a: string, b: string): number {
    const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return ((light ?? 0) + 0.05) / ((dark ?? 0) + 0.05);
}

describe('default theme', () => {
    it('gives the focus indicator at least 3:1 against the background (1.4.11)', () => {
        const light = rule(base, '.fsr-root');
        const focus = token(light, '--fsr-color-focus');
        const background = token(light, '--fsr-color-bg');
        expect(contrast(focus, background)).toBeGreaterThanOrEqual(3);
        expect(contrast(focus, token(light, '--fsr-color-surface'))).toBeGreaterThanOrEqual(3);
    });

    it('makes error summary links at least 44 px tall (2.5.8, 44 px house rule)', () => {
        const links = rule(layout, '.fsr-summary__list a');
        expect(links).toMatch(/min-height:\s*var\(--fsr-control-height\)/);
        expect(links).toMatch(/display:\s*inline-flex/);
        expect(links).toMatch(/align-items:\s*center/);
        expect(token(base, '--fsr-control-height')).toBe('2.75rem');
    });

    it('keeps focused fields clear of the sticky footer (2.4.11)', () => {
        const padding = rule(layout, 'html:has(.fsr-footer)');
        expect(padding).toMatch(
            /scroll-padding-bottom:\s*calc\(6rem \+ env\(safe-area-inset-bottom, 0px\)\)/,
        );
    });
});

describe('reflow (1.4.10)', () => {
    it('keeps visually hidden labels inside the scrolling table wrapper', () => {
        // Absolutely positioned sr-only labels escape an unpositioned overflow container and widen
        // the page on phones (the layout viewport grows and the sticky footer leaves the screen).
        expect(rule(repeater, '.fsr-table-wrap')).toMatch(/position:\s*relative;/);
    });
});
