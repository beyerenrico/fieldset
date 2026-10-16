<?php

declare(strict_types=1);

namespace FormSchema\Definition;

/**
 * Dependencies between calculated elements (spec §5.4).
 *
 * A calculated element depends on every calculated element it references, and on every
 * calculated element inside a repeater it references (row calculations before aggregates).
 * Only the first key of a path counts; references inside iteration bodies, computed paths and
 * whole-scope references are ignored.
 */
final readonly class DependencyGraph
{
    /**
     * @param  array<string, Element>  $nodes  calculated elements by path, in document order
     * @param  array<string, list<string>>  $edges  path => dependency paths (document order)
     */
    private function __construct(
        public array $nodes,
        public array $edges,
    ) {}

    public static function build(Definition $definition): self
    {
        $resolver = new ReferenceResolver($definition);
        $nodes = [];
        $position = [];

        foreach ($definition->calculatedElements() as $index => $calculated) {
            $nodes[$calculated->path] = $calculated;
            $position[$calculated->path] = $index;
        }

        $edges = [];
        foreach ($nodes as $path => $calculated) {
            $dependencies = [];

            foreach (LogicReferences::paths($calculated->get('expression')) as $reference) {
                $element = $resolver->resolve($reference, $calculated->scopeId)->element;

                foreach ($element === null ? [] : self::calculatedWithin($definition, $element) as $dependency) {
                    $dependencies[$dependency->path] = $position[$dependency->path] ?? PHP_INT_MAX;
                }
            }

            asort($dependencies);
            $edges[$path] = array_map('strval', array_keys($dependencies));
        }

        return new self($nodes, $edges);
    }

    /**
     * Evaluation order: dependencies first, document order otherwise. Members of a cycle keep
     * the order in which the depth-first traversal reaches them.
     *
     * @return list<Element>
     */
    public function order(): array
    {
        $state = [];
        $ordered = [];

        foreach (array_keys($this->nodes) as $path) {
            $this->visit((string) $path, $state, $ordered);
        }

        return array_map(fn (string $path): Element => $this->nodes[$path], $ordered);
    }

    /**
     * The first calculated element (document order) of every dependency cycle.
     *
     * @return list<Element>
     */
    public function cycleHeads(): array
    {
        $heads = [];

        foreach (StronglyConnected::components(array_map('strval', array_keys($this->nodes)), $this->edges) as $component) {
            $first = $component[0];
            $isCycle = count($component) > 1 || in_array($first, $this->edges[$first] ?? [], true);

            if ($isCycle) {
                $heads[] = $this->nodes[$first];
            }
        }

        $paths = array_flip(array_map('strval', array_keys($this->nodes)));
        usort($heads, static fn (Element $a, Element $b): int => $paths[$a->path] <=> $paths[$b->path]);

        return $heads;
    }

    /**
     * @param  array<string, bool>  $state  path => done (false while on the stack)
     * @param  list<string>  $ordered
     */
    private function visit(string $path, array &$state, array &$ordered): void
    {
        if (array_key_exists($path, $state)) {
            return;
        }

        $state[$path] = false;

        foreach ($this->edges[$path] ?? [] as $dependency) {
            $this->visit($dependency, $state, $ordered);
        }

        $state[$path] = true;
        $ordered[] = $path;
    }

    /**
     * @return list<Element>
     */
    private static function calculatedWithin(Definition $definition, Element $element): array
    {
        if ($element->isCalculated()) {
            return [$element];
        }

        if (! $element->isRepeater()) {
            return [];
        }

        return array_values(array_filter(
            $definition->descendants($element),
            static fn (Element $e): bool => $e->isCalculated(),
        ));
    }
}
