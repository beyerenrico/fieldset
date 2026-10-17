/** Tiny DOM helpers (no framework: the canvas is a keyed renderer, ADR 0037). */

export type Attrs = Readonly<Record<string, string | boolean | null | undefined>>;

/** Sets or removes attributes: `false`/`null`/`undefined` remove, `true` sets an empty value. */
export function setAttrs(el: Element, attrs: Attrs): void {
    for (const [name, value] of Object.entries(attrs)) {
        if (value === false || value === null || value === undefined) {
            el.removeAttribute(name);
        } else {
            const text = value === true ? '' : value;
            if (el.getAttribute(name) !== text) {
                el.setAttribute(name, text);
            }
        }
    }
}

export function h<K extends keyof HTMLElementTagNameMap>(
    tag: K,
    attrs: Attrs = {},
    children: readonly (Node | string)[] = [],
): HTMLElementTagNameMap[K] {
    const el = document.createElement(tag);
    setAttrs(el, attrs);
    el.append(...children);
    return el;
}

export function setText(el: Element, text: string): void {
    if (el.textContent !== text) {
        el.textContent = text;
    }
}

/** True for targets where typing must not trigger builder shortcuts. */
export function isEditableTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }
    if (target.isContentEditable) {
        return true;
    }
    const tag = target.tagName;
    if (tag === 'TEXTAREA' || tag === 'SELECT') {
        return true;
    }
    if (tag !== 'INPUT') {
        return false;
    }
    const type = (target as HTMLInputElement).type;
    return !['button', 'checkbox', 'radio', 'submit', 'reset', 'range', 'color', 'file'].includes(
        type,
    );
}
