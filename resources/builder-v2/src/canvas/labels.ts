/** Human labels for nodes and lists (cards, announcements, history entries). */
import type { JsonObject } from '@fieldset/fieldset-js';
import type { Container } from '../contract.ts';
import type { Translator } from '../i18n.ts';
import { str } from '../store/json.ts';
import { listEntry, nodeEntry, typeOf, type Definition } from '../store/tree.ts';

export function nodeLabel(node: JsonObject, tr: Translator): string {
    for (const property of ['label', 'title', 'text']) {
        const value = str(node[property])?.trim() ?? '';
        if (value !== '') {
            return value.length > 80 ? `${value.slice(0, 79)}…` : value;
        }
    }
    const type = typeOf(node);
    if (type !== '') {
        return tr.typeLabel(type);
    }
    return str(node.key) ?? tr.t('canvas.untitled');
}

export function labelOf(definition: Definition, id: string, tr: Translator): string {
    const entry = nodeEntry(definition, id);
    return entry === null ? tr.t('canvas.untitled') : nodeLabel(entry.node, tr);
}

export function listLabel(definition: Definition, container: Container, tr: Translator): string {
    if (container.kind === 'pages') {
        return tr.t('canvas.pages');
    }
    const ownerId = container.kind === 'page' ? container.pageId : container.parentId;
    const owner = labelOf(definition, ownerId, tr);
    return container.kind === 'column'
        ? `${owner}, ${tr.t('canvas.column', { number: container.column + 1 })}`
        : owner;
}

/** 1-based position and size of a node in its list (for announcements). */
export function positionOf(
    definition: Definition,
    id: string,
): { position: number; count: number } | null {
    const entry = nodeEntry(definition, id);
    const list = entry === null ? null : listEntry(definition, entry.container);
    if (entry === null || list === null) {
        return null;
    }
    return { position: list.ids.indexOf(id) + 1, count: list.ids.length };
}
