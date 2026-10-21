<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\ActorUid;
use App\Console\Support\JsonResult;
use App\Domain\Platform\Instance\Reset\ResetDatabase;
use App\Domain\Platform\Instance\Reset\ResetEpoch;
use App\Domain\Platform\Instance\Reset\ResetPlan;
use App\Domain\Platform\Instance\Restore\FinishRequest;
use App\Domain\Platform\Instance\Restore\RestoreConnections;
use App\Domain\Platform\Instance\Restore\RestoreRefused;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

/**
 * The database steps of `fieldset reset` (plan U23, KTD26, ADR 0088), each in a socket-less one-off of the app service (KTD25):
 *
 * - `plan`: read-only, the live database: organizations, the oldest real one, the active operators (ResetPlan);
 * - `recreate`: from the maintenance database, the database DB_DATABASE names dropped and created again (ResetDatabase);
 * - `epoch`: in the new, migrated database, the epoch seeded from the ledger and `instance.reset` with the old heads
 *   (stdin `{"previous_heads": {...}, "previous_epoch": int}`) as its first entry, once (ResetEpoch).
 *
 * One JSON document with --json; never a password.
 */
#[Description('Plan, recreate the database or begin the audit epoch of a fieldset reset')]
#[Signature('instance:reset
        {action : plan, recreate or epoch}
        {--epoch-seed=0 : epoch: the highest audit epoch the host-side ledger knows}
        {--actor-uid= : epoch: host UID of the operator running fieldset}
        {--safety-backup= : epoch: the safety backup that keeps the old data}
        {--audit-export= : epoch: the audit export written instead of a safety backup}
        {--demo : epoch: the reset adds the demo data}
        {--json : Print one JSON document on stdout}')]
final class ResetInstanceCommand extends Command
{
    /** Container key of a closure returning stdin (tests); the one-off reads STDIN. */
    public const string STDIN = 'instance.reset.stdin';

    private const string NAME = 'instance:reset';

    public function handle(ResetEpoch $epoch): int
    {
        $action = $this->argument('action');

        try {
            [$data, $changed] = match ($action) {
                'plan' => [ResetPlan::read(), false],
                'recreate' => [$this->recreate(), true],
                'epoch' => $this->epoch($epoch),
                default => throw new InvalidArgumentException('The action must be plan, recreate or epoch.'),
            };
        } catch (InvalidArgumentException $e) {
            return $this->refuse('invalid_input', $e->getMessage());
        } catch (RestoreRefused $e) {
            return $this->refuse($e->errorCode, $e->getMessage(), $e->hint);
        } catch (QueryException $e) {
            return $this->refuse('database_failed', 'The database step failed (SQLSTATE '.($e->errorInfo[0] ?? '?').'): '.($e->errorInfo[2] ?? 'no message'));
        }
        if ($this->option('json') === true) {
            return JsonResult::ok(self::NAME, $data, $changed)->emit($this);
        }
        $this->components->info(self::NAME.' '.$action.': done.');

        return self::SUCCESS;
    }

    /** @return array{database: string, dropped: bool, created: true} */
    private function recreate(): array
    {
        $live = RestoreConnections::current();

        try {
            return new ResetDatabase(RestoreConnections::maintenance())->recreate($live);
        } finally {
            RestoreConnections::close('fieldset_restore_maintenance');
        }
    }

    /** @return array{0: array{epoch: int, previous_epoch: int|null, activity_id: mixed, began: bool, sequences: int}, 1: bool} */
    private function epoch(ResetEpoch $epoch): array
    {
        $stdin = $this->stdin();
        $floors = $this->floors($stdin);
        $document = json_decode($stdin, true);
        $input = FinishRequest::stdin(is_array($document) ? (string) json_encode(array_diff_key($document, ['sequences' => true])) : $stdin);
        $seed = $this->option('epoch-seed');
        if (! is_string($seed) || preg_match('/^\d{1,9}$/D', $seed) !== 1) {
            throw new InvalidArgumentException('--epoch-seed must be a whole number.');
        }
        $data = $epoch->begin((int) $seed, $input['previous_heads'], $input['previous_epoch'], $this->backupName('safety-backup'), $this->backupName('audit-export'), $this->option('demo') === true, ActorUid::from($this->option('actor-uid')), $floors);

        return [$data, $data['began']];
    }

    /**
     * stdin's `sequences`: the old database's sequence values, by name (review S1).
     *
     * @return array<string, int>
     */
    private function floors(string $stdin): array
    {
        $document = trim($stdin) === '' ? [] : json_decode($stdin, true);
        $sequences = is_array($document) ? ($document['sequences'] ?? []) : [];
        if (! is_array($sequences)) {
            throw new InvalidArgumentException('stdin sequences must be an object of sequence names and values.');
        }
        foreach ($sequences as $name => $value) {
            if (! is_string($name) || preg_match(RestoreConnections::NAME, $name) !== 1 || ! is_int($value) || $value < 1) {
                throw new InvalidArgumentException('sequences must map sequence names to positive whole numbers.');
            }
        }

        return $sequences;
    }

    private function backupName(string $option): ?string
    {
        $value = $this->option($option);
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $value) !== 1) {
            throw new InvalidArgumentException("--{$option} must be a file name in backups/.");
        }

        return $value;
    }

    private function stdin(): string
    {
        $reader = $this->laravel->bound(self::STDIN)
            ? $this->laravel->make(self::STDIN)
            : static fn (): string => function_exists('posix_isatty') && posix_isatty(STDIN) ? '' : (string) stream_get_contents(STDIN, 1_048_576);

        return (string) $reader();
    }

    private function refuse(string $code, string $message, ?string $hint = null): int
    {
        if ($this->option('json') === true) {
            return JsonResult::failed(self::NAME, $code, $message, $hint)->emit($this);
        }
        $this->components->error($message);

        return self::FAILURE;
    }
}
