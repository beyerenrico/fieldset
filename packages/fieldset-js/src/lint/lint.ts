/** Linter entry point (spec §7). */
import { cycleStarts, dependencyGraph } from '../definition/dependencies.ts';
import { parseDefinition, type DefinitionModel } from '../definition/model.ts';
import { canonicalJson } from '../json/codec.ts';
import { historyFindings } from './history.ts';
import { logicFindings } from './logic.ts';
import { numberingFindings } from './numbering.ts';
import { structureFindings } from './structure.ts';
import { finding, type LintFinding } from './types.ts';

function cycleFindings(model: DefinitionModel): LintFinding[] {
    return cycleStarts(dependencyGraph(model)).map((node) =>
        finding('cycle', `${node.path}/expression`, { key: node.key }),
    );
}

function unique(findings: readonly LintFinding[]): LintFinding[] {
    const seen = new Set<string>();
    return findings.filter((item) => {
        const identity = canonicalJson(item);
        const fresh = !seen.has(identity);
        seen.add(identity);
        return fresh;
    });
}

/**
 * Lints a definition (raw JSON or parsed model). `published` are earlier published definitions
 * used for `key_locked` and `key_type_changed`. A definition with errors must not be published.
 */
export function lint(definition: unknown, published: readonly unknown[] = []): LintFinding[] {
    const model = parseDefinition(definition);
    return unique([
        ...structureFindings(model),
        ...logicFindings(model),
        ...cycleFindings(model),
        ...numberingFindings(model.definition),
        ...historyFindings(model, published),
    ]);
}

export function hasLintErrors(findings: readonly LintFinding[]): boolean {
    return findings.some((item) => item.severity === 'error');
}
