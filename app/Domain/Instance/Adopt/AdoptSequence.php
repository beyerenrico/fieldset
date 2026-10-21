<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\Backup\BackupRunner;
use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Console\FieldsetContext;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Install\FromBackupKeys;
use App\Domain\Instance\Install\Installer;
use App\Domain\Instance\Install\InstanceStack;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\AppCommands;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\Operations\Interruption;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;

/**
 * The phases of an adopt before 2.0 starts (plan U19, ADR 0087), run again on every resumed run until `start` began (each
 * phase skips itself once completed): secrets → state → pull → stop_1_0 → backup_1_0 → stop_postgres_1_0 → copy → verify →
 * up → migrate → baseline. Between them, and on every run before `start`, the newest journaled fence must still hold
 * (LegacyFence, review A1): a 1.0 started again in between stops the adopt instead of 2.0 starting on stale data. The
 * baseline of "writes since adopt" is taken last, before 2.0 serves anything (review A2): the smoke test that follows
 * writes no counted row (its mail goes out without the mail log, its PDF without a rendition row). A signal is handled
 * after each phase (Interruption, M12 gate O8): AdoptOperation goes back as on a failure.
 */
final readonly class AdoptSequence
{
    public const string QUIESCE = 'stop_1_0';

    public function __construct(
        private InstanceDirectory $directory,
        private FromBackupKeys $keys,
        private InstanceStack $stack,
        private ComposeRenderer $renderer,
        private AdoptData $data,
        private AppCommands $app,
        private BackupRunner $backups,
        private Interruption $interruption,
    ) {}

    /**
     * @param  array<string, mixed>  $preflight  the doctor phase's data
     * @return array<string, mixed> the data phases' results
     */
    public function run(OperationRun $run, LegacyCheckout $checkout, LegacyInstall $legacy, array $preflight, AdoptRequest $request, FieldsetContext $context): array
    {
        $run->phase('secrets', fn (): array => $this->keys->ensureKeys(static fn (): array => [$checkout->appKey(), $checkout->previousKeys()], $context->redactor));
        $this->interruption->checkpoint();
        $run->phase('state', fn (): array => $this->writeState($preflight, $context->facts));
        $state = $this->directory->state();
        $project = ComposeProject::forInstance($state, $this->directory);
        $run->phase('pull', fn (): array => $this->stack->pull(), changes: false);
        $this->interruption->checkpoint();
        $this->assertStopped($run, $state, $legacy);
        $run->phase(self::QUIESCE, fn (): array => $this->data->quiesce($state, $legacy, $request->dropJobs, (int) ($preflight['redis_db'] ?? 0)), maxSeconds: QueueDrain::SECONDS + 300);
        $this->interruption->checkpoint();
        $this->assertStopped($run, $state, $legacy); // before the backup: the writers stayed stopped
        $run->phase('backup_1_0', fn (): array => $this->data->backup($state, $legacy, $checkout, $run->record()->id()));
        $this->interruption->checkpoint();
        // stop_postgres_1_0 checks the writers' fence itself before it records Postgres's (review V6).
        $run->phase('stop_postgres_1_0', fn (): array => $this->data->stopData($state, $legacy, self::fence($run->record())));
        $this->interruption->checkpoint();
        $this->assertStopped($run, $state, $legacy); // before the copy
        $run->phase('copy', fn (): array => $this->data->copy($state, $project, $legacy));
        $this->interruption->checkpoint();
        $this->assertStopped($run, $state, $legacy); // after the copy: nothing started 1.0's Postgres while it ran
        $backup = $run->record()->phaseData('backup_1_0');
        $run->phase('verify', fn (): array => $this->data->verify($state, $project, $legacy, DataSnapshot::fromArray(is_array($backup['snapshot'] ?? null) ? $backup['snapshot'] : [])));
        $this->interruption->checkpoint();
        $run->phase('up', fn (): array => [...$this->stack->up($project, Installer::INFRASTRUCTURE), ...$this->stack->trustGateway($project, $preflight)]);
        $run->phase('migrate', fn (): array => $this->migrate($project));
        $run->phase('baseline', fn (): array => ['snapshot' => $this->data->snapshot($state, $project, $legacy)->toArray()], changes: false);
        $this->interruption->checkpoint();
        $this->assertStopped($run, $state, $legacy); // before 2.0 starts

        return self::phaseData($run->record());
    }

    /** @return array<string, mixed> the data phases' results (also on a resume that skips them) */
    public static function phaseData(JournalRecord $record): array
    {
        return ['quiesce' => $record->phaseData(self::QUIESCE), 'backup' => $record->phaseData('backup_1_0'), 'copy' => $record->phaseData('copy'),
            'verify' => $record->phaseData('verify'), 'baseline' => $record->phaseData('baseline'), 'fence' => self::fence($record)];
    }

    /**
     * The newest journaled fence holds (none before the writers stopped); also checked from `start` on (AdoptOperation).
     *
     * @throws InstanceException exit 4 `legacy_restarted`
     */
    public function assertStopped(OperationRun $run, InstanceState $state, LegacyInstall $legacy): void
    {
        $this->data->guard($state, $legacy, self::fence($run->record()));
    }

    /** @return array<string, mixed>|null the newest journaled fence: Postgres's, else the writers' */
    private static function fence(JournalRecord $record): ?array
    {
        $fence = $record->phaseData('stop_postgres_1_0')['fence'] ?? $record->phaseData(self::QUIESCE)['fence'] ?? null;

        return is_array($fence) ? $fence : null;
    }

    /**
     * @param  array<string, mixed>  $preflight
     * @return array{project: string, services: list<string>}
     */
    private function writeState(array $preflight, HostFacts $facts): array
    {
        $this->stack->writeState($preflight, $facts);
        /** @var array<string, string> $settings */
        $settings = is_array($preflight['settings'] ?? null) ? $preflight['settings'] : [];
        $state = $this->directory->state()->withSettings($settings);
        $this->directory->saveState($state);
        $secrets = $this->stack->secrets();
        $rendered = $this->renderer->write($this->directory, $state, $secrets, ComposeFile::emulated($this->directory, $this->renderer, $state, $secrets));

        return ['project' => $state->project(), 'services' => $rendered->services()];
    }

    /** @return array{migrated: true, backup_role: true} */
    private function migrate(ComposeProject $project): array
    {
        $outcome = $this->app->migrate($project);
        if (! $outcome->successful()) {
            $output = implode("\n", array_slice(preg_split('/\R/', trim($outcome->stdout)) ?: [], -10));
            throw new InstanceException('The migrations of the copied 1.0 database failed.', ExitCode::HealthCheckFailed, 'migrate_failed', 'Read the output; 1.0 runs again unchanged. Fix the cause and run adopt again.', true, ['output' => trim($output."\n".$outcome->stderrTail(10))]);
        }
        $this->backups->ensureRole($project);

        return ['migrated' => true, 'backup_role' => true];
    }
}
