/** WCAG 2.4.11 (focus not obscured): the sticky app header never covers a focused field. */
import { describe, expect, it } from 'vitest';
import app from '../src/styles/app.css?raw';

describe('app stylesheet', () => {
    it('keeps focused elements clear of the sticky header when scrolling up', () => {
        const html = /(?:^|\n)html\s*\{([^}]*)\}/.exec(app)?.[1] ?? '';
        expect(html).toMatch(/scroll-padding-top:\s*[^;]*rem/);
    });
});
