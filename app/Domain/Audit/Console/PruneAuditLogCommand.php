<?php

declare(strict_types=1);

namespace App\Domain\Audit\Console;

use App\Domain\Audit\Retention\PruneAuditLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Deletes audit entries older than audit.retention_days (ADR 0038); scheduled daily. */
#[Description('Delete audit log entries older than the retention period')]
#[Signature('audit:prune {--days= : Retention in days, at least 30 (default: audit.retention_days, 365)}')]
final class PruneAuditLogCommand extends Command
{
    public function handle(PruneAuditLog $prune): int
    {
        $option = $this->option('days');
        if ($option !== null && (filter_var($option, FILTER_VALIDATE_INT) === false || (int) $option < PruneAuditLog::MIN_DAYS)) {
            $this->components->error('--days must be an integer of at least '.PruneAuditLog::MIN_DAYS.'.');

            return self::FAILURE;
        }

        $days = $option === null ? PruneAuditLog::retentionDays() : (int) $option;
        $deleted = $prune->handle($days);
        $this->components->info("Deleted {$deleted} audit entries older than {$days} days.");

        return self::SUCCESS;
    }
}
