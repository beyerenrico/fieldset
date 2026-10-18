<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor\Checks;

use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\DoctorCheck;
use App\Domain\Instance\Doctor\DoctorContext;

/**
 * Path sharing (KTD2): the launcher wrote a nonce file that this container had to see before the command ran
 * (LaunchGuard, exit 3 otherwise). Reaching this check means it was visible.
 */
final readonly class PathShareCheck implements DoctorCheck
{
    public function key(): string
    {
        return 'path_sharing';
    }

    public function run(DoctorContext $context): CheckResult
    {
        return $context->facts->launched()
            ? CheckResult::ok($this->key(), 'The Docker engine shares the instance directory with fsa.', ['dir' => $context->directory->root, 'mounts' => array_keys($context->facts->mounts)])
            : CheckResult::skipped($this->key(), 'Not started through the fsa command or install.sh, which write the nonce this check needs.');
    }
}
