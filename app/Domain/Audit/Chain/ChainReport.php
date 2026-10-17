<?php

declare(strict_types=1);

namespace App\Domain\Audit\Chain;

/**
 * Result of `audit:verify`: chains and entries checked, entries not sealed in time, every problem found, and
 * the head (last sequence number and hash) of each chain. Recording the heads outside the database (log
 * shipping, monitoring) is what makes the loss of a chain's newest entries detectable (ADR 0038).
 */
final readonly class ChainReport
{
    /**
     * @param  list<string>  $problems
     * @param  array<int, array{seq: int, hash: string}>  $heads
     */
    public function __construct(
        public int $chains,
        public int $entries,
        public int $unsealed,
        public array $problems,
        public array $heads = [],
    ) {}

    public function intact(): bool
    {
        return $this->problems === [];
    }
}
