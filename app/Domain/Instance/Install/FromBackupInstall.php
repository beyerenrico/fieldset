<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\Backup\BackupRunner;
use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Console\FsaContext;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\AppCommands;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\Lifecycle\SignInDelivery;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Operations\ResumeCommand;
use App\Domain\Instance\Operations\Supervisor;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Restore\AppKeys;
use App\Domain\Instance\Restore\BackupIntake;
use App\Domain\Instance\Restore\PreparedBackup;
use App\Domain\Instance\Restore\RestoreChecks;
use App\Domain\Instance\Restore\RestoreNames;
use App\Domain\Instance\Restore\RestoreSource;
use App\Domain\Instance\Restore\RestoreStorage;
use App\Domain\Instance\Restore\ResumeInputs;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * `fsa install --from-backup <backup>` (R42, R60, F6, plan U17, ADR 0085): a new instance on this host from a backup,
 * journaled and resumable like every install:
 *
 * backup (verify the release; prepare and judge the backup without socket or network, BackupIntake) → doctor (the
 * backup's mode unless flags choose one; a domain not pointing here yet is "certificate pending", a warning) → secrets
 * (the backup's APP keys, FromBackupKeys) → state (portable settings from the backup, host-bound ones from flags and host;
 * capture mail, paused webhooks, the fresh storage volume) → pull → up (Postgres, Valkey) → restore_database →
 * restore_storage → migrate (an older backup is migrated here) and the backup role → restore_finish (new epoch with
 * `instance.restored`, stale credentials, grants, decrypt sample) → start → smoke (internal only while the certificate
 * is pending) → done (a sign-in link for `--owner-email`, the installed records).
 *
 * Only this command resumes its journal (a plain `fsa install` refuses it, M12 gate O1), and it needs the inputs the first
 * run had while it still prepares the backup again (ResumeInputs: exit 2 before the lock, O2).
 */
final readonly class FromBackupInstall
{
    public const string EVENT = 'installed_from_backup';

    public function __construct(
        private InstanceDirectory $directory,
        private Supervisor $supervisor,
        private ContainerIdentity $identity,
        private ReleaseSource $release,
        private InstallPreflight $preflight,
        private InstanceStack $stack,
        private ComposeRenderer $renderer,
        private BackupIntake $intake,
        private FromBackupKeys $keys,
        private FromBackupData $data,
        private AppCommands $app,
        private InstallSmoke $smoke,
        private BackupRunner $backups,
        private RestoreSource $source,
        private OperationJournal $journal,
    ) {}

    public function install(InstallRequest $request, FromBackupRequest $backup, FsaContext $context, bool $secretsToFile): CommandResult
    {
        FromBackupRequest::assertApplicable($request);
        $state = $this->directory->hasState() ? $this->directory->state() : null;
        if ($state instanceof InstanceState && InstallRecord::isInstalled($state)) {
            return FromBackupResult::unchanged($state, $backup, $this->directory);
        }
        $open = $this->journal->current();
        if ($open instanceof JournalRecord && ResumeCommand::fromBackup($open) === $backup->backup) {
            ResumeInputs::assert($open, $backup->toRestoreRequest($context->facts->callerUid()), ! $open->hasCompleted('restore_storage'));
        }

        return $this->supervisor->run(
            Installer::OPERATION, $this->identity->holder('fsa', $context->facts->callerUid()),
            fn (OperationRun $run): CommandResult => $this->steps($run, $request, $backup, $context, $secretsToFile),
            resume: true, context: [...$request->toContext(), ...$backup->toContext()], progress: $context->progress,
            archive: $this->withoutPersonalInputs(...),
            accepts: static fn (JournalRecord $record): bool => ($record->context()['from_backup'] ?? null) === $backup->backup,
        );
    }

    /** A failed attempt never leaves the decrypted set (a plaintext dump, secrets.env) behind (review A5). */
    private function steps(OperationRun $run, InstallRequest $request, FromBackupRequest $backup, FsaContext $context, bool $secretsToFile): CommandResult
    {
        $id = RestoreNames::id($run->record()->id());
        try {
            return $this->phases($run, $request, $backup, $context, $secretsToFile, $id);
        } catch (Throwable $e) {
            try {
                $this->source->discard($id);
            } catch (Throwable) {
                // the resumed attempt (same journal, same directory) replaces it; the failure that matters is $e
            }

            throw $e;
        }
    }

    private function phases(OperationRun $run, InstallRequest $request, FromBackupRequest $backup, FsaContext $context, bool $secretsToFile, string $id): CommandResult
    {
        $record = $run->record();
        $request = $record->phases() === [] ? $request : $request->over($record->context());
        // the set the database came from: a resume may not take another one from the same path (M12 gate)
        $pinned = $record->hasCompleted('restore_database') ? ($record->phaseData('backup')['sums'] ?? null) : null;
        $intake = $run->phase('backup', fn (): array => $this->prepare($request, $backup, $context->facts, $id, is_string($pinned) ? $pinned : null), changes: false, always: ! $record->hasCompleted('restore_storage'));
        $prepared = PreparedBackup::fromArray($intake['backup']);
        $effective = FromBackupSettings::effective($request, $prepared, $this->origin($intake));
        $effective->assertConsistent();
        $preflight = $run->phase('doctor', fn (): array => $this->preflight->run($effective, $context->facts, (string) $intake['uuid'], pendingCertificate: true), changes: false);
        $run->phase('secrets', fn (): array => $this->keys->ensure($prepared, $backup->keyFile, $context->redactor));
        $run->phase('state', fn (): array => $this->writeState($preflight, $context->facts, $prepared, $id, $backup->keepRecipients));
        $state = $this->directory->state();
        $project = ComposeProject::forInstance($state, $this->directory);
        $run->phase('pull', fn (): array => $this->stack->pull(), changes: false);
        $run->phase('up', fn (): array => [...$this->stack->up($project, Installer::INFRASTRUCTURE), ...$this->stack->trustGateway($project, $preflight)]);
        $run->phase('restore_database', fn (): array => $this->data->database($state, $project, $prepared, $id));
        $run->phase('restore_storage', fn (): array => $this->data->storage($state, $prepared));
        $run->phase('migrate', fn (): array => $this->migrate($project));
        $finish = $run->phase('restore_finish', fn (): array => $this->data->finish($state, $project, $prepared, $context->facts->callerUid(), $id));
        $run->phase('start', fn (): array => $this->stack->up($project, []), maxSeconds: InstanceStack::WAIT_SECONDS);
        $pending = FromBackupResult::certificatePending($preflight);
        $run->phase('smoke', fn (): array => $this->smoke->run($state, $project, $effective->ownerEmailIfKnown(), $context->progress, internalOnly: $pending), changes: false, maxSeconds: InstallSmoke::maxSeconds($state));
        $link = null;
        $done = $run->phase('done', function () use ($project, $effective, $prepared, $finish, $context, $id, &$link): array {
            [$link, $failure] = $this->finish($project, $effective, $prepared, $finish, $context, $id);

            return ['link_issued' => $link !== null, 'link_failure' => $failure];
        });
        $result = FromBackupResult::build($this->directory->state(), $this->directory, $effective, $prepared, $intake, $preflight, $finish, $done);

        return $link === null ? $result : SignInDelivery::deliver($result, $context, $link['value'], $link['expires_at'], $secretsToFile, (string) $effective->ownerEmailIfKnown());
    }

    /**
     * The release verified, the backup prepared and judged. What later phases need from a 1.0 backup's `env` (its mode
     * and SMTP server) is journaled now, while the verified copy is there: a failed attempt removes it, and after
     * `restore_storage` a resume does not prepare again. With `$pinned` the set must be the one the database came from.
     *
     * @return array{backup: array<string, mixed>, warnings: list<array{0: string, 1: string, 2: string|null}>, uuid: string, version: string, image: string, pending_mail: array<string, mixed>|null, origin: array{0: string|null, 1: string|null}, sums: string|null}
     *
     * @throws InvalidInput exit 2 `backup_changed`
     */
    private function prepare(InstallRequest $request, FromBackupRequest $backup, HostFacts $facts, string $id, ?string $pinned): array
    {
        $manifest = $this->release->verified($this->directory, $facts, $request->version());
        $image = ReleaseSource::digests($manifest, $facts->registry)['app'] ?? '';
        $uuid = $this->directory->hasState() ? $this->directory->state()->uuid() : Str::uuid()->toString();
        $user = $facts->ownerUidInContainers().':'.($facts->ownerUidInContainers() === 0 ? 0 : $facts->callerGid());
        $intake = $this->intake->take(['image' => $image, 'uuid' => $uuid, 'user' => $user], $backup->toRestoreRequest($facts->callerUid()),
            RestoreChecks::FROM_BACKUP, $id, AppKeys::of([]), (string) $manifest->version, mountedElsewhere: true);
        FromBackupKeys::keys($intake['backup'], $backup->keyFile); // exit 2 `app_key_required` before anything is written
        $sums = $intake['backup']->sumsDigest();
        if ($pinned !== null && ! hash_equals($pinned, (string) $sums)) {
            throw new InvalidInput("The backup at {$backup->backup} is not the set an earlier attempt of this install restored the database from: its SHA256SUMS changed.", 'backup_changed',
                'Put that backup back and run the same command again, or start over in a new, empty directory.');
        }

        return ['backup' => $intake['backup']->toArray(), 'warnings' => $intake['warnings'], 'uuid' => $uuid, 'version' => (string) $manifest->version, 'image' => $image,
            'pending_mail' => FromBackupSettings::pendingMail($intake['backup']), 'origin' => FromBackupSettings::origin($intake['backup']), 'sums' => $sums];
    }

    /**
     * @param  array<string, mixed>  $intake
     * @return array{0: string|null, 1: string|null}|null
     */
    private function origin(array $intake): ?array
    {
        $origin = $intake['origin'] ?? null;

        return is_array($origin) ? [is_string($origin[0] ?? null) ? $origin[0] : null, is_string($origin[1] ?? null) ? $origin[1] : null] : null;
    }

    /**
     * @param  array<string, mixed>  $preflight
     * @return array{project: string, services: list<string>}
     */
    private function writeState(array $preflight, HostFacts $facts, PreparedBackup $backup, string $id, bool $keepRecipients): array
    {
        $this->stack->writeState($preflight, $facts);
        $state = $this->directory->state();
        $state = FromBackupSettings::overlay($state, $backup, RestoreStorage::fresh($state, $id), $keepRecipients);
        $this->directory->saveState($state);
        $this->data->volume($state); // before the first Compose call: the compose file declares it as an existing volume
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
            throw new InstanceException('The database migrations of the restored data failed.', ExitCode::HealthCheckFailed, 'migrate_failed', 'Read the output, fix the cause, then run `fsa install` again with the same flags: it resumes here.', true, ['output' => trim($output."\n".$outcome->stderrTail(10))]);
        }
        $this->backups->ensureRole($project);

        return ['migrated' => true, 'backup_role' => true];
    }

    /**
     * Marks the instance installed (and installed from this backup), drops the scratch directory and issues the sign-in
     * link `--owner-email` asked for.
     *
     * @param  array<string, mixed>  $finish
     * @return array{0: array{value: string, expires_at: CarbonImmutable|null}|null, 1: string|null} the link, or why there is none
     */
    private function finish(ComposeProject $project, InstallRequest $request, PreparedBackup $backup, array $finish, FsaContext $context, string $id): array
    {
        $this->source->discard($id);
        $state = $this->directory->state();
        if (! InstallRecord::isInstalled($state)) {
            $digest = RecordDigest::fromEnvFiles(new EnvFiles($this->directory));
            $entry = [...InstallRecord::entry($request, $state, $digest, false), 'from_backup' => $backup->source];
            $state = $state->withHistoryEntry(InstallRecord::EVENT, $entry)
                ->withHistoryEntry(self::EVENT, ['backup' => $backup->name, 'epoch' => is_int($finish['epoch'] ?? null) ? $finish['epoch'] : null]);
            $this->directory->saveState($state);
        }
        $email = $request->ownerEmailIfKnown();
        if ($email === null) {
            return [null, null];
        }
        $link = $this->app->loginLink($project, $email, $context->facts->callerUid());
        $secret = $link->ok ? $link->secret(SignInDelivery::SECRET) : null;
        if ($secret === null) {
            return [null, $link->errorMessage()];
        }
        $context->redactor->register($secret['value']);

        return [$secret, null];
    }

    private function withoutPersonalInputs(JournalRecord $record): JournalRecord
    {
        $digest = RecordDigest::fromEnvFiles(new EnvFiles($this->directory));

        return $record->withValuesReplaced(InstallRecord::PERSONAL, static fn (string $value): ?string => $digest?->digest($value));
    }
}
