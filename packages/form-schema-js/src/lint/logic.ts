/** Logic lint rules: references, operators, decimal misuse (spec §7). */
import type { DefinitionModel, ElementNode, ScopeNode } from '../definition/model.ts';
import { isNumericElement, resolveReference, targetElement } from '../definition/resolve.ts';
import { getOwn, hasOwn, isJsonInteger, stringProp, type RawObject } from '../json/types.ts';
import { KNOWN_OPERATORS } from '../logic/evaluator.ts';
import {
    BUILTIN_NUMERIC_OPERATORS,
    DECIMAL_OPERATORS,
    ROW_KEY_OPERATORS,
} from '../logic/operation.ts';
import { literalVarPath, pathLiteral, visitLogic, type OperationVisit } from '../logic/walk.ts';
import { finding, type LintFinding } from './types.ts';

const ELEMENT_LOGIC = ['visibleIf', 'requiredIf', 'readOnlyIf', 'default', 'expression'] as const;

type Rule = (visit: OperationVisit, scope: ScopeNode, path: string) => LintFinding | null;

const unknownOperator: Rule = ({ op }, _scope, path) =>
    KNOWN_OPERATORS.has(op) ? null : finding('unknown_operator', path, { operator: op });

const unknownVar: Rule = ({ op, args, inIteration }, scope, path) => {
    const reference = op === 'var' && !inIteration ? pathLiteral(args[0]) : null;
    return reference !== null && resolveReference(reference, scope).kind === 'unknown'
        ? finding('unknown_reference', path, { reference })
        : null;
};

const floatLiteral: Rule = ({ op, args }, _scope, path) =>
    DECIMAL_OPERATORS.has(op) && args.some((arg) => typeof arg === 'number' && !isJsonInteger(arg))
        ? finding('float_literal', path, { operator: op })
        : null;

/** `sum`/`min`/`max` over a repeater: the row key must be defined in the repeater's rows. */
const unknownRowKey: Rule = ({ op, args, inIteration }, scope, path) => {
    const [list, rowKey] = args;
    const listPath = ROW_KEY_OPERATORS.has(op) && !inIteration ? literalVarPath(list) : null;
    if (listPath === null || typeof rowKey !== 'string') {
        return null;
    }
    const repeater = targetElement(resolveReference(listPath, scope));
    const rows = repeater?.rowScope ?? null;
    return rows !== null && !rows.dataKeys.has(rowKey)
        ? finding('unknown_reference', path, { reference: rowKey })
        : null;
};

function isNumericVar(operand: unknown, scope: ScopeNode): boolean {
    const reference = literalVarPath(operand);
    if (reference === null || reference === '') {
        return false;
    }
    const target = targetElement(resolveReference(reference, scope));
    return target !== null && isNumericElement(target);
}

const decimalBuiltin: Rule = ({ op, args, inIteration }, scope, path) =>
    BUILTIN_NUMERIC_OPERATORS.has(op) &&
    !inIteration &&
    args.some((arg) => isNumericVar(arg, scope))
        ? finding('decimal_builtin_operator', path, { operator: op })
        : null;

const RULES: readonly Rule[] = [
    unknownOperator,
    unknownVar,
    floatLiteral,
    unknownRowKey,
    decimalBuiltin,
];

/** Lints one logic property; `path` points at the property itself. */
export function lintLogic(logic: unknown, scope: ScopeNode, path: string): LintFinding[] {
    const findings: LintFinding[] = [];
    visitLogic(logic, (visit) => {
        for (const rule of RULES) {
            const found = rule(visit, scope, path);
            if (found !== null) {
                findings.push(found);
            }
        }
    });
    return findings;
}

function logicProperties(
    raw: RawObject,
    names: readonly string[],
    scope: ScopeNode,
    base: string,
): LintFinding[] {
    return names
        .filter((name) => hasOwn(raw, name))
        .flatMap((name) => lintLogic(getOwn(raw, name), scope, `${base}/${name}`));
}

function signerReference(node: ElementNode): LintFinding[] {
    const key = node.type === 'signature' ? stringProp(node.raw, 'signerNameFrom') : undefined;
    if (key === undefined) {
        return [];
    }
    const resolution = resolveReference(key, node.scope);
    return resolution.kind === 'element' && resolution.rest.length === 0
        ? []
        : [finding('unknown_reference', `${node.path}/signerNameFrom`, { reference: key })];
}

export function logicFindings(model: DefinitionModel): LintFinding[] {
    const pages = model.pages.flatMap((page) =>
        logicProperties(page.raw, ['visibleIf'], model.root, page.path),
    );
    const elements = model.nodes.flatMap((node) => [
        ...logicProperties(node.raw, ELEMENT_LOGIC, node.scope, node.path),
        ...signerReference(node),
    ]);
    return [...pages, ...elements];
}
