/** Versioning rules against published history (spec §6, lint `key_locked` / `key_type_changed`). */
import { parseDefinition, type DefinitionModel, type ElementNode } from '../definition/model.ts';
import { isPlainObject } from '../json/types.ts';
import { finding, type LintFinding } from './types.ts';

interface History {
    /** Lowercased element id → keys it was published with. */
    readonly keysById: ReadonlyMap<string, ReadonlySet<string>>;
    /** Scoped key (`repeater.key`) → types it was published with. */
    readonly typesByKey: ReadonlyMap<string, ReadonlySet<string>>;
}

function scopedKey(node: ElementNode): string {
    return [...node.scope.keyPath, node.key].join('.');
}

function addTo(map: Map<string, Set<string>>, key: string, value: string): void {
    const values = map.get(key) ?? new Set<string>();
    values.add(value);
    map.set(key, values);
}

function buildHistory(published: readonly unknown[]): History {
    const keysById = new Map<string, Set<string>>();
    const typesByKey = new Map<string, Set<string>>();
    const models = published.filter(isPlainObject).map((definition) => parseDefinition(definition));
    for (const node of models.flatMap((model) => model.nodes)) {
        if (node.id !== '') {
            addTo(keysById, node.id.toLowerCase(), node.key);
        }
        if (node.key !== '') {
            addTo(typesByKey, scopedKey(node), node.type);
        }
    }
    return { keysById, typesByKey };
}

function nodeFindings(node: ElementNode, history: History): LintFinding[] {
    const publishedKeys = history.keysById.get(node.id.toLowerCase());
    const locked =
        node.id !== '' &&
        publishedKeys !== undefined &&
        [...publishedKeys].some((key) => key !== node.key);
    const publishedTypes = history.typesByKey.get(scopedKey(node));
    const changed =
        node.key !== '' &&
        publishedTypes !== undefined &&
        [...publishedTypes].some((type) => type !== node.type);
    return [
        ...(locked ? [finding('key_locked', `${node.path}/key`, { key: node.key })] : []),
        ...(changed
            ? [finding('key_type_changed', node.path, { key: node.key, type: node.type })]
            : []),
    ];
}

export function historyFindings(
    model: DefinitionModel,
    published: readonly unknown[],
): LintFinding[] {
    if (published.length === 0) {
        return [];
    }
    const history = buildHistory(published);
    return model.nodes.flatMap((node) => nodeFindings(node, history));
}
