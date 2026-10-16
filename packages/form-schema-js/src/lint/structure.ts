/** Structural lint rules: duplicates, bounds, patterns, reserved types, roles, empty pages. */
import { RESERVED_TYPES } from '../definition/element-types.ts';
import type { DefinitionModel, ElementNode, ScopeNode } from '../definition/model.ts';
import { isValidPattern } from '../definition/pattern.ts';
import { arrayProp, integerProp, isPlainObject, objectProp, stringProp } from '../json/types.ts';
import { knownRoles } from '../spec-assets.ts';
import { finding, type LintCode, type LintFinding } from './types.ts';

/** Reports every occurrence after the first of a value produced by `valueOf`. */
function laterDuplicates<T>(
    items: readonly T[],
    valueOf: (item: T) => string | undefined,
    report: (item: T, value: string) => LintFinding,
): LintFinding[] {
    const seen = new Set<string>();
    return items.flatMap((item) => {
        const value = valueOf(item);
        if (value === undefined) {
            return [];
        }
        const duplicate = seen.has(value);
        seen.add(value);
        return duplicate ? [report(item, value)] : [];
    });
}

function duplicateKeys(scope: ScopeNode): LintFinding[] {
    return laterDuplicates(
        scope.elements,
        (node) => (node.key === '' ? undefined : node.key),
        (node, key) => finding('duplicate_key', node.path, { key }),
    );
}

function duplicateRoles(scope: ScopeNode): LintFinding[] {
    return laterDuplicates(
        scope.elements,
        (node) => stringProp(node.raw, 'role'),
        (node, role) => finding('duplicate_role', `${node.path}/role`, { role }),
    );
}

function unknownRole(node: ElementNode): LintFinding[] {
    const role = stringProp(node.raw, 'role');
    return role === undefined || knownRoles.has(role)
        ? []
        : [finding('unknown_role', `${node.path}/role`, { role })];
}

function duplicateOptions(node: ElementNode): LintFinding[] {
    const options = arrayProp(node.raw, 'options') ?? [];
    const values = options.map((option, index) => ({
        index,
        value: isPlainObject(option) ? stringProp(option, 'value') : undefined,
    }));
    return laterDuplicates(
        values,
        (option) => option.value,
        (option, value) =>
            finding('duplicate_option_value', `${node.path}/options/${String(option.index)}`, {
                value,
            }),
    );
}

function repeaterBounds(node: ElementNode): LintFinding[] {
    const min = integerProp(node.raw, 'minItems');
    const max = integerProp(node.raw, 'maxItems');
    return node.type === 'repeater' && min !== undefined && max !== undefined && min > max
        ? [finding('invalid_repeater_bounds', node.path, { minItems: min, maxItems: max })]
        : [];
}

function invalidPattern(node: ElementNode): LintFinding[] {
    const pattern = stringProp(objectProp(node.raw, 'validation') ?? {}, 'pattern');
    return pattern !== undefined && !isValidPattern(pattern)
        ? [finding('invalid_pattern', `${node.path}/validation/pattern`, { pattern })]
        : [];
}

function reservedType(node: ElementNode): LintFinding[] {
    return RESERVED_TYPES.has(node.type)
        ? [finding('reserved_type', `${node.path}/type`, { type: node.type })]
        : [];
}

const ELEMENT_RULES: readonly ((node: ElementNode) => LintFinding[])[] = [
    reservedType,
    duplicateOptions,
    repeaterBounds,
    invalidPattern,
    unknownRole,
];

function pageFindings(model: DefinitionModel): LintFinding[] {
    const duplicates = laterDuplicates(
        model.pages,
        (page) => stringProp(page.raw, 'key'),
        (page, key) => finding('duplicate_page_key', page.path, { key }),
    );
    const empty = model.pages
        .filter((page) => (arrayProp(page.raw, 'elements') ?? []).length === 0)
        .map((page) => finding('empty_page', page.path));
    return [...duplicates, ...empty];
}

function duplicateIds(model: DefinitionModel): LintFinding[] {
    return laterDuplicates(
        model.nodes,
        (node) => (node.id === '' ? undefined : node.id.toLowerCase()),
        (node, id) => finding('duplicate_id' satisfies LintCode, node.path, { id }),
    );
}

export function structureFindings(model: DefinitionModel): LintFinding[] {
    return [
        ...pageFindings(model),
        ...model.scopes.flatMap(duplicateKeys),
        ...duplicateIds(model),
        ...model.nodes.flatMap((node) => ELEMENT_RULES.flatMap((rule) => rule(node))),
        ...model.scopes.flatMap(duplicateRoles),
    ];
}
