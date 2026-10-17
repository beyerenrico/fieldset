/** Static import graph over source texts (agent B): which modules an entry can load at runtime. */

export interface ImportGraph {
    /** Source files reached from the entry (keys of `sources`). */
    readonly files: ReadonlySet<string>;
    /** Bare specifiers (packages, virtual modules) imported along the way. */
    readonly packages: ReadonlySet<string>;
}

const SPECIFIER =
    /(?:^|[\s;])(?:import|export)(\s+type)?\s[^'"]*?from\s*['"]([^'"]+)['"]|import\s*\(\s*['"]([^'"]+)['"]\s*\)|(?:^|[\s;])import\s+['"]([^'"]+)['"]/g;

function join(from: string, specifier: string): string {
    const parts = from.split('/').slice(0, -1);
    for (const part of specifier.split('/')) {
        if (part === '..') {
            parts.pop();
        } else if (part !== '.') {
            parts.push(part);
        }
    }
    return parts.join('/');
}

/** Runtime imports of one file (type-only imports are erased by the compiler). */
export function runtimeImports(source: string): string[] {
    return [...source.matchAll(SPECIFIER)].flatMap((match) =>
        match[1] === undefined ? [match[2] ?? match[3] ?? match[4] ?? ''].filter(Boolean) : [],
    );
}

export function importGraph(sources: Readonly<Record<string, string>>, entry: string): ImportGraph {
    const files = new Set<string>();
    const packages = new Set<string>();
    const queue = [entry];
    for (let file = queue.shift(); file !== undefined; file = queue.shift()) {
        const source = sources[file];
        if (files.has(file) || source === undefined) {
            continue;
        }
        files.add(file);
        for (const specifier of runtimeImports(source)) {
            if (specifier.startsWith('.')) {
                queue.push(join(file, specifier));
            } else {
                packages.add(specifier);
            }
        }
    }
    return { files, packages };
}
