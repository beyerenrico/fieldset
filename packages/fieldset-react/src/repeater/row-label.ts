import type { ElementNode } from '@fieldset/fieldset-js';
import { interpolate, type RendererMessages } from '../i18n/messages.ts';

/** `itemLabel` (with `{index}` or an appended number) or the default "Entry n". */
export function rowLabel(node: ElementNode, index: number, messages: RendererMessages): string {
    const itemLabel = node.raw['itemLabel'];
    const number = index + 1;
    if (typeof itemLabel === 'string' && itemLabel !== '') {
        return itemLabel.includes('{index}')
            ? interpolate(itemLabel, { index: number })
            : `${itemLabel} ${String(number)}`;
    }
    return interpolate(messages.rowLabel, { index: number });
}
