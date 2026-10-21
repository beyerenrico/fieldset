/** Immutable definition model with scope information and JSON paths (spec §1–§3). */
import { decodeJson } from '../json/codec.ts';
import { arrayProp, isPlainObject, stringProp, toJson, type RawObject } from '../json/types.ts';
import { isLayoutType } from './element-types.ts';
import type { FormDefinition, FormElement, Page } from './types.ts';

/** The submission root or one repeater row. */
export interface ScopeNode {
    /** The repeater whose rows form this scope; `null` for the root scope. */
    readonly repeater: ElementNode | null;
    readonly parent: ScopeNode | null;
    /** Keys of the enclosing repeaters, outermost first (empty for the root scope). */
    readonly keyPath: readonly string[];
    /** Every element whose key lives in this scope (layout included), in document order. */
    readonly elements: readonly ElementNode[];
    /** First data element per key, in document order. */
    readonly dataKeys: ReadonlyMap<string, ElementNode>;
}

export interface ElementNode {
    readonly element: FormElement;
    /** The same element object for untyped, defensive property access. */
    readonly raw: RawObject;
    readonly type: string;
    readonly key: string;
    readonly id: string;
    /** JSON Pointer into the definition, e.g. `/pages/0/elements/2`. */
    readonly path: string;
    /** Position in pre-order document traversal. */
    readonly index: number;
    readonly page: number;
    /** Scope the element's key belongs to. */
    readonly scope: ScopeNode;
    /** Enclosing section, columns or repeater element. */
    readonly parent: ElementNode | null;
    /** Direct children (column contents flattened); repeater children live in `rowScope`. */
    readonly children: readonly ElementNode[];
    /** Row scope of a repeater. */
    readonly rowScope: ScopeNode | null;
    /** True for elements that carry data (everything except layout types). */
    readonly isData: boolean;
}

export interface PageNode {
    readonly page: Page;
    readonly raw: RawObject;
    readonly key: string;
    readonly path: string;
    readonly index: number;
    readonly elements: readonly ElementNode[];
}

export interface DefinitionModel {
    readonly definition: FormDefinition;
    readonly pages: readonly PageNode[];
    /** All elements in pre-order document order. */
    readonly nodes: readonly ElementNode[];
    readonly root: ScopeNode;
    readonly scopes: readonly ScopeNode[];
}

export class DefinitionError extends Error {
    override readonly name = 'DefinitionError';
}

type Mutable<T> = { -readonly [K in keyof T]: T[K] };
type MutableScope = Mutable<ScopeNode> & {
    elements: ElementNode[];
    dataKeys: Map<string, ElementNode>;
};
type MutableNode = Mutable<ElementNode> & { children: ElementNode[] };

interface BuildContext {
    readonly scope: MutableScope;
    readonly parent: ElementNode | null;
    readonly page: number;
    readonly nodes: ElementNode[];
    readonly scopes: MutableScope[];
}

const models = new WeakSet<DefinitionModel>();

function createScope(
    repeater: ElementNode | null,
    parent: MutableScope | null,
    scopes: MutableScope[],
): MutableScope {
    const keyPath = repeater && parent ? [...parent.keyPath, repeater.key] : [];
    const scope: MutableScope = { repeater, parent, keyPath, elements: [], dataKeys: new Map() };
    scopes.push(scope);
    return scope;
}

function buildList(
    list: readonly unknown[] | undefined,
    basePath: string,
    context: BuildContext,
): ElementNode[] {
    return (list ?? []).flatMap((item, index) =>
        isPlainObject(item) ? [buildNode(item, `${basePath}/${String(index)}`, context)] : [],
    );
}

function register(node: ElementNode, context: BuildContext): void {
    context.nodes.push(node);
    context.scope.elements.push(node);
    if (node.isData && node.key !== '' && !context.scope.dataKeys.has(node.key)) {
        context.scope.dataKeys.set(node.key, node);
    }
}

function buildChildren(node: MutableNode, context: BuildContext): ElementNode[] {
    const inner: BuildContext = { ...context, parent: node };
    if (node.type === 'repeater') {
        const rowScope = createScope(node, context.scope, context.scopes);
        node.rowScope = rowScope;
        return buildList(arrayProp(node.raw, 'elements'), `${node.path}/elements`, {
            ...inner,
            scope: rowScope,
        });
    }
    if (node.type === 'section') {
        return buildList(arrayProp(node.raw, 'elements'), `${node.path}/elements`, inner);
    }
    if (node.type === 'columns') {
        return (arrayProp(node.raw, 'columns') ?? []).flatMap((column, index) =>
            isPlainObject(column)
                ? buildList(
                      arrayProp(column, 'elements'),
                      `${node.path}/columns/${String(index)}/elements`,
                      inner,
                  )
                : [],
        );
    }
    return [];
}

function buildNode(raw: RawObject, path: string, context: BuildContext): ElementNode {
    const type = stringProp(raw, 'type') ?? '';
    const node: MutableNode = {
        element: raw as unknown as FormElement,
        raw,
        type,
        key: stringProp(raw, 'key') ?? '',
        id: stringProp(raw, 'id') ?? '',
        path,
        index: context.nodes.length,
        page: context.page,
        scope: context.scope,
        parent: context.parent,
        children: [],
        rowScope: null,
        isData: type !== '' && !isLayoutType(type),
    };
    register(node, context);
    node.children.push(...buildChildren(node, context));
    return node;
}

function buildPage(
    raw: RawObject,
    index: number,
    context: Omit<BuildContext, 'page' | 'parent'>,
): PageNode {
    const path = `/pages/${String(index)}`;
    const elements = buildList(arrayProp(raw, 'elements'), `${path}/elements`, {
        ...context,
        page: index,
        parent: null,
    });
    return {
        page: raw as unknown as Page,
        raw,
        key: stringProp(raw, 'key') ?? '',
        path,
        index,
        elements,
    };
}

function freezeModel(model: DefinitionModel): DefinitionModel {
    for (const node of model.nodes) {
        Object.freeze(node.children);
        Object.freeze(node);
    }
    for (const scope of model.scopes) {
        Object.freeze(scope.elements);
        Object.freeze(scope);
    }
    model.pages.forEach((page) => Object.freeze(page));
    Object.freeze(model.pages);
    Object.freeze(model.nodes);
    Object.freeze(model.scopes);
    return Object.freeze(model);
}

export function isDefinitionModel(value: unknown): value is DefinitionModel {
    return typeof value === 'object' && value !== null && models.has(value as DefinitionModel);
}

/**
 * Builds the immutable model of a definition (JSON text or parsed value). The definition is deep
 * copied and frozen; structurally unexpected parts are skipped (use `validateDefinition` for
 * meta-schema validation). Passing a model returns it unchanged.
 */
export function parseDefinition(input: unknown): DefinitionModel {
    if (isDefinitionModel(input)) {
        return input;
    }
    const value = typeof input === 'string' ? decodeJson(input) : input;
    if (!isPlainObject(value)) {
        throw new DefinitionError('A form definition must be a JSON object');
    }
    const definition = toJson(value) as RawObject;
    const scopes: MutableScope[] = [];
    const nodes: ElementNode[] = [];
    const root = createScope(null, null, scopes);
    const pages = (arrayProp(definition, 'pages') ?? []).flatMap((page, index) =>
        isPlainObject(page) ? [buildPage(page, index, { scope: root, nodes, scopes })] : [],
    );
    const model = freezeModel({
        definition: definition as unknown as FormDefinition,
        pages,
        nodes,
        root,
        scopes,
    });
    models.add(model);
    return model;
}
