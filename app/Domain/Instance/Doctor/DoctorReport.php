<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor;

/** The results of one doctor run, in check order. */
final readonly class DoctorReport
{
    /** @param  list<CheckResult>  $results */
    public function __construct(public array $results) {}

    public function passed(): bool
    {
        return $this->failed() === [];
    }

    /** @return list<CheckResult> */
    public function failed(): array
    {
        return $this->withStatus(CheckStatus::Fail);
    }

    /** @return list<CheckResult> warnings and unknown results (which count like warnings), in check order */
    public function warnings(): array
    {
        return array_values(array_filter($this->results, static fn (CheckResult $result): bool => in_array($result->status, [CheckStatus::Warn, CheckStatus::Unknown], true)));
    }

    public function result(string $key): ?CheckResult
    {
        return array_find($this->results, static fn (CheckResult $result): bool => $result->key === $key);
    }

    /** @return list<array{key: string, status: string, summary: string, hint: string|null, details: array<string, mixed>}> */
    public function toArray(): array
    {
        return array_map(static fn (CheckResult $result): array => $result->toArray(), $this->results);
    }

    /** @return list<CheckResult> */
    private function withStatus(CheckStatus $status): array
    {
        return array_values(array_filter($this->results, static fn (CheckResult $result): bool => $result->status === $status));
    }
}
