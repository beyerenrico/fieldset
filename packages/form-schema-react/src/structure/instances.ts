/** Structural walk of a definition model over the data: one entry per element instance. */
import type {
    DefinitionModel,
    ElementNode,
    JsonObject,
    JsonValue,
} from '@form-schema-app/form-schema-js';
import { getAt, toPointer, type DataPath } from '../data/path.ts';

export interface FieldInstance {
    readonly node: ElementNode;
    readonly path: DataPath;
    readonly pointer: string;
    /** Index of the page in `model.pages`. */
    readonly page: number;
}

/** Number of rows to render for a repeater: raw rows, else evaluated rows (e.g. a default). */
export function rowCount(raw: JsonValue | undefined, evaluated: JsonValue | undefined): number {
    if (Array.isArray(raw)) {
        return raw.length;
    }
    return Array.isArray(evaluated) ? evaluated.length : 0;
}

function collect(
    nodes: readonly ElementNode[],
    base: DataPath,
    raw: JsonObject,
    evaluated: JsonObject,
    out: FieldInstance[],
): void {
    for (const node of nodes) {
        const path = [...base, node.key];
        out.push({ node, path, pointer: toPointer(path), page: node.page });
        if (node.rowScope !== null) {
            const count = rowCount(getAt(raw, path), getAt(evaluated, path));
            for (let index = 0; index < count; index++) {
                collect(node.children, [...path, index], raw, evaluated, out);
            }
        } else {
            collect(node.children, base, raw, evaluated, out);
        }
    }
}

/** Every element instance in document order, repeater rows in index order. */
export function listInstances(
    model: DefinitionModel,
    raw: JsonObject,
    evaluated: JsonObject,
): FieldInstance[] {
    const out: FieldInstance[] = [];
    for (const page of model.pages) {
        collect(page.elements, [], raw, evaluated, out);
    }
    return out;
}

/** Indexes of pages with at least one visible top-level element. */
export function visiblePageIndexes(
    model: DefinitionModel,
    visibility: Readonly<Record<string, boolean>>,
): number[] {
    return model.pages
        .filter((page) => page.elements.some((node) => visibility[toPointer([node.key])] === true))
        .map((page) => page.index);
}

const INPUT_LAYOUT = new Set(['heading', 'paragraph', 'divider', 'image', 'page_break']);

/** Data and layout-container descendants usable as table columns (sections/columns flattened). */
export function flattenColumns(nodes: readonly ElementNode[]): ElementNode[] {
    return nodes.flatMap((node) => {
        if (node.type === 'section' || node.type === 'columns') {
            return flattenColumns(node.children);
        }
        return INPUT_LAYOUT.has(node.type) || node.type === 'hidden' ? [] : [node];
    });
}

/** First focusable input of a row (for focusing new rows). */
export function firstInput(nodes: readonly ElementNode[]): ElementNode | undefined {
    return flattenColumns(nodes).find(
        (node) => node.isData && node.type !== 'calculated' && node.rowScope === null,
    );
}
