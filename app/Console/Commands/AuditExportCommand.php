<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Platform\Instance\Reset\AuditExport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Streams the whole audit log as JSON Lines to stdout (AuditExport): `fieldset reset --no-backup` runs it in a socket-less
 * one-off and writes the stream into `backups/` (mode 0600) before the database is recreated (plan U23, ADR 0088).
 * Nothing but the export goes to stdout.
 */
#[Description('Write every audit entry with the chain heads as JSON Lines to stdout')]
#[Signature('instance:audit-export')]
final class AuditExportCommand extends Command
{
    public function handle(): int
    {
        AuditExport::write(fn (string $line) => $this->output->write($line, false, OutputInterface::OUTPUT_RAW));

        return self::SUCCESS;
    }
}
