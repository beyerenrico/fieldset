<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Console\FieldsetContext;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Lifecycle\OneOffResult;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Operations\OperationUnfinished;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceDirectory;
use Throwable;

/**
 * `fieldset restore --drill <backup>` (R41, plan U17, ADR 0085): proves a backup restores without touching the instance.
 * Under the instance lock it prepares the backup like a restore, restores the dump into a scratch database
 * `restore_drill_<id>` (the restricted role for an unauthenticated archive) and runs `instance:restore-drill` against
 * it in a one-off with a null mailer and a sync queue: counts, chain heads, the storage archive against the file
 * references, a document rendered to a temporary directory, a decrypt sample and the migration set. The scratch
 * database and directory are dropped afterwards, whatever happened; leftovers of a killed drill go with the next one.
 * Live storage, the live database, the cache and the mail log stay as they are (CACHE_STORE=array, a null mailer, a sync
 * queue). The document rendered reads the images it embeds from the live storage volume (`render_storage: live`): the
 * backup's files are checked by name against the references, not mounted. Exit 4 when a check fails; exit 9
 * `operation_unfinished` while any operation is unfinished (a scratch database next to a half-done restore, M12 gate C4).
 */
final readonly class RestoreDrill
{
    public const string OPERATION = 'restore-drill';

    public const string PREFIX = 'restore_drill_';

    private const float TIMEOUT = 1800.0;

    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private InstanceLock $lock,
        private OperationJournal $journal,
        private ContainerIdentity $identity,
        private BackupIntake $intake,
        private RestoreDatabases $databases,
        private RestoreStack $stack,
        private RestoreSource $source,
    ) {}

    public function drill(RestoreRequest $request, FieldsetContext $context): CommandResult
    {
        $state = RestoreOperation::installed($this->directory);
        $project = ComposeProject::forInstance($state, $this->directory);
        $id = bin2hex(random_bytes(6));
        $database = self::PREFIX.$id;
        $handle = $this->lock->acquire($this->identity->holder('fieldset', $request->actorUid), self::OPERATION);
        $open = $this->journal->current(); // under the lock: no drill next to an unfinished operation (M12 gate C4)
        if ($open instanceof JournalRecord) {
            $this->lock->release($handle);

            throw new OperationUnfinished($open);
        }

        try {
            foreach ($this->databases->list($project, self::PREFIX) as $leftover) { // with the restricted role of its drill (review A10)
                $this->databases->drop($project, $leftover, $this->role(substr($leftover, strlen(self::PREFIX))));
            }
            $context->progress->phase('prepare', 'Checking the backup');
            $intake = $this->intake->take(RestoreOperation::runAs($state), $request, RestoreChecks::DRILL, $id, AppKeys::of($this->stack->secrets()->appKeys()), $state->version());
            $context->progress->phase('restore', "Restoring into {$database}");
            $created = $this->databases->create($project, $database, $intake['backup']->authenticated() ? null : $this->role($id));
            $this->databases->restore($state, $project, $intake['backup'], $database, $created['role'] === null ? null : ['username' => $created['role'], 'password' => (string) $created['password']]);
            if ($created['role'] !== null) {
                $this->databases->audit($project, $database, RestoreNames::for($state, $id)->live);
                $this->databases->adopt($project, $database, $created['role']);
            }
            $context->progress->phase('check', 'Checking the restored data');
            $report = $this->check($project, $database, $intake);
        } finally {
            $this->cleanUp($project, $database, $id);
            $this->lock->release($handle);
        }

        return $this->result($intake, $report, $database);
    }

    /** @param  array{backup: PreparedBackup, warnings: list<array{0: string, 1: string, 2: string|null}>, add: list<string>}  $intake */
    private function check(ComposeProject $project, string $database, array $intake): OneOffResult
    {
        $backup = $intake['backup'];
        $listing = $backup->storage === null ? null : (string) @file_get_contents($backup->storage['listing']);
        $keys = implode(',', array_values(array_unique([...$this->stack->secrets()->previousKeys, ...$intake['add']])));
        // nothing reaches the live mail, queues or cache: the restored rows share IDs with the live ones (review A14)
        $env = ['DB_DATABASE' => $database, 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'array', ...($keys === '' ? [] : ['APP_PREVIOUS_KEYS' => $keys])];

        return OneOffResult::fromOutcome($this->docker->composeRun(
            $project, ComposeRenderer::ONE_OFF_SERVICE, ['php', 'artisan', 'instance:restore-drill', ...($listing === null ? ['--no-storage'] : []), '--json'], $env,
            input: $listing ?? '', timeout: self::TIMEOUT,
        ));
    }

    /** The restricted role of a drill (hex ID only; null for a name that is no drill's). */
    private function role(string $id): ?string
    {
        return preg_match('/^[0-9a-f]{12}$/D', $id) === 1 ? 'fieldset_drill_'.$id : null;
    }

    private function cleanUp(ComposeProject $project, string $database, string $id): void
    {
        try {
            $this->databases->drop($project, $database, $this->role($id));
        } catch (Throwable) {
            // the next drill drops every restore_drill_* database first
        }
        try {
            $this->source->discard($id);
        } catch (Throwable) {
            // a scratch directory is hidden and holds no instance data the operator lacks (the backup itself)
        }
    }

    /** @param  array{backup: PreparedBackup, warnings: list<array{0: string, 1: string, 2: string|null}>, add: list<string>}  $intake */
    private function result(array $intake, OneOffResult $report, string $database): CommandResult
    {
        $backup = $intake['backup'];
        $data = [
            'backup' => $backup->name, 'format' => $backup->format, 'authenticated' => $backup->authenticated(), 'encrypted' => $backup->encrypted,
            'created_at' => $backup->manifest['created_at'] ?? null, 'backup_version' => $backup->version(), 'migrations' => $backup->migrations,
            'storage' => $backup->storage === null ? null : ['files' => $backup->storage['files'], 'directories' => $backup->storage['directories'], 'bytes' => $backup->storage['bytes']],
            'database' => $database, 'dropped' => true, 'drill' => $report->data,
            'render_storage' => 'live', // the rendered document reads images from the live volume, not from the backup (review A14)
        ];
        $result = $report->ok
            ? CommandResult::ok('restore', $data)
            : CommandResult::failed('restore', ExitCode::HealthCheckFailed, $report->errorCode() === 'one_off_failed' ? 'drill_failed' : $report->errorCode(), 'The drill found a problem: '.$report->errorMessage(), 'Read `data.drill.checks`; the instance was not touched.', false, $data);
        foreach ([...$intake['warnings'], ...array_map(static fn (array $w): array => [$w['code'], $w['message'], $w['hint']], $report->warnings)] as [$code, $message, $hint]) {
            $result = $result->withWarning($code, $message, $hint);
        }

        return $result;
    }
}
