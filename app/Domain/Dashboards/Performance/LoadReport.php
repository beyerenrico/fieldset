<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Performance;

/** Row counts and duration of one load-generator run. */
final class LoadReport
{
    public int $submissions = 0;

    public int $values = 0;

    public int $tasks = 0;

    public int $renditions = 0;

    public int $runs = 0;

    public int $mails = 0;

    public float $seconds = 0.0;

    /** @return array<string, int|float> */
    public function toArray(): array
    {
        return [
            'submissions' => $this->submissions, 'submission_values' => $this->values, 'tasks' => $this->tasks,
            'renditions' => $this->renditions, 'workflow_runs' => $this->runs, 'mail_messages' => $this->mails,
            'seconds' => round($this->seconds, 2),
        ];
    }
}
