import { canonicalJson, type JsonObject } from '@fieldset/fieldset-js';
import { basename } from 'node:path';
import { describe, expect, it } from 'vitest';
import { applyOperation } from '../src/store/ops.ts';
import { checkOperation } from '../src/store/rules.ts';
import { BuilderStore } from '../src/store/store.ts';
import { indexTree, listEntry } from '../src/store/tree.ts';
import {
    contractConfig,
    contractDefinition,
    deepFreeze,
    readJson,
    templateFiles,
} from './support/fixtures.ts';

const SOURCES: [string, () => JsonObject][] = [
    ...templateFiles().map((file): [string, () => JsonObject] => [
        basename(file),
        () => deepFreeze(readJson(file)),
    ]),
    ['contract definition', contractDefinition],
    ['contract config definition', () => deepFreeze(contractConfig().definition)],
];

function serialize(store: BuilderStore): JsonObject {
    return JSON.parse(JSON.stringify(store.state.definition)) as JsonObject;
}

describe('fidelity', () => {
    it.each(SOURCES)('%s: load and serialize keeps the canonical JSON', (_name, load) => {
        const original = load();
        const store = new BuilderStore(original, { maxContainerDepth: 3 });
        expect(store.state.definition).toBe(original);
        expect(canonicalJson(serialize(store))).toBe(canonicalJson(original));
    });

    it.each(SOURCES)('%s: insert then remove gives the same definition', (_name, load) => {
        const original = load();
        const node = {
            id: '0192f1c4-0000-7000-8000-0000000000ff',
            type: 'text',
            key: 'probe',
            label: 'Probe',
        };
        for (const list of indexTree(original).lists.values()) {
            if (list.container.kind === 'pages') {
                continue;
            }
            const inserted = applyOperation(original, {
                op: 'insert',
                target: list.container,
                index: list.ids.length,
                node,
            });
            const removed = applyOperation(inserted, { op: 'remove', id: node.id });
            expect(canonicalJson(removed)).toBe(canonicalJson(original));
        }
    });

    it.each(SOURCES)('%s: move and move back gives the same definition', (_name, load) => {
        const original = load();
        const firstPage = [...indexTree(original).nodes.values()].find(
            (entry) => entry.kind === 'page',
        )!;
        for (const entry of indexTree(original).nodes.values()) {
            if (entry.kind === 'page') {
                continue;
            }
            const there = {
                op: 'move',
                id: entry.id,
                target: { kind: 'page', pageId: firstPage.id },
                index: 0,
            } as const;
            if (checkOperation(original, there, 3) !== null) {
                continue;
            }
            const moved = applyOperation(original, there);
            const position = listEntry(original, entry.container)!.ids.indexOf(entry.id);
            const back = applyOperation(moved, {
                op: 'move',
                id: entry.id,
                target: entry.container,
                index: position,
            });
            expect(canonicalJson(back)).toBe(canonicalJson(original));
        }
    });
});
