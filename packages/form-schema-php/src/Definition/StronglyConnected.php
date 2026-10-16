<?php

declare(strict_types=1);

namespace FormSchema\Definition;

/**
 * Tarjan's strongly connected components. Each component lists its nodes in input order.
 */
final class StronglyConnected
{
    private int $counter = 0;

    /** @var array<string, int> */
    private array $index = [];

    /** @var array<string, int> */
    private array $low = [];

    /** @var list<string> */
    private array $stack = [];

    /** @var array<string, bool> */
    private array $onStack = [];

    /** @var list<list<string>> */
    private array $components = [];

    /**
     * @param  list<string>  $nodes
     * @param  array<string, list<string>>  $edges
     */
    private function __construct(
        private readonly array $nodes,
        private readonly array $edges,
    ) {}

    /**
     * @param  list<string>  $nodes
     * @param  array<string, list<string>>  $edges
     * @return list<list<string>>
     */
    public static function components(array $nodes, array $edges): array
    {
        $search = new self($nodes, $edges);

        foreach ($nodes as $node) {
            if (! isset($search->index[$node])) {
                $search->connect($node);
            }
        }

        return $search->components;
    }

    private function connect(string $node): void
    {
        $this->index[$node] = $this->counter;
        $this->low[$node] = $this->counter;
        $this->counter++;
        $this->stack[] = $node;
        $this->onStack[$node] = true;

        foreach ($this->edges[$node] ?? [] as $next) {
            if (! isset($this->index[$next])) {
                $this->connect($next);
                $this->low[$node] = min($this->low[$node], $this->low[$next]);
            } elseif ($this->onStack[$next] ?? false) {
                $this->low[$node] = min($this->low[$node], $this->index[$next]);
            }
        }

        if ($this->low[$node] === $this->index[$node]) {
            $this->components[] = $this->pop($node);
        }
    }

    /**
     * @return list<string>
     */
    private function pop(string $root): array
    {
        $component = [];

        do {
            $member = array_pop($this->stack);
            assert($member !== null);
            $this->onStack[$member] = false;
            $component[] = $member;
        } while ($member !== $root);

        $order = array_flip($this->nodes);
        usort($component, static fn (string $a, string $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));

        return $component;
    }
}
