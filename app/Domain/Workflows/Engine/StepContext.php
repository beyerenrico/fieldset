<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Engine;

use App\Domain\Identity\Models\Organization;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\Models\WorkflowStepRun;

/** What a step handler gets: the run, its step row, the step's configuration and the subject. */
final readonly class StepContext
{
    /** @param  array<string, mixed>  $config  the step's `data` from the run's definition snapshot */
    public function __construct(
        public WorkflowRun $run,
        public WorkflowStepRun $step,
        public array $config,
        public Organization $organization,
        public ?Submission $submission,
    ) {}

    /** The submission, or a permanent failure for runs without one (schedule, form.published). */
    public function requireSubmission(): Submission
    {
        return $this->submission ?? throw new StepFailedException(__('workflows.errors.needs_submission'));
    }

    public function string(string $key): ?string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function int(string $key): ?int
    {
        $value = $this->config[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    public function bool(string $key): bool
    {
        return filter_var($this->config[$key] ?? false, FILTER_VALIDATE_BOOL);
    }

    /** @return list<string> */
    public function strings(string $key): array
    {
        $values = $this->config[$key] ?? [];

        return is_array($values) ? array_values(array_filter(array_map(
            static fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '',
            $values,
        ), static fn (string $v): bool => $v !== '')) : [];
    }

    /** @return list<int> */
    public function ints(string $key): array
    {
        $values = $this->config[$key] ?? [];

        return is_array($values) ? array_values(array_unique(array_map(intval(...), array_filter($values, is_numeric(...))))) : [];
    }
}
