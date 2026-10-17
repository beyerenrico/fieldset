<?php

declare(strict_types=1);

namespace App\Domain\Audit\Console;

use App\Domain\Audit\Chain\AuditChain;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Seals audit entries whose after-commit sealing did not run (ADR 0038); scheduled every five minutes. */
#[Description('Seal audit log entries into their hash chain')]
#[Signature('audit:seal {--limit=5000 : Most entries to seal in this run}')]
final class SealAuditLogCommand extends Command
{
    public function handle(AuditChain $chain): int
    {
        $sealed = $chain->sealPending(max(1, (int) $this->option('limit')));
        $this->components->info("Sealed {$sealed} audit entries.");

        return self::SUCCESS;
    }
}
