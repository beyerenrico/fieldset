<?php

declare(strict_types=1);

namespace App\Domain\Instance\Reset;

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\OneOffResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceDirectory;
use Carbon\CarbonImmutable;

/**
 * The app-side steps of `fsa reset` as socket-less one-offs of the app service (KTD25, plan U23, ADR 0088): the fsa process
 * holding the socket never connects to a database. `instance:reset plan|recreate|epoch` answer with a JSON document;
 * `instance:audit-export` streams JSON Lines into `backups/audit-export-reset-<UTC time>.jsonl` (written as `.partial`,
 * 0600, renamed once its header checked out), the record of the audit log when no safety backup keeps it.
 */
final readonly class ResetApp
{
    public const string EXPORT_PREFIX = 'audit-export-reset-';

    public const string EXPORT_SUFFIX = '.jsonl';

    private const float TIMEOUT = 600.0;

    /** Every audit entry of a large instance, streamed. */
    private const float EXPORT_TIMEOUT = 7200.0;

    public function __construct(private InstanceDirectory $directory, private DockerGateway $docker) {}

    /** @throws InstanceException exit 4 when the live database cannot be read (nothing changed yet) */
    public function plan(ComposeProject $project): OneOffResult
    {
        return $this->run($project, ['plan'], 'reset_plan_failed', 'Check that the instance runs (`fsa status`, `fsa logs postgres`); nothing was changed.');
    }

    /** @return array<string, mixed> */
    public function recreate(ComposeProject $project): array
    {
        return $this->run($project, ['recreate'], 'database_recreate_failed', 'Read `fsa logs postgres`, then run `fsa reset` again: it continues here.')->data;
    }

    /**
     * @param  array<string, mixed>  $input  previous_heads, previous_epoch (stdin)
     * @return array<string, mixed>
     */
    public function epoch(ComposeProject $project, int $seed, array $input, ?string $safetyBackup, ?string $auditExport, bool $demo, int $actorUid): array
    {
        $options = ['epoch', '--epoch-seed='.$seed, '--actor-uid='.$actorUid, ...($safetyBackup === null ? [] : ['--safety-backup='.$safetyBackup]),
            ...($auditExport === null ? [] : ['--audit-export='.$auditExport]), ...($demo ? ['--demo'] : [])];

        return $this->run($project, $options, 'reset_epoch_failed', 'Read the output, then run `fsa reset` again: it continues here.', json_encode($input, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))->data;
    }

    /**
     * Writes the audit export into backups/.
     *
     * @return array{file: string, entries: int|null, bytes: int}
     *
     * @throws InstanceException exit 4 `audit_export_failed` (nothing changed: the database is still there)
     */
    public function export(ComposeProject $project): array
    {
        $this->directory->ensureDirectory(InstanceDirectory::BACKUPS_DIR);
        $this->discardPartials();
        $name = self::EXPORT_PREFIX.CarbonImmutable::now('UTC')->format('Ymd\THis\Z').self::EXPORT_SUFFIX;
        $partial = $this->directory->path(InstanceDirectory::BACKUPS_DIR.'/'.$name.'.partial');
        $outcome = $this->docker->composeRunToFile($project, ComposeRenderer::ONE_OFF_SERVICE, ['php', 'artisan', 'instance:audit-export'], $partial, timeout: self::EXPORT_TIMEOUT);
        $header = $outcome->successful() ? self::header($partial) : null;
        if ($header === null) {
            @unlink($partial);
            throw new InstanceException('The audit export failed: '.($outcome->successful() ? 'it is incomplete (no export header, or fewer entries than the header names)' : $outcome->stderrTail(5)), ExitCode::HealthCheckFailed, 'audit_export_failed', 'Read the output; nothing was deleted. Run `fsa reset` again, or without --no-backup to keep a full backup.', true);
        }
        $final = $this->directory->path(InstanceDirectory::BACKUPS_DIR.'/'.$name);
        if (! rename($partial, $final)) {
            throw new InstanceException("The audit export could not be renamed to {$name}.", ExitCode::HealthCheckFailed, 'audit_export_failed', 'Check backups/; nothing was deleted.', true);
        }
        @chmod($final, 0600);

        return ['file' => $name, 'entries' => is_int($header['entries'] ?? null) ? $header['entries'] : null, 'bytes' => (int) filesize($final)];
    }

    /** @return array<string, mixed>|null the export's header, when the file starts with one and holds the entries it names */
    private static function header(string $path): ?array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $header = json_decode((string) fgets($handle), true);
        $lines = 0;
        while (fgets($handle) !== false) {
            $lines++;
        }
        fsync($handle);
        fclose($handle);
        $complete = is_array($header) && ($header['format'] ?? null) === 'fsa-audit-export' && is_int($header['entries'] ?? null) && $lines >= $header['entries'];

        return $complete ? $header : null;
    }

    /** What an interrupted export left (never a complete one). */
    private function discardPartials(): void
    {
        foreach ($this->directory->files()->list(InstanceDirectory::BACKUPS_DIR) as $name) {
            if (str_starts_with($name, self::EXPORT_PREFIX) && str_ends_with($name, '.partial')) {
                @unlink($this->directory->path(InstanceDirectory::BACKUPS_DIR.'/'.$name));
            }
        }
    }

    /** @param  list<string>  $arguments */
    private function run(ComposeProject $project, array $arguments, string $code, string $hint, ?string $input = null): OneOffResult
    {
        $outcome = OneOffResult::fromOutcome($this->docker->composeRun($project, ComposeRenderer::ONE_OFF_SERVICE, ['php', 'artisan', 'instance:reset', ...$arguments, '--json'], input: $input, timeout: self::TIMEOUT));
        if (! $outcome->ok) {
            throw new InstanceException("`instance:reset {$arguments[0]}` failed: ".$outcome->errorMessage(), ExitCode::HealthCheckFailed, $code, $hint, true, ['code' => $outcome->errorCode(), 'output' => $outcome->output]);
        }

        return $outcome;
    }
}
