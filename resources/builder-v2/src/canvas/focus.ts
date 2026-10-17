/** Focus by node id: survives re-renders that move or rebuild card elements. */

export type FocusPart = 'card' | 'handle' | 'menu' | 'collapse';

export interface FocusMark {
    readonly id: string;
    readonly part: FocusPart;
}

const PART_SELECTORS: Readonly<Record<Exclude<FocusPart, 'card'>, string>> = {
    handle: ':scope > .bv2-card__head > [data-builder-handle]',
    menu: ':scope > .bv2-card__head > [data-builder-menu]',
    collapse: ':scope > .bv2-card__head > [data-builder-collapse]',
};

export function cardOf(el: Element | null): HTMLElement | null {
    return el?.closest<HTMLElement>('[data-builder-node], [data-builder-page]') ?? null;
}

export function cardId(card: HTMLElement): string | null {
    return card.dataset.builderNode ?? card.dataset.builderPage ?? null;
}

/** Which card (and which part of it) holds focus inside `root`. */
export function focusMark(
    root: HTMLElement,
    active: Element | null = document.activeElement,
): FocusMark | null {
    if (active === null || !root.contains(active)) {
        return null;
    }
    const card = cardOf(active);
    const id = card === null ? null : cardId(card);
    if (card === null || id === null) {
        return null;
    }
    if (active === card) {
        return { id, part: 'card' };
    }
    const part = (Object.keys(PART_SELECTORS) as Exclude<FocusPart, 'card'>[]).find((name) =>
        active.matches(`[data-builder-${name}]`),
    );
    return { id, part: part ?? 'card' };
}

export function findCard(root: ParentNode, id: string): HTMLElement | null {
    return (
        Array.from(
            root.querySelectorAll<HTMLElement>('[data-builder-node], [data-builder-page]'),
        ).find((el) => cardId(el) === id) ?? null
    );
}

/** Focuses a card or one of its head buttons; returns false when the card is not rendered. */
export function focusNode(root: ParentNode, mark: FocusMark): boolean {
    const card = findCard(root, mark.id);
    if (card === null) {
        return false;
    }
    const target =
        mark.part === 'card' ? card : card.querySelector<HTMLElement>(PART_SELECTORS[mark.part]);
    (target ?? card).focus({ preventScroll: false });
    return true;
}
