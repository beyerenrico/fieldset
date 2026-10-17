import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import type {
    BuilderConfig,
    InspectorScope,
    NodeChangedDetail,
    PublishedDetail,
    SyncResult,
} from '../src/contract.ts';

/** Reads a contract fixture; callers state the expected contract type. */
function fixture(name: string): unknown {
    // Vitest runs from the workspace root (resources/builder-v2).
    const file = resolve(process.cwd(), `../../tests/Fixtures/builder-v2/contract/${name}.json`);
    return JSON.parse(readFileSync(file, 'utf8'));
}

describe('contract fixtures', () => {
    it('describe the config, scope, sync result and events', () => {
        const config = fixture('config') as BuilderConfig;
        expect(config.definition).toEqual(fixture('definition'));
        expect(config.palette.flatMap((group) => group.items.map((item) => item.type))).toContain(
            'repeater',
        );
        expect((fixture('scope') as InspectorScope).rowKeys['items']).toEqual([
            'description',
            'qty',
        ]);
        expect((fixture('sync-result') as SyncResult).status).toBe('saved');
        const changed = fixture('node-changed') as NodeChangedDetail;
        expect(JSON.parse(changed.node)).toMatchObject({ id: changed.id, key: 'customer' });
        expect((fixture('published') as PublishedDetail).lockedIds).toHaveLength(2);
    });
});
