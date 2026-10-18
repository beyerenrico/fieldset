<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor\Checks;

use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\DoctorCheck;
use App\Domain\Instance\Doctor\DoctorContext;

/**
 * Free space where the instance directory lives (backups land there), against E16's 20 GB disk (ADR 0062): images
 * take about 3 GB, so less than 4 GiB free fails and less than 10 GiB warns. The engine's image store is not
 * visible from the ops container; the pull reports a full disk there.
 */
final readonly class DiskCheck implements DoctorCheck
{
    public const int FAIL_BELOW_GIB = 4;

    public const int WARN_BELOW_GIB = 10;

    public function key(): string
    {
        return 'disk';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $free = $context->probes->diskFree($context->directory->root);
        if ($free === null) {
            return CheckResult::warn($this->key(), 'The free disk space cannot be read.', 'Make sure the host has at least 10 GB free.');
        }
        $gib = round($free / 1024 ** 3, 1);
        $details = ['free_gib' => $gib, 'path' => $context->directory->root];

        return match (true) {
            $gib < self::FAIL_BELOW_GIB => CheckResult::fail($this->key(), "Only {$gib} GiB free on the disk of the instance directory.", 'Free disk space (images need about 3 GB), then run the command again.', $details),
            $gib < self::WARN_BELOW_GIB => CheckResult::warn($this->key(), "{$gib} GiB free on the disk of the instance directory.", 'Backups and upgrades need more room; free some space soon.', $details),
            default => CheckResult::ok($this->key(), "{$gib} GiB free.", $details),
        };
    }
}
