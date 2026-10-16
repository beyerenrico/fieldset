<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Steps;

use App\Domain\Workflows\Engine\StepContext;
use App\Domain\Workflows\Engine\StepFailedException;
use App\Domain\Workflows\Engine\StepResult;
use Throwable;

/**
 * Executes one step type. Runs inside the run's organization, in a queued job: return a result
 * (completed or waiting), throw StepFailedException for a permanent failure (no retry), or any other
 * exception to retry with backoff. Handlers must be safe to re-run after a crash (use the step output
 * to remember what already happened).
 */
interface StepHandler
{
    /**
     * @throws StepFailedException
     * @throws Throwable
     */
    public function execute(StepContext $context): StepResult;
}
