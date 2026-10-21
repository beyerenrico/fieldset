/** Window-scrolled virtualization of repeater rows (enabled for long lists). */
import { useWindowVirtualizer } from '@tanstack/react-virtual';
import { useEffect, useState } from 'react';
import { useRenderer } from '../context.tsx';

export const VIRTUALIZE_ABOVE = 25;

export interface RowWindow {
    readonly indexes: readonly number[];
    /** Spacer heights (px) before and after the rendered rows. */
    readonly before: number;
    readonly after: number;
    /** Ref for rendered rows (dynamic measurement); `undefined` when not virtualized. */
    readonly measure: ((element: Element | null) => void) | undefined;
    /** Ref for the list container (offset of the list on the page). */
    readonly attachList: (element: HTMLElement | null) => void;
}

function range(count: number): number[] {
    return Array.from({ length: count }, (_, index) => index);
}

/** Parses the row index of `pointer` below `repeaterPointer` (`/items/12/qty` → 12). */
export function rowIndexOf(repeaterPointer: string, pointer: string): number | null {
    if (!pointer.startsWith(`${repeaterPointer}/`)) {
        return null;
    }
    const segment = pointer.slice(repeaterPointer.length + 1).split('/')[0] ?? '';
    return /^\d+$/.test(segment) ? Number(segment) : null;
}

export function useRowWindow(count: number, repeaterPointer: string, estimate: number): RowWindow {
    const { reveal } = useRenderer();
    const enabled = count > VIRTUALIZE_ABOVE;
    const [list, setList] = useState<HTMLElement | null>(null);
    const scrollMargin = list === null ? 0 : list.getBoundingClientRect().top + window.scrollY;
    const virtualizer = useWindowVirtualizer({
        count: enabled ? count : 0,
        estimateSize: () => estimate,
        overscan: 5,
        scrollMargin,
        // Unrendered/unlaid-out rows report 0 px; keep the estimate instead of collapsing them.
        measureElement: (element) => {
            const height = element.getBoundingClientRect().height;
            return height > 0 ? height : estimate;
        },
    });

    useEffect(() => {
        if (!enabled) {
            return undefined;
        }
        return reveal.register((pointer) => {
            const index = rowIndexOf(repeaterPointer, pointer);
            if (index === null) {
                return false;
            }
            virtualizer.scrollToIndex(index, { align: 'center' });
            return true;
        });
    }, [enabled, repeaterPointer, reveal, virtualizer]);

    if (!enabled) {
        return {
            indexes: range(count),
            before: 0,
            after: 0,
            measure: undefined,
            attachList: setList,
        };
    }
    const items = virtualizer.getVirtualItems();
    const first = items[0];
    const last = items[items.length - 1];
    const before = first === undefined ? 0 : first.start - scrollMargin;
    const after =
        last === undefined
            ? 0
            : Math.max(virtualizer.getTotalSize() - (last.end - scrollMargin), 0);
    return {
        indexes: items.map((item) => item.index),
        before,
        after,
        measure: virtualizer.measureElement,
        attachList: setList,
    };
}
