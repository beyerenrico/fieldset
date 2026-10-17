/** Static inline icons for canvas chrome (heroicons outline paths, MIT). Constants only, never user data. */

const PATHS = {
    grip: 'M9 5.25h.008v.008H9V5.25Zm0 6.75h.008v.008H9V12Zm0 6.75h.008v.008H9v-.008Zm6-13.5h.008v.008H15V5.25Zm0 6.75h.008v.008H15V12Zm0 6.75h.008v.008H15v-.008Z',
    chevron: 'm19.5 8.25-7.5 7.5-7.5-7.5',
    dots: 'M6.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM12.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM18.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z',
    lock: 'M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z',
    alert: 'M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z',
    plus: 'M12 4.5v15m7.5-7.5h-15',
    close: 'M6 18 18 6M6 6l12 12',
    square: 'M5.25 7.5A2.25 2.25 0 0 1 7.5 5.25h9a2.25 2.25 0 0 1 2.25 2.25v9a2.25 2.25 0 0 1-2.25 2.25h-9a2.25 2.25 0 0 1-2.25-2.25v-9Z',
} as const;

export type IconName = keyof typeof PATHS;

const SVG_NS = 'http://www.w3.org/2000/svg';

export function icon(name: IconName): SVGSVGElement {
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.5');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    svg.setAttribute('class', 'bv2-icon');
    const path = document.createElementNS(SVG_NS, 'path');
    path.setAttribute('stroke-linecap', 'round');
    path.setAttribute('stroke-linejoin', 'round');
    path.setAttribute('d', PATHS[name]);
    svg.append(path);
    return svg;
}

/**
 * Element-type icons rendered server-side (`<template data-builder-icons>` holds one
 * `[data-icon="heroicon-o-…"]` per palette icon); unknown types fall back to a square.
 */
export class TypeIcons {
    readonly #byType = new Map<string, Element>();

    constructor(template: HTMLTemplateElement | null, iconByType: ReadonlyMap<string, string>) {
        const holders = Array.from(template?.content.querySelectorAll('[data-icon]') ?? []);
        for (const [type, name] of iconByType) {
            const holder = holders.find(
                (candidate) => candidate.getAttribute('data-icon') === name,
            );
            const svg = holder?.querySelector('svg') ?? null;
            if (svg !== null) {
                this.#byType.set(type, svg);
            }
        }
    }

    for(type: string): Element {
        const svg = this.#byType.get(type);
        if (svg === undefined) {
            return icon('square');
        }
        const copy = svg.cloneNode(true) as Element;
        copy.setAttribute('aria-hidden', 'true');
        copy.setAttribute('focusable', 'false');
        copy.classList.add('bv2-icon');
        return copy;
    }
}
