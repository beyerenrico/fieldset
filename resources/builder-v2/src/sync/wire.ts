/**
 * Adapts Livewire's `$wire` to the BuilderWire contract (so tests can fake it) and validates the
 * answers: the server is external input, a malformed answer becomes a WireError.
 */
import type {
    BuilderWire,
    InspectorKind,
    LintFinding,
    LintReport,
    SyncResult,
} from '../contract.ts';

export class WireError extends Error {
    override readonly name = 'WireError';
}

/** The parts of Livewire's `$wire` proxy we use: component methods return promises. */
export type LivewireProxy = Readonly<Record<string, unknown>>;

const STATUSES: ReadonlySet<string> = new Set(['saved', 'unchanged', 'invalid', 'conflict']);

function isRecord(value: unknown): value is Readonly<Record<string, unknown>> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function list(value: unknown): readonly unknown[] {
    return Array.isArray(value) ? value : [];
}

function parseFinding(value: unknown): LintFinding | null {
    if (!isRecord(value) || typeof value.code !== 'string' || typeof value.path !== 'string') {
        return null;
    }
    return {
        severity: value.severity === 'error' ? 'error' : 'warning',
        code: value.code,
        path: value.path,
    };
}

export function parseLint(value: unknown): LintReport {
    const source = isRecord(value) ? value : {};
    const schemaErrors = list(source.schema_errors).flatMap((error) =>
        isRecord(error) && typeof error.path === 'string'
            ? [
                  {
                      path: error.path,
                      message: typeof error.message === 'string' ? error.message : '',
                  },
              ]
            : [],
    );
    const findings = list(source.findings).flatMap((finding) => {
        const parsed = parseFinding(finding);
        return parsed === null ? [] : [parsed];
    });
    return { schema_errors: schemaErrors, findings };
}

export function parseSyncResult(value: unknown): SyncResult {
    if (!isRecord(value) || typeof value.status !== 'string' || !STATUSES.has(value.status)) {
        throw new WireError('Malformed sync result');
    }
    if (typeof value.checksum !== 'string') {
        throw new WireError('Sync result without checksum');
    }
    return {
        status: value.status as SyncResult['status'],
        checksum: value.checksum,
        lint: parseLint(value.lint),
        savedAt: typeof value.savedAt === 'string' ? value.savedAt : null,
    };
}

function method(proxy: LivewireProxy, name: string): (...args: unknown[]) => Promise<unknown> {
    const fn = proxy[name];
    if (typeof fn !== 'function') {
        throw new WireError(`Livewire method ${name} is missing`);
    }
    return (...args: unknown[]) =>
        Promise.resolve((fn as (...a: unknown[]) => unknown).apply(proxy, args));
}

export function createWire(proxy: LivewireProxy): BuilderWire {
    const call = (name: string, ...args: unknown[]): Promise<unknown> =>
        method(proxy, name)(...args);
    return {
        sync: async (definition, baseChecksum, force = false) =>
            parseSyncResult(await call('sync', definition, baseChecksum, force)),
        save: async (definition, baseChecksum, force = false) =>
            parseSyncResult(await call('save', definition, baseChecksum, force)),
        inspect: async (kind: InspectorKind, id, node, scope) => {
            await call('inspect', kind, id, node, scope);
        },
        commitInspector: async (scope) => {
            await call('commitInspector', scope);
        },
        cloneNode: async (node, scopeKeys) => {
            const result = await call('cloneNode', node, scopeKeys);
            if (typeof result !== 'string') {
                throw new WireError('cloneNode must return a JSON string');
            }
            return result;
        },
        mountAction: async (name, args) => {
            await call('mountAction', name, args);
        },
    };
}
