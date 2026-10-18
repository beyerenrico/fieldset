<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor\Checks;

use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\DoctorCheck;
use App\Domain\Instance\Doctor\DoctorContext;

/**
 * Memory against E16 (2 GB, ADR 0062), as the engine reports it (the VM on Docker Desktop). A "2 GB" VPS reports
 * about 1.9 GiB after the kernel's share, so the floor fails only well below that and warns close to it.
 */
final readonly class MemoryCheck implements DoctorCheck
{
    public const int FAIL_BELOW_MIB = 1536;

    public const int WARN_BELOW_MIB = 1800;

    public function key(): string
    {
        return 'memory';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $mib = intdiv($context->info()->memoryBytes, 1024 * 1024);
        $details = ['total_mib' => $mib, 'minimum_mib' => 2048];

        return match (true) {
            $mib <= 0 => CheckResult::warn($this->key(), 'The engine reports no memory size.', 'Make sure the host has at least 2 GB of RAM.', $details),
            $mib < self::FAIL_BELOW_MIB => CheckResult::fail($this->key(), "The engine has {$mib} MiB of memory; the minimum is 2 GB.", 'Use a host with at least 2 GB of RAM, or give Docker Desktop more memory.', $details),
            $mib < self::WARN_BELOW_MIB => CheckResult::warn($this->key(), "The engine has {$mib} MiB of memory, just at the 2 GB minimum.", 'Add swap or memory if PDF rendering or imports run out of memory.', $details),
            default => CheckResult::ok($this->key(), "{$mib} MiB of memory.", $details),
        };
    }
}
