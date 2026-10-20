<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

/** The inputs of `fsa adopt` (plan U19, ADR 0087): the 1.0 checkout, the release to adopt into, and `--drop-jobs`. */
final readonly class AdoptRequest
{
    public function __construct(
        public string $checkout,
        public ?string $release = null,
        public bool $dropJobs = false,
        public int $callerUid = 0,
    ) {}

    /** @return array{checkout: string, release: string|null, drop_jobs: int} what the journal records */
    public function toContext(): array
    {
        return ['checkout' => $this->checkout, 'release' => $this->release, 'drop_jobs' => $this->dropJobs ? 1 : 0];
    }
}
