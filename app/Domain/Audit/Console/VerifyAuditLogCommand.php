<?php

declare(strict_types=1);

namespace App\Domain\Audit\Console;

use App\Domain\Audit\Chain\ChainVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Verifies the audit hash chains (ADR 0038). Exit code 1 when an entry was changed, removed or inserted
 * outside the chain, or when entries older than ten minutes are still unsealed. `--heads` prints each chain's
 * head for external storage: comparing it with the next run detects the loss of a chain's newest entries.
 */
#[Description('Verify the tamper-evident hash chains of the audit log')]
#[Signature('audit:verify {--organization= : Verify only this organization\'s chain (0 = platform entries)} {--heads : Print each chain\'s head as JSON (ship it to external storage)}')]
final class VerifyAuditLogCommand extends Command
{
    public function handle(ChainVerifier $verifier): int
    {
        $option = $this->option('organization');
        $report = $verifier->verify($option === null ? null : (int) $option);

        foreach ($report->problems as $problem) {
            $this->components->error($problem);
        }
        if ((bool) $this->option('heads')) {
            // Always an object keyed by chain number: a plain array of chains 0, 1, 2 … would print as a JSON list.
            $this->line((string) json_encode(['verified_at' => now()->toIso8601String(), 'heads' => (object) $report->heads], JSON_UNESCAPED_SLASHES));
        }
        if (! $report->intact()) {
            return self::FAILURE;
        }

        $this->components->info("Verified {$report->entries} entries in {$report->chains} chains: intact.");

        return self::SUCCESS;
    }
}
