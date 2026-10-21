/** Classification of element types (spec §3.2). */

/** Layout elements carry an id and a key but never data. */
export const LAYOUT_TYPES: ReadonlySet<string> = new Set([
    'section',
    'columns',
    'heading',
    'paragraph',
    'divider',
    'image',
    'page_break',
]);

/** Type names reserved for later versions; 1.0 rejects them (lint `reserved_type`). */
export const RESERVED_TYPES: ReadonlySet<string> = new Set([
    'record',
    'barcode',
    'rich_text',
    'lookup',
]);

export function isLayoutType(type: string): boolean {
    return LAYOUT_TYPES.has(type);
}
