<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\JsonResult;
use App\Domain\Platform\Instance\Restore\FinishRequest;
use App\Domain\Platform\Instance\Restore\SecurityExport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The current release's security state as a versioned document (SecurityExport) for `fsa rollback` (R64, plan U18,
 * ADR 0086): run as a socket-less one-off of the current image before the database is replaced, read by the previous
 * image's `instance:restore-finish`. Stdout carries one JSON document; fsa writes it only to a 0600 file.
 */
#[Description('Export the security state for fsa rollback (password hashes, MFA, access, sequences)')]
#[Signature('instance:security-export
        {--since= : The safety backup\'s time (ISO 8601 with zone): audit entries after it are exported}
        {--json : Print one JSON document on stdout}')]
final class SecurityExportCommand extends Command
{
    private const string NAME = 'instance:security-export';

    public function handle(): int
    {
        try {
            $db = DB::connection();
            if (! $db instanceof Connection) {
                throw new InvalidArgumentException('The export needs a database connection.');
            }
            $document = new SecurityExport($db)->export(FinishRequest::backupTime($this->option('since')));
        } catch (InvalidArgumentException $e) {
            return JsonResult::failed(self::NAME, 'invalid_input', $e->getMessage())->emit($this);
        } catch (Throwable $e) {
            return JsonResult::failed(self::NAME, 'export_failed', $e->getMessage())->emit($this);
        }

        return JsonResult::ok(self::NAME, $document)->emit($this);
    }
}
