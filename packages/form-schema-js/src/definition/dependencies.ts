/** Dependency graph between calculated elements (spec §5.4, lint `cycle`). */
import { getOwn } from '../json/types.ts';
import { pathLiteral, visitLogic } from '../logic/walk.ts';
import type { DefinitionModel, ElementNode } from './model.ts';
import { calculatedWithin, resolveReference } from './resolve.ts';

export type DependencyGraph = ReadonlyMap<ElementNode, readonly ElementNode[]>;

/**
 * Calculated elements an expression depends on: referenced calculated elements, plus every
 * calculated element inside a referenced repeater (row calculations before aggregates).
 * References inside per-item arguments of iteration operators resolve against items and are
 * ignored, as are computed paths and whole-scope references.
 */
export function dependenciesOf(node: ElementNode): ElementNode[] {
    const dependencies = new Set<ElementNode>();
    visitLogic(getOwn(node.raw, 'expression'), ({ op, args, inIteration }) => {
        const path = op === 'var' && !inIteration ? pathLiteral(args[0]) : null;
        if (path === null || path === '') {
            return;
        }
        const resolution = resolveReference(path, node.scope);
        if (resolution.kind !== 'element') {
            return;
        }
        const target = resolution.node;
        const found = target.type === 'repeater' ? calculatedWithin(target) : [target];
        found
            .filter((item) => item.type === 'calculated')
            .forEach((item) => dependencies.add(item));
    });
    return [...dependencies];
}

export function calculatedNodes(model: DefinitionModel): ElementNode[] {
    return model.nodes.filter((node) => node.type === 'calculated');
}

export function dependencyGraph(model: DefinitionModel): DependencyGraph {
    return new Map(calculatedNodes(model).map((node) => [node, dependenciesOf(node)]));
}

/** Tarjan's strongly connected components over the graph, visiting nodes in document order. */
function stronglyConnected(graph: DependencyGraph): ElementNode[][] {
    const index = new Map<ElementNode, number>();
    const low = new Map<ElementNode, number>();
    const stack: ElementNode[] = [];
    const onStack = new Set<ElementNode>();
    const components: ElementNode[][] = [];

    const connect = (node: ElementNode): void => {
        index.set(node, index.size);
        low.set(node, index.get(node) ?? 0);
        stack.push(node);
        onStack.add(node);
        for (const next of graph.get(node) ?? []) {
            if (!index.has(next)) {
                connect(next);
                low.set(node, Math.min(low.get(node) ?? 0, low.get(next) ?? 0));
            } else if (onStack.has(next)) {
                low.set(node, Math.min(low.get(node) ?? 0, index.get(next) ?? 0));
            }
        }
        if (low.get(node) === index.get(node)) {
            components.push(popComponent(stack, onStack, node));
        }
    };

    for (const node of graph.keys()) {
        if (!index.has(node)) {
            connect(node);
        }
    }
    return components;
}

function popComponent(
    stack: ElementNode[],
    onStack: Set<ElementNode>,
    root: ElementNode,
): ElementNode[] {
    const component: ElementNode[] = [];
    let member: ElementNode | undefined;
    do {
        member = stack.pop();
        if (member) {
            onStack.delete(member);
            component.push(member);
        }
    } while (member && member !== root);
    return component;
}

/** First calculated element (document order) of every dependency cycle. */
export function cycleStarts(graph: DependencyGraph): ElementNode[] {
    return stronglyConnected(graph)
        .filter((component) => {
            const [only] = component;
            return (
                component.length > 1 ||
                (only !== undefined && (graph.get(only) ?? []).includes(only))
            );
        })
        .map((component) =>
            component.reduce((first, node) => (node.index < first.index ? node : first)),
        )
        .sort((a, b) => a.index - b.index);
}

/**
 * Evaluation order: dependencies first, ties broken by document order. Members of a cycle (an
 * invalid definition) are emitted in document order once nothing else is ready.
 */
export function evaluationOrder(graph: DependencyGraph): ElementNode[] {
    const pending = [...graph.keys()].sort((a, b) => a.index - b.index);
    const done = new Set<ElementNode>();
    const order: ElementNode[] = [];
    while (pending.length > 0) {
        const readyAt = pending.findIndex((node) =>
            (graph.get(node) ?? []).every(
                (dependency) => dependency === node || done.has(dependency),
            ),
        );
        const [next] = pending.splice(readyAt === -1 ? 0 : readyAt, 1);
        if (next) {
            done.add(next);
            order.push(next);
        }
    }
    return order;
}
