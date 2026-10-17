import { describe, expect, it, vi } from 'vitest';
import { hasErrors, LintPanel, lintItems, markersOf, resolvePointer } from '../src/lint.ts';
import { Translator, toStrings } from '../src/i18n.ts';
import { contractDefinition, ID } from './support/fixtures.ts';
import { englishStrings } from './support/dom.ts';

describe('lint pointers', () => {
    it.each([
        ['', { kind: 'settings' }],
        ['/settings/numbering/format', { kind: 'settings' }],
        ['/pages/0', { kind: 'page', id: ID.pageStart }],
        ['/pages/1/title', { kind: 'page', id: ID.pageItems }],
        ['/pages/0/elements/0/key', { kind: 'element', id: ID.customer }],
        ['/pages/0/elements/1/elements/0/columns/0/elements/0', { kind: 'element', id: ID.day }],
        ['/pages/0/elements/1/elements/0/columns/1', { kind: 'element', id: ID.cols }],
        ['/pages/1/elements/0/elements/1/validation/min', { kind: 'element', id: ID.qty }],
        ['/pages/9/elements/0', { kind: 'settings' }],
    ])('%s', (pointer, expected) => {
        expect(resolvePointer(contractDefinition(), pointer)).toEqual(expected);
    });

    it('unescapes RFC 6901 tokens', () => {
        expect(resolvePointer({ 'a/b': { 'c~d': 1 } }, '/a~1b/c~0d')).toEqual({ kind: 'settings' });
    });
});

describe('lint panel', () => {
    const report = {
        schema_errors: [{ path: '/pages/0/elements/0', message: 'must have label' }],
        findings: [
            { severity: 'warning' as const, code: 'label_missing', path: '/pages/0/elements/0' },
            {
                severity: 'warning' as const,
                code: 'duplicate_key',
                path: '/pages/1/elements/0/elements/1',
            },
        ],
    };

    it('turns a report into items and worst markers per node', () => {
        const items = lintItems(report, contractDefinition());
        expect(items).toHaveLength(3);
        expect(hasErrors(items)).toBe(true);
        expect(markersOf(items)).toEqual(
            new Map([
                [ID.customer, 'error'],
                [ID.qty, 'warning'],
            ]),
        );
        expect(
            hasErrors(
                lintItems({ schema_errors: [], findings: report.findings }, contractDefinition()),
            ),
        ).toBe(false);
    });

    it('lists findings with paths and selects the node on click', () => {
        document.body.innerHTML =
            '<div data-builder-lint><span data-builder-lint-count></span><ul data-builder-lint-list></ul></div>';
        const onPick = vi.fn();
        const panel = new LintPanel(document.querySelector('[data-builder-lint]'), {
            tr: new Translator(toStrings(englishStrings())),
            onPick,
        });
        panel.update(lintItems(report, contractDefinition()), contractDefinition());
        const items = document.querySelectorAll<HTMLElement>('[data-builder-lint-item]');
        expect(items).toHaveLength(3);
        expect(items[0]!.dataset.path).toBe('/pages/0/elements/0');
        expect(items[0]!.textContent).toContain('Structure error: must have label');
        expect(items[2]!.textContent).toContain('Menge');
        expect(items[2]!.textContent).toContain('Key is used twice in the same scope');
        expect(document.querySelector('[data-builder-lint-count]')!.textContent).toBe('3 problems');
        items[2]!.click();
        expect(onPick).toHaveBeenCalledWith({ kind: 'element', id: ID.qty });
        panel.update([], contractDefinition());
        expect(document.querySelector('[data-builder-lint-count]')!.textContent).toBe(
            'No problems found',
        );
        expect(document.querySelectorAll('[data-builder-lint-item]')).toHaveLength(0);
    });
});
