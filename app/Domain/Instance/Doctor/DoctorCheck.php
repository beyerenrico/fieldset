<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor;

/** One preflight or health check of `fieldset doctor` (R10). May throw: Doctor turns exceptions into a failed result. */
interface DoctorCheck
{
    /** Stable key in the JSON report (docker, architecture, memory …). */
    public function key(): string;

    public function run(DoctorContext $context): CheckResult;
}
