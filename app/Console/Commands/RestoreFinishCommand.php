<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\ActorUid;
use App\Console\Support\JsonResult;
use App\Domain\Platform\Instance\BackupRole;
use App\Domain\Platform\Instance\Restore\FinishRequest;
use App\Domain\Platform\Instance\Restore\FinishRestore;
use App\Domain\Platform\Instance\Restore\RestoreConnections;
use App\Domain\Platform\Instance\Restore\RestoreRefused;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Prepares a restored database for the swap (`fsa restore`, `fsa install --from-backup`; plan U17, ADR 0085) in a
 * socket-less one-off whose DB_DATABASE is that database (FinishRestore): new audit epoch and `instance.restored`,
 * the R64 carry and the sequence floors from `--source`, stale credentials cleared, grants, decrypt sample. fsa passes
 * the ledger's final heads on stdin and the backup role's password in FSA_BACKUP_DB_PASSWORD (never an argument).
 */
#[Description('Prepare a restored database for the swap: audit epoch, security-state carry, sequences, grants, decrypt check')]
#[Signature('instance:restore-finish
        {--source= : The pre-restore database (omit for fsa install --from-backup: nothing to carry)}
        {--backup= : The backup the database was restored from (recorded in the audit entry)}
        {--epoch-seed=0 : The highest audit epoch the host-side ledger knows}
        {--actor-uid= : Host UID of the operator running fsa}
        {--reason=restored : restored, or rolled_back (U18)}
        {--no-carry : Skip the security-state carry and the sequence floors (automatic rollback)}
        {--backup-role=fsa_backup : The backup service\'s database role}
        {--json : Print one JSON document on stdout}')]
final class RestoreFinishCommand extends Command
{
    /** Container key of a closure returning stdin (tests); the one-off reads STDIN. */
    public const string STDIN = 'instance.restore-finish.stdin';

    private const string NAME = 'instance:restore-finish';

    public function handle(FinishRestore $finish): int
    {
        try {
            $password = getenv(BackupRole::PASSWORD_ENV);
            if (! is_string($password) || $password === '') {
                throw new RestoreRefused('password_missing', BackupRole::PASSWORD_ENV.' is not set.');
            }
            $data = $finish->run($this->request(), $password);
        } catch (InvalidArgumentException $e) {
            return $this->refuse('invalid_input', $e->getMessage());
        } catch (RestoreRefused $e) {
            return $this->refuse($e->errorCode, $e->getMessage(), $e->hint);
        } catch (QueryException $e) {
            return $this->refuse('database_failed', 'The restored database could not be prepared (SQLSTATE '.($e->errorInfo[0] ?? '?').'): '.($e->errorInfo[2] ?? 'no message'));
        } catch (RuntimeException $e) {
            return $this->refuse('restore_finish_failed', $e->getMessage());
        }
        if ($this->option('json') === true) {
            return JsonResult::ok(self::NAME, $data, changed: true)->emit($this);
        }
        $this->components->info("Restored database prepared (audit epoch {$data['epoch']}).");

        return self::SUCCESS;
    }

    private function request(): FinishRequest
    {
        $input = FinishRequest::stdin($this->stdin());
        $seed = $this->option('epoch-seed');
        if (! is_string($seed) || preg_match('/^\d{1,9}$/D', $seed) !== 1) {
            throw new InvalidArgumentException('--epoch-seed must be a whole number.');
        }
        $source = $this->option('source');

        return new FinishRequest(
            $source === null || $source === '' ? null : RestoreConnections::name($source, 'source'),
            $this->backupName(),
            (int) $seed,
            ActorUid::from($this->option('actor-uid')),
            is_string($this->option('reason')) ? $this->option('reason') : '',
            $this->option('no-carry') !== true,
            RestoreConnections::name($this->option('backup-role'), 'backup-role'),
            $input['previous_heads'],
            $input['previous_epoch'],
            $input['from_backup'],
        );
    }

    private function backupName(): ?string
    {
        $backup = $this->option('backup');
        if ($backup === null || $backup === '') {
            return null;
        }
        if (! is_string($backup) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $backup) !== 1) {
            throw new InvalidArgumentException('--backup must be a backup name.');
        }

        return $backup;
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
