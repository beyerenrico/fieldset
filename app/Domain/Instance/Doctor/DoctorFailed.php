<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;

/**
 * A failed preflight (exit 3; exit 10 when Docker itself is too old, §7). Nothing was changed. The whole report
 * travels under `data.checks`, the first failure's fix is the hint.
 */
final class DoctorFailed extends InstanceException
{
    public function __construct(public readonly DoctorReport $report)
    {
        $failed = $report->failed();
        $first = $failed[0] ?? null;
        $docker = array_any($failed, static fn (CheckResult $result): bool => ($result->details['too_old'] ?? false) === true);
        parent::__construct(
            'Preflight failed: '.implode('; ', array_map(static fn (CheckResult $result): string => $result->key.': '.rtrim($result->summary, '.'), $failed)).'.',
            $docker ? ExitCode::DockerUnavailable : ExitCode::PreflightFailed,
            $docker ? 'docker_too_old' : 'doctor_failed',
            $first?->hint,
            false,
            ['checks' => $report->toArray()],
        );
    }
}
