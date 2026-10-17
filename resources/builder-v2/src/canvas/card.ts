/** One canvas card (page or element): its DOM parts, created once per id and patched in place. */
import type { Container } from '../contract.ts';
import { h, setAttrs, setText } from '../dom.ts';
import type { Translator } from '../i18n.ts';
import { str } from '../store/json.ts';
import {
    MAX_COLUMNS,
    MIN_COLUMNS,
    containerKey,
    isContainerType,
    ownContainers,
    typeOf,
    type NodeEntry,
} from '../store/tree.ts';
import { icon, type TypeIcons } from './icons.ts';
import { nodeLabel } from './labels.ts';

export const HANDLE_HELP_ID = 'bv2-handle-help';

export interface Card {
    readonly id: string;
    readonly kind: 'page' | 'element';
    readonly el: HTMLElement;
    readonly handle: HTMLButtonElement;
    readonly collapse: HTMLButtonElement;
    readonly menu: HTMLButtonElement;
    readonly typeIcon: HTMLElement;
    readonly label: HTMLElement;
    readonly type: HTMLElement;
    readonly key: HTMLElement;
    readonly lock: HTMLElement;
    readonly flag: HTMLElement;
    readonly body: HTMLElement;
    /** Rendered state: the card is only patched when one of these changed. */
    node: object | null;
    depth: number;
    collapsed: boolean;
    lists: HTMLElement[];
}

export interface CardContext {
    readonly tr: Translator;
    readonly icons: TypeIcons;
}

function button(className: string, attrs: Record<string, string>, child: Node): HTMLButtonElement {
    return h('button', { type: 'button', class: className, tabindex: '-1', ...attrs }, [child]);
}

interface HeadParts {
    readonly handle: HTMLButtonElement;
    readonly collapse: HTMLButtonElement;
    readonly menu: HTMLButtonElement;
    readonly typeIcon: HTMLElement;
    readonly label: HTMLElement;
    readonly type: HTMLElement;
    readonly key: HTMLElement;
    readonly lock: HTMLElement;
    readonly flag: HTMLElement;
}

function headParts(kind: 'page' | 'element', ctx: CardContext): HeadParts {
    const srOnly = (text: string): HTMLElement => h('span', { class: 'bv2-sr-only' }, [text]);
    return {
        handle: button(
            'bv2-handle',
            { 'data-builder-handle': kind, 'aria-describedby': HANDLE_HELP_ID },
            icon('grip'),
        ),
        collapse: button('bv2-card__collapse', { 'data-builder-collapse': '' }, icon('chevron')),
        menu: button(
            'bv2-card__menu',
            { 'aria-haspopup': 'menu', 'aria-expanded': 'false', 'data-builder-menu': '' },
            icon('dots'),
        ),
        typeIcon: h('span', { class: 'bv2-card__icon', 'aria-hidden': 'true' }),
        label: h('span', { class: 'bv2-card__label' }),
        type: h('span', { class: 'bv2-card__type' }),
        key: h('code', { class: 'bv2-card__key' }),
        lock: h('span', { class: 'bv2-card__lock', hidden: true }, [
            icon('lock'),
            srOnly(ctx.tr.t('canvas.locked')),
        ]),
        flag: h('span', { class: 'bv2-card__flag', hidden: true }, [icon('alert'), srOnly('')]),
    };
}

export function createCard(kind: 'page' | 'element', id: string, ctx: CardContext): Card {
    const titleId = `bv2-t-${id}`;
    const parts = headParts(kind, ctx);
    const title = h('span', { class: 'bv2-card__title', id: titleId }, [
        parts.label,
        parts.type,
        parts.lock,
        parts.flag,
    ]);
    const head = h('div', { class: 'bv2-card__head' }, [
        parts.handle,
        parts.collapse,
        parts.typeIcon,
        title,
        parts.key,
        parts.menu,
    ]);
    const body = h('div', { class: 'bv2-card__body' });
    const el = h(
        'div',
        {
            class: `bv2-card bv2-card--${kind}`,
            role: 'treeitem',
            tabindex: '-1',
            'aria-labelledby': titleId,
            'aria-selected': 'false',
        },
        [head, body],
    );
    setAttrs(el, kind === 'page' ? { 'data-builder-page': id } : { 'data-builder-node': id });
    return { id, kind, el, ...parts, body, node: null, depth: -1, collapsed: false, lists: [] };
}

/** Updates texts and attributes from the node (cheap: attribute writes only happen on change). */
export function patchCard(
    card: Card,
    entry: NodeEntry,
    collapsed: boolean,
    ctx: CardContext,
): void {
    const { tr } = ctx;
    const node = entry.node;
    const type = entry.kind === 'page' ? 'page' : typeOf(node);
    const label = nodeLabel(node, tr);
    const key = str(node.key) ?? '';
    const expandable = entry.kind === 'page' || isContainerType(type);
    setText(card.label, label);
    setText(card.type, entry.kind === 'page' ? tr.typeLabel('page') : tr.typeLabel(type));
    setText(card.key, key);
    if (card.typeIcon.dataset.type !== type) {
        card.typeIcon.dataset.type = type;
        card.typeIcon.replaceChildren(ctx.icons.for(type));
    }
    setAttrs(card.el, {
        'aria-level': String(entry.depth + 1),
        'aria-expanded': expandable ? String(!collapsed) : null,
        ...(entry.kind === 'page'
            ? { 'data-builder-page-key': key }
            : { 'data-builder-type': type, 'data-builder-key': key }),
    });
    setAttrs(card.handle, { 'aria-label': tr.t('canvas.handle', { label }) });
    setAttrs(card.menu, { 'aria-label': tr.t('canvas.actions', { label }) });
    setAttrs(card.collapse, {
        hidden: !expandable,
        'aria-expanded': String(!collapsed),
        'aria-label': tr.t(collapsed ? 'canvas.expand' : 'canvas.collapse', { label }),
    });
    card.body.hidden = collapsed || !expandable;
}

/** The lists a card owns: a page's elements, a section/repeater's elements or each column. */
export function cardContainers(entry: NodeEntry): Container[] {
    return entry.kind === 'page' ? [{ kind: 'page', pageId: entry.id }] : ownContainers(entry.node);
}

function makeList(container: Container, ariaLabel: string, ctx: CardContext): HTMLElement {
    return h('div', {
        class: 'bv2-list',
        role: 'group',
        'data-builder-list': '',
        'data-builder-container': containerKey(container),
        'aria-label': ariaLabel,
        'data-empty-label': ctx.tr.t('canvas.empty'),
    });
}

function columnBlock(
    card: Card,
    list: HTMLElement,
    column: number,
    count: number,
    ctx: CardContext,
): HTMLElement {
    const number = column + 1;
    const text = ctx.tr.t('canvas.remove_column', { number });
    const remove = button(
        'bv2-btn bv2-btn--icon',
        {
            'data-builder-action': 'remove-column',
            'data-builder-owner': card.id,
            'data-column': String(column),
            'aria-label': text,
            title: text,
        },
        icon('close'),
    );
    remove.disabled = count <= MIN_COLUMNS;
    const head = h('div', { class: 'bv2-column__head' }, [
        h('span', {}, [ctx.tr.t('canvas.column', { number })]),
        remove,
    ]);
    return h('div', { class: 'bv2-column' }, [head, list]);
}

function listLabels(containers: readonly Container[], label: string, ctx: CardContext): string[] {
    const base = ctx.tr.t('canvas.elements_of', { label });
    return containers.map((container) =>
        container.kind === 'column'
            ? `${base}, ${ctx.tr.t('canvas.column', { number: container.column + 1 })}`
            : base,
    );
}

function columnsBody(card: Card, lists: readonly HTMLElement[], ctx: CardContext): Node[] {
    const add = button(
        'bv2-btn bv2-columns__add',
        { 'data-builder-action': 'add-column', 'data-builder-owner': card.id },
        icon('plus'),
    );
    add.append(h('span', {}, [ctx.tr.t('canvas.add_column')]));
    add.disabled = lists.length >= MAX_COLUMNS;
    const blocks = lists.map((list, column) => columnBlock(card, list, column, lists.length, ctx));
    return [h('div', { class: 'bv2-columns', 'data-count': String(lists.length) }, blocks), add];
}

/** Makes the card's body hold exactly one list per container (reusing lists by container key). */
export function syncLists(
    card: Card,
    containers: readonly Container[],
    label: string,
    ctx: CardContext,
): HTMLElement[] {
    const keys = containers.map(containerKey);
    const labels = listLabels(containers, label, ctx);
    const unchanged =
        keys.length === card.lists.length &&
        keys.every((key, index) => card.lists[index]?.dataset.builderContainer === key);
    if (!unchanged) {
        const byKey = new Map(
            card.lists.map((list) => [list.dataset.builderContainer ?? '', list]),
        );
        card.lists = containers.map(
            (container, index) =>
                byKey.get(keys[index] ?? '') ?? makeList(container, labels[index] ?? '', ctx),
        );
        const isColumns = containers.some((container) => container.kind === 'column');
        card.body.replaceChildren(...(isColumns ? columnsBody(card, card.lists, ctx) : card.lists));
    }
    card.lists.forEach((list, index) => {
        setAttrs(list, { 'aria-label': labels[index] ?? '' });
    });
    return card.lists;
}
