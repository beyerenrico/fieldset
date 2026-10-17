/** Reads and checks the BuilderConfig embedded as `<script type="application/json" data-builder-config>`. */
import type { JsonObject } from '@form-schema-app/form-schema-js';
import type { BuilderConfig, PaletteGroup, PaletteItem } from './contract.ts';
import { isObject, str } from './store/json.ts';

export class ConfigError extends Error {
    override readonly name = 'ConfigError';
}

function paletteItem(value: unknown): PaletteItem | null {
    if (!isObject(value) || !isObject(value.node) || str(value.type) === null) {
        return null;
    }
    return {
        type: str(value.type) ?? '',
        label: str(value.label) ?? str(value.type) ?? '',
        icon: str(value.icon) ?? '',
        node: value.node,
    };
}

function paletteGroups(value: unknown): PaletteGroup[] {
    return (Array.isArray(value) ? value : []).flatMap((group: unknown) => {
        if (!isObject(group)) {
            return [];
        }
        const items = (Array.isArray(group.items) ? group.items : []).flatMap((item: unknown) => {
            const parsed = paletteItem(item);
            return parsed === null ? [] : [parsed];
        });
        return [{ group: str(group.group) ?? 'group', label: str(group.label) ?? '', items }];
    });
}

export function parseConfig(value: unknown): BuilderConfig {
    if (!isObject(value) || !isObject(value.definition) || typeof value.checksum !== 'string') {
        throw new ConfigError('Builder v2: the embedded config has no definition or checksum');
    }
    const urls: JsonObject = isObject(value.urls) ? value.urls : {};
    const depth = value.maxContainerDepth;
    return {
        formId: typeof value.formId === 'number' ? value.formId : 0,
        definition: value.definition,
        checksum: value.checksum,
        lockedIds: (Array.isArray(value.lockedIds) ? value.lockedIds : []).filter(
            (id): id is string => typeof id === 'string',
        ),
        maxContainerDepth:
            typeof depth === 'number' && Number.isInteger(depth) && depth > 0 ? depth : 3,
        palette: paletteGroups(value.palette),
        urls: { preview: str(urls.preview) ?? '/form-preview/', classic: str(urls.classic) ?? '' },
    };
}

export function readConfig(root: ParentNode): BuilderConfig {
    const script = root.querySelector('script[data-builder-config]');
    try {
        return parseConfig(JSON.parse(script?.textContent ?? 'null'));
    } catch (error) {
        throw error instanceof ConfigError
            ? error
            : new ConfigError('Builder v2: the embedded config is not valid JSON');
    }
}
