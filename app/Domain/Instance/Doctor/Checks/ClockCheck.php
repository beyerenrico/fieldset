<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor\Checks;

use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\DoctorCheck;
use App\Domain\Instance\Doctor\DoctorContext;

/**
 * Clock skew (R10) against the `Date` header of the registry the install talks to anyway (§10: no outbound call
 * just for the time). More than 30 s warns (MFA codes start failing), more than 5 minutes fails (certificates and
 * signed links). Without a reference it is skipped.
 */
final readonly class ClockCheck implements DoctorCheck
{
    public const int WARN_SECONDS = 30;

    public const int FAIL_SECONDS = 300;

    public function key(): string
    {
        return 'clock';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $reference = $context->registryDate === null ? false : strtotime($context->registryDate);
        if ($reference === false) {
            return CheckResult::skipped($this->key(), 'No reference clock: fsa contacts no server just for the time.');
        }
        $skew = $context->probes->now() - $reference;
        $details = ['skew_seconds' => $skew];
        $summary = 'The clock is '.abs($skew).' s '.($skew >= 0 ? 'ahead of' : 'behind').' the registry.';
        $hint = 'Enable time synchronisation on the host (e.g. systemd-timesyncd or chrony).';

        return match (true) {
            abs($skew) > self::FAIL_SECONDS => CheckResult::fail($this->key(), $summary, $hint, $details),
            abs($skew) > self::WARN_SECONDS => CheckResult::warn($this->key(), $summary, $hint, $details),
            default => CheckResult::ok($this->key(), $summary, $details),
        };
    }
}
