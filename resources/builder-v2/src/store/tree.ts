/** Read-only views over the canonical definition: node index, lists (containers) and depths. */
import type { JsonObject } from '@form-schema-app/form-schema-js';
import type { Container } from '../contract.ts';
import { getIn, isList, isObject, listProp, str, type Path } from './json.ts';

export type Definition = JsonObject;

export const CHILD_CONTAINER_TYPES: ReadonlySet<string> = new Set(['section', 'repeater']);
export const MIN_COLUMNS = 2;
export const MAX_COLUMNS = 4;

export interface NodeEntry {
    readonly id: string;
    readonly kind: 'page' | 'element';
    readonly node: JsonObject;
    readonly path: Path;
    readonly container: Container;
    readonly index: number;
    /** Depth of the list the node lives in: the page list is 0, a page's elements 1. */
    readonly depth: number;
    /** Container element (section/repeater/columns) holding the node; null on a page or for pages. */
    readonly parentId: string | null;
    readonly pageId: string;
}

export interface ListEntry {
    readonly container: Container;
    readonly key: string;
    readonly path: Path;
    readonly depth: number;
    readonly ids: readonly string[];
}

export interface TreeIndex {
    readonly nodes: ReadonlyMap<string, NodeEntry>;
    readonly lists: ReadonlyMap<string, ListEntry>;
}

export function typeOf(node: JsonObject): string {
    return str(node.type) ?? '';
}

export function idOf(node: JsonObject): string | null {
    return str(node.id);
}

export function isContainerType(type: string): boolean {
    return CHILD_CONTAINER_TYPES.has(type) || type === 'columns';
}

export function containerKey(container: Container): string {
    switch (container.kind) {
        case 'pages':
            return 'pages';
        case 'page':
            return `page:${container.pageId}`;
        case 'children':
            return `children:${container.parentId}`;
        case 'column':
            return `column:${container.parentId}:${String(container.column)}`;
    }
}

export function parseContainerKey(key: string): Container | null {
    if (key === 'pages') {
        return { kind: 'pages' };
    }
    const [kind, id, column] = key.split(':');
    if (id === undefined || id === '') {
        return null;
    }
    if (kind === 'page') {
        return { kind: 'page', pageId: id };
    }
    if (kind === 'children') {
        return { kind: 'children', parentId: id };
    }
    const index = Number(column);
    return kind === 'column' && Number.isInteger(index) && index >= 0
        ? { kind: 'column', parentId: id, column: index }
        : null;
}

/** The lists directly owned by an element (none for non-containers). */
export function ownContainers(node: JsonObject): Container[] {
    const id = idOf(node);
    const type = typeOf(node);
    if (id === null) {
        return [];
    }
    if (CHILD_CONTAINER_TYPES.has(type)) {
        return [{ kind: 'children', parentId: id }];
    }
    if (type === 'columns') {
        return listProp(node, 'columns').map((_, column) => ({
            kind: 'column',
            parentId: id,
            column,
        }));
    }
    return [];
}

/** How many container levels the subtree of `node` occupies (0 for a non-container). */
export function containerHeight(node: JsonObject): number {
    const type = typeOf(node);
    if (!isContainerType(type)) {
        return 0;
    }
    const lists =
        type === 'columns'
            ? listProp(node, 'columns').map((column) =>
                  isObject(column) ? listProp(column, 'elements') : [],
              )
            : [listProp(node, 'elements')];
    let inner = 0;
    for (const list of lists) {
        for (const child of list) {
            inner = Math.max(inner, isObject(child) ? containerHeight(child) : 0);
        }
    }
    return 1 + inner;
}

interface WalkContext {
    readonly root: Definition;
    readonly nodes: Map<string, NodeEntry>;
    readonly lists: Map<string, ListEntry>;
    readonly pageId: string;
}

function walkList(ctx: WalkContext, container: Container, path: Path, depth: number): void {
    const list = getIn(ctx.root, path);
    const items = isList(list) ? list : [];
    const ids: string[] = [];
    const parentId = 'parentId' in container ? container.parentId : null;
    items.forEach((item, index) => {
        const id = isObject(item) ? idOf(item) : null;
        if (id === null || !isObject(item) || ctx.nodes.has(id)) {
            return;
        }
        ids.push(id);
        const nodePath = [...path, index];
        const entry: NodeEntry = {
            id,
            kind: 'element',
            node: item,
            path: nodePath,
            container,
            index,
            depth,
            parentId,
            pageId: ctx.pageId,
        };
        ctx.nodes.set(id, entry);
        walkOwnLists(ctx, item, nodePath, depth);
    });
    const key = containerKey(container);
    ctx.lists.set(key, { container, key, path, depth, ids });
}

function walkOwnLists(ctx: WalkContext, node: JsonObject, nodePath: Path, depth: number): void {
    for (const container of ownContainers(node)) {
        const path =
            container.kind === 'column'
                ? [...nodePath, 'columns', container.column, 'elements']
                : [...nodePath, 'elements'];
        walkList(ctx, container, path, depth + 1);
    }
}

const cache = new WeakMap<Definition, TreeIndex>();

/** Index of all pages, elements and lists; cached per definition object. */
export function indexTree(definition: Definition): TreeIndex {
    const cached = cache.get(definition);
    if (cached !== undefined) {
        return cached;
    }
    const nodes = new Map<string, NodeEntry>();
    const lists = new Map<string, ListEntry>();
    const pageIds: string[] = [];
    listProp(definition, 'pages').forEach((page, index) => {
        const id = isObject(page) ? idOf(page) : null;
        if (id === null || !isObject(page) || nodes.has(id)) {
            return;
        }
        pageIds.push(id);
        const path: Path = ['pages', index];
        nodes.set(id, {
            id,
            kind: 'page',
            node: page,
            path,
            container: { kind: 'pages' },
            index,
            depth: 0,
            parentId: null,
            pageId: id,
        });
        walkList(
            { root: definition, nodes, lists, pageId: id },
            { kind: 'page', pageId: id },
            [...path, 'elements'],
            1,
        );
    });
    lists.set('pages', {
        container: { kind: 'pages' },
        key: 'pages',
        path: ['pages'],
        depth: 0,
        ids: pageIds,
    });
    const index: TreeIndex = { nodes, lists };
    cache.set(definition, index);
    return index;
}

export function listEntry(definition: Definition, container: Container): ListEntry | null {
    return indexTree(definition).lists.get(containerKey(container)) ?? null;
}

export function nodeEntry(definition: Definition, id: string): NodeEntry | null {
    return indexTree(definition).nodes.get(id) ?? null;
}

/** True when `id` is `ancestorId` or lies inside its subtree. */
export function isWithin(definition: Definition, id: string, ancestorId: string): boolean {
    const nodes = indexTree(definition).nodes;
    let current = nodes.get(id) ?? null;
    while (current !== null) {
        if (current.id === ancestorId) {
            return true;
        }
        const parent: string | null =
            current.parentId ?? (current.kind === 'element' ? current.pageId : null);
        current = parent === null ? null : (nodes.get(parent) ?? null);
    }
    return false;
}

/** Ids of the enclosing repeaters of a node, outermost first. */
export function repeaterAncestors(definition: Definition, id: string): string[] {
    const nodes = indexTree(definition).nodes;
    const found: string[] = [];
    let parentId = nodes.get(id)?.parentId ?? null;
    while (parentId !== null) {
        const parent = nodes.get(parentId);
        if (parent === undefined) {
            break;
        }
        if (typeOf(parent.node) === 'repeater') {
            found.unshift(parent.id);
        }
        parentId = parent.parentId;
    }
    return found;
}

/** All ids in the subtree of a node (the node included). */
export function subtreeIds(node: JsonObject): string[] {
    const id = idOf(node);
    const ids = id === null ? [] : [id];
    for (const child of listProp(node, 'elements')) {
        ids.push(...(isObject(child) ? subtreeIds(child) : []));
    }
    for (const column of listProp(node, 'columns')) {
        for (const child of isObject(column) ? listProp(column, 'elements') : []) {
            ids.push(...(isObject(child) ? subtreeIds(child) : []));
        }
    }
    return ids;
}
