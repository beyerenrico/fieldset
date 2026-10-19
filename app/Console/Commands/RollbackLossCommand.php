<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\JsonResult;
use App\Domain\Platform\Instance\Restore\FinishRequest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * What a manual `fsa rollback` would lose (R37, plan U18, ADR 0086): the rows written since its safety backup, counted
 * in a socket-less one-off before the operator confirms. Counts only, never content.
 */
#[Description('Count what was written since a time, for fsa rollback\'s warning')]
#[Signature('instance:rollback-loss
        {--since= : The safety backup\'s time (ISO 8601 with zone)}
        {--json : Print one JSON document on stdout}')]
final class RollbackLossCommand extends Command
{
    private const string NAME = 'instance:rollback-loss';

    /** Table => what a person calls its rows; each counted by created_at after the time. */
    public const array COUNTED = ['submissions' => 'submissions', 'activity_log' => 'audit entries', 'users' => 'accounts', 'forms' => 'forms', 'mail_messages' => 'mails', 'attachments' => 'attachments'];

    public function handle(): int
    {
        try {
            $since = FinishRequest::backupTime($this->option('since')) ?? throw new InvalidArgumentException('--since is required.');
            $counts = [];
            foreach (array_keys(self::COUNTED) as $table) {
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    $counts[$table] = DB::table($table)->where('created_at', '>', $since->format('Y-m-d H:i:s'))->count();
                }
            }
        } catch (InvalidArgumentException $e) {
            return JsonResult::failed(self::NAME, 'invalid_input', $e->getMessage())->emit($this);
        } catch (Throwable $e) {
            return JsonResult::failed(self::NAME, 'count_failed', $e->getMessage())->emit($this);
        }

        return JsonResult::ok(self::NAME, ['since' => $since->toIso8601ZuluString(), 'counts' => $counts])->emit($this);
    }
}
