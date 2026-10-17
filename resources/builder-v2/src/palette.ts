/**
 * The palette (ADR 0037): one button per element type. Click or Enter inserts after the selection;
 * the buttons are also SortableJS clone sources (see canvas/sortable.ts).
 */
import type { JsonObject } from '@form-schema-app/form-schema-js';
import type { PaletteGroup } from './contract.ts';
import { h } from './dom.ts';
import type { Translator } from './i18n.ts';
import type { TypeIcons } from './canvas/icons.ts';

export interface PaletteDeps {
    readonly tr: Translator;
    readonly icons: TypeIcons;
    readonly insert: (type: string) => void;
    /** True right after a drag ended (a click must not insert a second copy). */
    readonly justDragged: () => boolean;
}

/** Templates by element type, from the config's palette groups. */
export function paletteTemplates(groups: readonly PaletteGroup[]): Map<string, JsonObject> {
    return new Map(
        groups.flatMap((group) =>
            group.items.map((item): [string, JsonObject] => [item.type, item.node]),
        ),
    );
}

export function paletteIcons(groups: readonly PaletteGroup[]): Map<string, string> {
    return new Map(
        groups.flatMap((group) =>
            group.items.map((item): [string, string] => [item.type, item.icon]),
        ),
    );
}

export class Palette {
    readonly #el: HTMLElement;
    readonly #deps: PaletteDeps;
    readonly #lists: HTMLElement[];

    constructor(el: HTMLElement, groups: readonly PaletteGroup[], deps: PaletteDeps) {
        this.#el = el;
        this.#deps = deps;
        this.#lists = this.#render(groups);
        el.addEventListener('click', this.#onClick);
    }

    #render(groups: readonly PaletteGroup[]): HTMLElement[] {
        const { tr, icons } = this.#deps;
        const lists: HTMLElement[] = [];
        const blocks = groups.map((group) => {
            const headingId = `bv2-palette-${group.group}`;
            const items = group.items.map((item) =>
                h(
                    'button',
                    {
                        type: 'button',
                        class: 'bv2-palette__item',
                        'data-builder-palette-item': item.type,
                        title: tr.t('palette.insert', { type: item.label }),
                        'aria-label': tr.t('palette.insert', { type: item.label }),
                    },
                    [icons.for(item.type), h('span', { 'aria-hidden': 'true' }, [item.label])],
                ),
            );
            const list = h(
                'div',
                {
                    class: 'bv2-palette__list',
                    role: 'group',
                    'aria-labelledby': headingId,
                    'data-builder-palette-list': '',
                },
                items,
            );
            lists.push(list);
            return h('div', { class: 'bv2-palette__group' }, [
                h('h3', { class: 'bv2-palette__heading', id: headingId }, [group.label]),
                list,
            ]);
        });
        this.#el.replaceChildren(...blocks);
        return lists;
    }

    readonly #onClick = (event: MouseEvent): void => {
        const item = (event.target as Element | null)?.closest<HTMLElement>(
            '[data-builder-palette-item]',
        );
        const type = item?.dataset.builderPaletteItem;
        if (type !== undefined && !this.#deps.justDragged()) {
            this.#deps.insert(type);
        }
    };

    get lists(): readonly HTMLElement[] {
        return this.#lists;
    }

    destroy(): void {
        this.#el.removeEventListener('click', this.#onClick);
    }
}
