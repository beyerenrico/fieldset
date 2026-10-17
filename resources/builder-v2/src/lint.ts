/**
 * Lint panel (ADR 0037): schema errors and lint findings from the last sync. JSON Pointers are
 * resolved against the definition that was checked, so findings stick to node ids while the user
 * keeps editing; clicking a finding selects and focuses its node.
 */
import type { LintReport } from './contract.ts';
import type { Marker } from './canvas/render.ts';
import { h, setText } from './dom.ts';
import type { Translator } from './i18n.ts';
import { labelOf } from './canvas/labels.ts';
import type { Selection } from './store/history.ts';
import { isList, isObject, str, type PathSegment } from './store/json.ts';
import type { Definition } from './store/tree.ts';

export interface LintItem {
    readonly severity: Marker;
    readonly code: string;
    readonly path: string;
    readonly message: string | null;
    readonly selection: Selection;
}

function unescapeToken(token: string): string {
    return token.replace(/~1/g, '/').replace(/~0/g, '~');
}

/** The deepest page or element on a JSON Pointer path; the form settings for anything else. */
export function resolvePointer(definition: Definition, pointer: string): Selection {
    const segments: PathSegment[] =
        pointer === '' ? [] : pointer.split('/').slice(1).map(unescapeToken);
    let value: unknown = definition;
    let found: Selection = { kind: 'settings' };
    segments.forEach((segment, position) => {
        value = isList(value)
            ? value[Number(segment)]
            : isObject(value)
              ? value[String(segment)]
              : undefined;
        const id = isObject(value) ? str(value.id) : null;
        if (id === null || segments[0] !== 'pages') {
            return;
        }
        if (position === 1) {
            found = { kind: 'page', id };
        } else if (segments[position - 1] === 'elements') {
            found = { kind: 'element', id };
        }
    });
    return found;
}

export function lintItems(report: LintReport, definition: Definition): LintItem[] {
    const schema = report.schema_errors.map((error): LintItem => ({
        severity: 'error',
        code: 'schema',
        path: error.path,
        message: error.message,
        selection: resolvePointer(definition, error.path),
    }));
    const findings = report.findings.map((finding): LintItem => ({
        severity: finding.severity,
        code: finding.code,
        path: finding.path,
        message: null,
        selection: resolvePointer(definition, finding.path),
    }));
    return [...schema, ...findings];
}

/** Worst marker per node id (errors win over warnings). */
export function markersOf(items: readonly LintItem[]): Map<string, Marker> {
    const markers = new Map<string, Marker>();
    for (const item of items) {
        const id =
            item.selection !== null && item.selection.kind !== 'settings'
                ? item.selection.id
                : null;
        if (id !== null && markers.get(id) !== 'error') {
            markers.set(id, item.severity);
        }
    }
    return markers;
}

export function hasErrors(items: readonly LintItem[]): boolean {
    return items.some((item) => item.severity === 'error');
}

export interface LintPanelDeps {
    readonly tr: Translator;
    readonly onPick: (selection: Selection) => void;
}

export class LintPanel {
    readonly #list: HTMLElement | null;
    readonly #count: HTMLElement | null;
    readonly #deps: LintPanelDeps;

    constructor(panel: HTMLElement | null, deps: LintPanelDeps) {
        this.#deps = deps;
        this.#list = panel?.querySelector<HTMLElement>('[data-builder-lint-list]') ?? null;
        this.#count = panel?.querySelector<HTMLElement>('[data-builder-lint-count]') ?? null;
    }

    update(items: readonly LintItem[], definition: Definition): void {
        const { tr } = this.#deps;
        if (this.#count !== null) {
            this.#count.dataset.count = String(items.length);
            this.#count.dataset.severity = hasErrors(items)
                ? 'error'
                : items.length > 0
                  ? 'warning'
                  : 'ok';
            setText(
                this.#count,
                items.length === 0 ? tr.t('lint.ok') : tr.t('lint.count', { count: items.length }),
            );
        }
        this.#list?.replaceChildren(
            ...items.map((item) => h('li', {}, [this.#button(item, definition)])),
        );
    }

    #button(item: LintItem, definition: Definition): HTMLButtonElement {
        const { tr } = this.#deps;
        const where =
            item.selection === null || item.selection.kind === 'settings'
                ? tr.t('lint.form')
                : labelOf(definition, item.selection.id, tr);
        const text =
            item.message === null
                ? tr.lintLabel(item.code)
                : `${tr.t('lint.schema')}: ${item.message}`;
        const button = h(
            'button',
            {
                type: 'button',
                class: 'bv2-lint__item',
                'data-builder-lint-item': '',
                'data-path': item.path,
                'data-severity': item.severity,
            },
            [
                h('span', { class: 'bv2-lint__where' }, [where]),
                h('span', { class: 'bv2-lint__text' }, [text]),
            ],
        );
        button.addEventListener('click', () => {
            this.#deps.onPick(item.selection);
        });
        return button;
    }
}
