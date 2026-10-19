<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\Compose\Services\PostgresService;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\Ledger;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use Throwable;

/**
 * What an adopted instance remembers about its 1.0 origin (plan U19, ADR 0087): the state's `adopted` history entry
 * (checkout, 1.0 project, backup, jobs left in 1.0's Valkey), or, while an adopt that stopped 1.0 is unfinished (review
 * A3), the same read from its open journal; and the baseline snapshot taken before 2.0 started (the ledger's `adopted`
 * line, else the journal's `baseline` phase). `fsa uninstall` uses it: an adopted instance gets a final backup unless
 * `--no-backup` (its 1.0 checkout holds the data only up to the adopt), and the result reports the writes made since
 * adopt (DataSnapshot::writesSince), the jobs 1.0 will run when it comes back and the command that brings it back.
 */
final readonly class AdoptedInstance
{
    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private DatabaseProbe $probe,
        private Ledger $ledger,
        private OperationJournal $journal,
    ) {}

    /** @return array<string, mixed>|null the last `adopted` history entry */
    public static function record(?InstanceState $state): ?array
    {
        $entries = array_values(array_filter($state?->history() ?? [], static fn (array $entry): bool => ($entry['event'] ?? null) === AdoptOperation::EVENT));

        return $entries === [] ? null : $entries[array_key_last($entries)];
    }

    /**
     * The adopt record, or the record an unfinished adopt that stopped 1.0 would write (`pending`: true).
     *
     * @return array<string, mixed>|null
     */
    public function current(?InstanceState $state): ?array
    {
        $current = $this->journal->current();
        if (self::record($state) !== null || ! $current instanceof JournalRecord || $current->operation() !== AdoptOperation::OPERATION || ! $current->hasStarted('stop_1_0')) {
            return self::record($state);
        }
        $legacy = $current->phaseData('doctor')['legacy'] ?? [];
        $backup = $current->phaseData('backup_1_0')['name'] ?? null;

        return [...self::entry(is_array($legacy) ? LegacyInstall::fromArray($legacy) : LegacyInstall::fromArray([]), is_string($backup) ? $backup : null, $current->phaseData('stop_1_0')), 'pending' => true];
    }

    /**
     * The `adopted` history entry: scalars only.
     *
     * @param  array<string, mixed>  $quiesce  the stop_1_0 phase's data (its drain)
     * @return array<string, string|int|null>
     */
    public static function entry(LegacyInstall $legacy, ?string $backup, array $quiesce): array
    {
        $drain = is_array($quiesce['drain'] ?? null) ? $quiesce['drain'] : [];
        $left = ($drain['drained'] ?? true) === true ? 0 : (int) ($drain['waiting'] ?? 0) + (int) ($drain['reserved'] ?? 0);

        return ['checkout' => $legacy->checkout, 'project' => $legacy->project, 'version' => $legacy->version, 'backup' => $backup,
            'jobs_left' => $left, 'jobs_delayed' => (int) ($drain['delayed'] ?? 0), 'jobs_unknown' => array_key_exists('drained', $drain) && $drain['drained'] === null ? 1 : 0];
    }

    /**
     * The writes since adopt, read from the instance's running Postgres (the final backup started it); null when the
     * baseline or the database cannot be read.
     *
     * @return array{audit_entries: int, rows: array<string, int>, any: bool}|null
     */
    public function writesSince(InstanceState $state): ?array
    {
        $baseline = $this->ledger->last(AdoptOperation::EVENT)['baseline'] ?? $this->journal->current()?->phaseData('baseline')['snapshot'] ?? null;
        if (! is_array($baseline)) {
            return null;
        }

        try {
            $project = ComposeProject::forInstance($state, $this->directory);
            $postgres = array_find(ComposeFile::serviceContainers($this->docker->composePs($project)), static fn (ContainerInfo $c): bool => $c->service() === PostgresService::NAME && $c->running());
            if (! $postgres instanceof ContainerInfo) {
                return null;
            }
            $settings = InstanceSettings::of($state);
            $now = $this->probe->snapshot($state->digests()['postgres'] ?? '', $postgres->id, $settings->dbDatabase(), $settings->dbUsername(), $state->uuid());

            return $now->writesSince(DataSnapshot::fromArray($baseline));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The `adopted` block of uninstall's result.
     *
     * @param  array<string, mixed>  $record
     * @param  array{audit_entries: int, rows: array<string, int>, any: bool}|null  $writes
     * @return array<string, mixed>
     */
    public static function uninstallData(array $record, ?array $writes): array
    {
        $checkout = (string) ($record['checkout'] ?? '');

        return ['checkout' => $checkout, 'project' => $record['project'] ?? null, 'backup' => $record['backup'] ?? null, 'pending' => ($record['pending'] ?? false) === true,
            'writes_since_adopt' => $writes, 'restart_1_0' => "cd {$checkout} && docker compose up -d",
            'jobs_in_1_0' => ['left' => (int) ($record['jobs_left'] ?? 0), 'delayed' => (int) ($record['jobs_delayed'] ?? 0), 'unknown' => ($record['jobs_unknown'] ?? 0) === 1]];
    }

    /**
     * What `restart_1_0` says about the jobs 1.0 will run when it comes back (review A6).
     *
     * @param  array<string, mixed>  $record
     */
    public static function jobsNotice(array $record): string
    {
        $left = (int) ($record['jobs_left'] ?? 0);
        $delayed = (int) ($record['jobs_delayed'] ?? 0);
        $unknown = ($record['jobs_unknown'] ?? 0) === 1;
        if ($left === 0 && $delayed === 0 && ! $unknown) {
            return '';
        }
        $counted = $unknown ? 'an uncounted number of jobs' : trim(($left > 0 ? "{$left} job(s) left by --drop-jobs" : '').($left > 0 && $delayed > 0 ? ' and ' : '').($delayed > 0 ? "{$delayed} delayed job(s)" : ''));

        return " It will run the {$counted} still in its Valkey (sent again, rendered again), on data written before the adopt.";
    }
}
