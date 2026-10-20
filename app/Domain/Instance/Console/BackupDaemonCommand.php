<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Console\Support\JsonResult;
use App\Domain\Instance\Backup\AgeCli;
use App\Domain\Instance\Backup\BackupConfig;
use App\Domain\Instance\Backup\BackupDaemon;
use App\Domain\Instance\Backup\BackupFailed;
use App\Domain\Instance\Backup\BackupName;
use App\Domain\Instance\Backup\BackupRun;
use App\Domain\Instance\Backup\BackupRunLog;
use App\Domain\Instance\Backup\BackupRunRecorder;
use App\Domain\Instance\Backup\BackupSealer;
use App\Domain\Instance\Backup\BackupStore;
use App\Domain\Instance\Backup\BackupTools;
use App\Domain\Instance\Backup\BackupWriter;
use App\Domain\Instance\Backup\ServiceIdentity;
use App\Domain\Instance\Backup\ServiceStartFailure;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\LockRecord;
use App\Domain\Instance\State\InstanceDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Hidden;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The backup service's entry point (`fsa backup-daemon`, an image role, hidden from users; KTD15, ADR 0084). It runs in
 * the `backup` service as container root with only DAC_READ_SEARCH, DAC_OVERRIDE, CHOWN and FOWNER, without the Docker
 * socket, with the storage volume read-only and the instance directory's marker, `locks/`, `backups/` and
 * `staging/backup/` mounted.
 *
 * - Without options: the daemon (BackupDaemon) until SIGTERM, SIGINT or SIGHUP. A start that fails (its configuration)
 *   is recorded (kind `service`, status `failed`) before it exits.
 * - `--once --kind=manual|final|safety-<operation> --lock-id=<id>`: one run as a one-off of `fsa backup`, uninstall's
 *   final backup or a safety backup, under the lock its fsa parent holds; `--include-secrets` reads `secrets.env` from
 *   stdin (encryption required). One JSON document on stdout.
 * - `--seal=<backup> --lock-id=<id>`: encrypts a plain backup in place to the recipients (safety backups, R57).
 */
#[Description('Run the fsa backup service (the backup service role; use fsa backup for a manual backup)')]
#[Signature('fsa:backup-daemon
        {--once : Run one backup and exit}
        {--kind=manual : With --once: manual, final, or safety-upgrade|restore|reset|rollback|adopt}
        {--lock-id= : With --once or --seal: the ID of the instance lock the calling fsa command holds}
        {--include-secrets : With --once: read secrets.env from stdin into the (encrypted) backup}
        {--seal= : Encrypt this plain backup in place to the backup recipients}
        {--json : Print one JSON document on stdout}')]
#[Hidden]
final class BackupDaemonCommand extends Command
{
    private const string COMMAND = 'backup-daemon';

    /** Container key of a closure that returns stdin (tests). */
    public const string STDIN = 'fsa.backup-daemon.stdin';

    private bool $stopping = false;

    public function handle(): int
    {
        umask(0077);

        try {
            $config = BackupConfig::fromEnvironment(getenv());
            $directory = InstanceDirectory::open($config->instanceRoot, [$config->ownerUid, $config->ownerGid]);
            $this->laravel->instance(InstanceDirectory::class, $directory);
            $store = new BackupStore($directory, $config->ownerUid, $config->ownerGid);

            return match (true) {
                is_string($this->option('seal')) => $this->seal($store, $config),
                $this->option('once') === true => $this->once($directory, $store, $config),
                default => $this->daemon($directory, $store, $config),
            };
        } catch (InstanceException|BackupFailed $e) {
            return $this->refuse($e instanceof BackupFailed ? $e->reason : $e->errorCode, $e->getMessage());
        } catch (Throwable $e) {
            return $this->refuse('internal_error', $e->getMessage());
        }
    }

    /** One JSON document; the daemon also records why it could not start (ServiceStartFailure), so /platform sees it. */
    private function refuse(string $reason, string $message): int
    {
        if (! is_string($this->option('seal')) && $this->option('once') !== true) {
            new ServiceStartFailure(DB::connection())->record(getenv(), $reason, $message);
        }

        return $this->emit(JsonResult::failed(self::COMMAND, $reason, $message));
    }

    private function once(InstanceDirectory $directory, BackupStore $store, BackupConfig $config): int
    {
        $lockId = $this->lockId();
        $secrets = $this->option('include-secrets') === true ? $this->stdin() : null;
        $name = BackupName::for((string) $this->option('kind'), CarbonImmutable::now('UTC'));
        if ($name->isScheduled()) {
            throw new BackupFailed('invalid_kind', 'Scheduled backups are the daemon\'s; pass --kind=manual, final or a safety kind.');
        }
        $run = $this->backupRun($directory, $store, $config)->run($name, new ServiceIdentity()->holder(), $lockId, $secrets);
        $document = $run['status'] === 'succeeded'
            ? JsonResult::ok(self::COMMAND, $run, changed: true)
            : JsonResult::failed(self::COMMAND, (string) ($run['reason'] ?? 'backup_failed'), (string) ($run['message'] ?? 'The backup failed.'), retryable: true);

        return $this->emit($document);
    }

    private function seal(BackupStore $store, BackupConfig $config): int
    {
        $lockId = $this->lockId();
        $current = $this->laravel->make(InstanceLock::class)->current();
        if (! $current instanceof LockRecord || ! hash_equals($current->lockId, $lockId)) {
            throw new BackupFailed('lock_mismatch', 'The fsa command that asked for the seal no longer holds the instance lock.');
        }
        $sealed = new BackupSealer($store, $this->laravel->make(AgeCli::class))->seal((string) $this->option('seal'), $config->recipients);

        return $this->emit(JsonResult::ok(self::COMMAND, $sealed, changed: $sealed['changed']));
    }

    private function daemon(InstanceDirectory $directory, BackupStore $store, BackupConfig $config): int
    {
        try {
            $schedule = $config->schedule();
        } catch (InvalidArgumentException $e) {
            throw new BackupFailed('invalid_schedule', $e->getMessage(), $e);
        }
        $this->trapSignals();
        $daemon = new BackupDaemon(
            $schedule,
            new BackupRunLog($directory, $store),
            $this->backupRun($directory, $store, $config),
            $this->laravel->make(InstanceLock::class),
            new ServiceIdentity()->holder(),
            static function (int $seconds): void {
                sleep($seconds); // a signal ends the sleep early (async signals)
            },
            static fn (): CarbonImmutable => CarbonImmutable::now('UTC'),
        );
        $this->line((string) json_encode(['event' => 'backup_service_started', 'schedule' => $schedule->time ?? 'off', 'time_zone' => $config->timeZone, 'retention' => $config->retention, 'encrypted' => $config->encrypts()], JSON_UNESCAPED_SLASHES));
        $daemon->loop(fn (): bool => $this->stopping, function (array $run): void {
            unset($run['result']);
            $this->line((string) json_encode(['event' => 'backup_run', ...$run], JSON_UNESCAPED_SLASHES));
        });

        return self::SUCCESS;
    }

    private function backupRun(InstanceDirectory $directory, BackupStore $store, BackupConfig $config): BackupRun
    {
        $db = DB::connection();
        $writer = new BackupWriter($store, $db, $this->laravel->make(BackupTools::class), $this->laravel->make(AgeCli::class), $config);

        return new BackupRun($this->laravel->make(InstanceLock::class), $store, $writer, new BackupRunRecorder(new BackupRunLog($directory, $store), $db), $config);
    }

    private function lockId(): string
    {
        $lockId = $this->option('lock-id');
        if (! is_string($lockId) || preg_match('/^[0-9a-f]{32}$/D', $lockId) !== 1) {
            throw new BackupFailed('lock_required', 'A one-off backup runs only under the lock of the fsa command that started it (--lock-id).');
        }

        return $lockId;
    }

    private function stdin(): string
    {
        $reader = $this->laravel->bound(self::STDIN) ? $this->laravel->make(self::STDIN) : static fn (): string => (string) stream_get_contents(STDIN);
        $secrets = (string) $reader();
        if ($secrets === '' || strlen($secrets) > 65536) {
            throw new BackupFailed('secrets_missing', '--include-secrets expects secrets.env on stdin.');
        }

        return $secrets;
    }

    private function trapSignals(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->stopping = true;
            });
        }
    }

    private function emit(JsonResult $document): int
    {
        return $document->emit($this);
    }
}
