/** Resolves `signerNameFrom` by key through the scope chain (row first, then outer scopes). */
import type { ElementNode, ScopeNode } from '@form-schema-app/form-schema-js';
import type { RendererContextValue } from '../context.tsx';
import type { DataPath } from '../data/path.ts';
import { displayValue } from './useField.ts';

export function signerNameDefault(
    node: ElementNode,
    base: DataPath,
    context: RendererContextValue,
): string {
    const key = node.raw['signerNameFrom'];
    if (typeof key !== 'string') {
        return '';
    }
    let scope: ScopeNode | null = node.scope;
    let path = base;
    while (scope !== null) {
        if (scope.dataKeys.has(key)) {
            const value = displayValue(context, [...path, key]);
            return typeof value === 'string' ? value : '';
        }
        scope = scope.parent;
        path = path.slice(0, -2);
    }
    return '';
}
