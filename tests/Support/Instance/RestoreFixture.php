<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use App\Domain\Instance\Backup\AgeCli;
use App\Domain\Instance\Backup\BackupChecksums;
use App\Domain\Instance\Backup\BackupTools;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Restore\BackupPreparer;
use App\Domain\Instance\Restore\MigrationSet;
use App\Domain\Instance\Restore\RestoreSpace;
use App\Domain\Instance\Restore\UnsafeArchive;
use App\Domain\Instance\State\EnvFiles;
use Closure;
use RuntimeException;

/**
 * An installed instance (ConfigFixture) whose Docker answers the steps of a restore (plan U17): the socket-less prepare
 * container runs the real BackupPreparer in-process (fake age, pg_restore answers from fixtures), `instance:restore-db`
 * keeps an in-memory list of databases (create, drop, swap, adopt, list), pg_restore and tar succeed unless told
 * otherwise, and `instance:restore-finish`, `audit:verify --heads` and `instance:restore-drill` answer like the app.
 */
final class RestoreFixture
{
    public ConfigFixture $config;

    public InstanceCommandFixture $instance;

    /** @var list<string> */
    public array $databases = ['form_schema', 'postgres'];

    /** @var array<string, true> restore-db actions to fail */
    public array $failDatabase = [];

    /** @var list<array{action: string, options: list<string>, env: list<string>}> */
    public array $databaseCalls = [];

    /** A schema mismatch the audit of an unauthenticated restore reports (null: none). */
    public ?string $auditMismatch = null;

    public ?ProcessOutcome $pgRestore = null;

    public ?ProcessOutcome $tar = null;

    public ?ProcessOutcome $finish = null;

    public ?ProcessOutcome $drill = null;

    /** The upgrade child's answer for an older backup (`instance:upgrade-migrate`; null: it migrates). */
    public ?ProcessOutcome $upgradeMigrate = null;

    /** @var list<string>|null the dump's migrations (null: this image's set) */
    public ?array $migrations = null;

    public string $extraEntries = '';

    public ?int $freeBytes = null;

    /** @param  array<string, mixed>  $installFlags  ConfigFixture's install flags (a domain-mode instance) */
    public function __construct(array $installFlags = [])
    {
        $this->config = new ConfigFixture($installFlags);
        $this->instance = $this->config->instance;
        $this->instance->withRunningStack();
        $state = $this->instance->state(); // Compose's own storage volume, as `docker volume ls` lists it
        $this->instance->docker->withResource(new DockerResource(DockerResource::VOLUME, $state->project().'_app-storage', [Labels::INSTANCE => $state->uuid(), 'com.docker.compose.volume' => 'app-storage']));
        app()->instance(AgeCli::class, new AgeCli(base_path('tests/Fixtures/bin/fake-age'), base_path('tests/Fixtures/bin/fake-age-keygen')));
        app()->instance(RestoreSpace::FREE_BYTES, fn (): ?int => $this->freeBytes);
        $run = $this->instance->docker->runResponder;
        $this->instance->docker->respondToRun(fn (ContainerSpec $spec): ?ProcessOutcome => $this->answerRun($spec) ?? ($run instanceof Closure ? $run($spec) : null));
        $oneOff = $this->instance->docker->composeRunResponder;
        $this->instance->docker->respondToComposeRun(fn (array $command, ?string $input = null): ?ProcessOutcome => $this->answerOneOff($command) ?? ($oneOff instanceof Closure ? $oneOff($command, $input) : null));
    }

    public function appKey(): string
    {
        return new EnvFiles($this->instance->directory)->read('app')['APP_KEY'];
    }

    /** A plain fsa backup in backups/ made by this instance (or `$instance`) with its APP key (or `$appKey`). */
    public function backup(string $name = 'manual-20261019T120000Z', ?string $appKey = null, ?TarBuilder $storage = null, ?string $instance = null): string
    {
        return BackupSetFixture::fsa($this->instance->directory->root.'/backups/'.$name, manifest: $this->manifest($instance), storage: $storage, appKey: $appKey ?? $this->appKey());
    }

    /** @return array{instance: array{uuid: string, project: string}} the manifest part naming the instance that made a backup */
    public function manifest(?string $instance = null): array
    {
        $state = $this->instance->state();

        return ['instance' => ['uuid' => $instance ?? $state->uuid(), 'project' => $state->project()]];
    }

    /** @param  array<string, mixed>  $options */
    public function restore(string $backup = 'manual-20261019T120000Z', array $options = []): array
    {
        return $this->instance->run('fsa:restore', ['backup' => $backup, '--yes' => true, ...$options]);
    }

    /** @return list<ContainerSpec> runContainer specs whose command or entrypoint matches */
    public function specs(string $match): array
    {
        $specs = array_map(static fn (array $call): ContainerSpec => $call['spec'], $this->instance->docker->called('runContainer'));

        return array_values(array_filter($specs, static fn (ContainerSpec $spec): bool => $spec->entrypoint === $match || ($spec->command[1] ?? null) === $match));
    }

    /** @return list<string> the restore-db actions in call order */
    public function actions(): array
    {
        return array_column($this->databaseCalls, 'action');
    }

    private function answerRun(ContainerSpec $spec): ?ProcessOutcome
    {
        return match (true) {
            ($spec->command[0] ?? null) === 'fsa' && ($spec->command[1] ?? null) === 'restore-prepare' => $this->prepare($spec),
            $spec->entrypoint === 'pg_restore' => $this->pgRestore ?? new ProcessOutcome(0),
            $spec->entrypoint === 'tar' => $this->tar ?? new ProcessOutcome(0),
            default => null,
        };
    }

    /** The prepare container, run in-process with the arguments and stdin fsa gave it. */
    private function prepare(ContainerSpec $spec): ProcessOutcome
    {
        $calls = $this->instance->docker->called('runContainer');
        $input = json_decode((string) ($calls[array_key_last($calls)]['input'] ?? '{}'), true) ?: [];
        $options = [];
        foreach (array_slice($spec->command, 2) as $argument) {
            if (preg_match('/^--([a-z-]+)=(.*)$/D', $argument, $match) === 1) {
                $options[$match[1]] = $match[2];
            }
        }
        $keys = array_map(static fn (array $key): array => ['fingerprint' => $key['fingerprint'], 'key' => BackupChecksums::key($key['hmac_key'])], $input['keys'] ?? []);
        $preparer = new BackupPreparer(app(AgeCli::class), $this->tools());

        try {
            $prepared = $preparer->prepare($options['source'], $options['scratch'], $keys, MigrationSet::image(base_path('database/migrations')), $options['identity-file'] ?? null, $input['passphrase'] ?? null);
        } catch (UnsafeArchive $e) {
            return InstanceCommandFixture::oneOffJson(['command' => 'restore-prepare', 'status' => 'failed', 'errors' => [['code' => 'backup_unsafe', 'message' => $e->getMessage(), 'hint' => null]]]);
        } catch (RuntimeException $e) {
            $code = $e->getMessage() === 'identity_required' ? 'identity_required' : (str_starts_with($e->getMessage(), 'age could not') ? 'decrypt_failed' : 'prepare_failed');

            return InstanceCommandFixture::oneOffJson(['command' => 'restore-prepare', 'status' => 'failed', 'errors' => [['code' => $code, 'message' => $e->getMessage(), 'hint' => null]]]);
        }

        return InstanceCommandFixture::oneOffJson(['command' => 'restore-prepare', 'status' => 'ok', 'data' => $prepared->toArray()]);
    }

    private function tools(): BackupTools
    {
        return BackupSetFixture::tools($this->migrations, $this->extraEntries);
    }

    /** @param  list<string>  $command */
    private function answerOneOff(array $command): ?ProcessOutcome
    {
        return match (true) {
            ($command[0] ?? null) === 'valkey-cli' => new ProcessOutcome(0, "OK\n"),
            ($command[2] ?? null) === 'instance:restore-db' => $this->database($command),
            ($command[2] ?? null) === 'instance:restore-finish' => $this->finish ?? $this->ok('instance:restore-finish', [
                'epoch' => 2, 'previous_epoch' => 1, 'activity_id' => 99, 'restored_heads' => [],
                'carried' => ['devices_revoked' => 1, 'users_deactivated' => 0], 'sequences' => ['sequences' => 12, 'number_sequences' => 1],
                'cleared' => ['sessions' => 3, 'password_reset_tokens' => 0, 'login_links' => 1], 'grants' => ['revoked_public' => 3, 'backup_role' => true],
                'decrypt' => ['tested' => 2, 'failed' => []],
            ]),
            ($command[2] ?? null) === 'instance:upgrade-migrate' => $this->upgradeMigrate ?? $this->ok('instance:upgrade-migrate', [
                'migrated' => ['2026_10_26_100200_add_schedule_to_backup_runs'], 'steps' => [], 'invalid_indexes' => [], 'lock_timeout' => '1min',
            ]),
            ($command[2] ?? null) === 'audit:seal' => new ProcessOutcome(0, "Sealed 0 audit entries.\n"),
            ($command[2] ?? null) === 'audit:verify' => new ProcessOutcome(0, '{"verified_at":"2026-10-19T12:00:00+00:00","epoch":1,"heads":{"0":{"seq":7,"hash":"'.str_repeat('a', 64).'"}}}'."\n"),
            ($command[2] ?? null) === 'instance:restore-drill' => $this->drill ?? $this->ok('instance:restore-drill', [
                'database' => 'restore_drill_x', 'counts' => ['organizations' => 1, 'submissions' => 4], 'audit' => ['epoch' => 1, 'heads' => [], 'intact' => true, 'problems' => []],
                'files' => ['referenced' => 1, 'missing' => 0, 'sample' => []], 'render' => ['status' => 'pass', 'submission' => 'x', 'bytes' => 1834],
                'checks' => [['name' => 'render', 'status' => 'pass', 'message' => 'ok']],
            ]),
            default => null,
        };
    }

    /** @param  list<string>  $command */
    private function database(array $command): ProcessOutcome
    {
        $action = $command[3] ?? '';
        $options = [];
        foreach (array_slice($command, 4) as $argument) {
            if (preg_match('/^--([a-z-]+)=(.*)$/D', $argument, $match) === 1) {
                $options[$match[1]] = $match[2];
            }
        }
        $calls = $this->instance->docker->called('composeRun');
        $this->databaseCalls[] = ['action' => $action, 'options' => array_slice($command, 4), 'env' => $calls[array_key_last($calls)]['env'] ?? []];
        if ($action === 'audit' && $this->auditMismatch !== null) {
            return InstanceCommandFixture::oneOffJson(['command' => 'instance:restore-db', 'status' => 'failed', 'errors' => [['code' => 'schema_mismatch', 'message' => 'The restored database holds code this version does not define: '.$this->auditMismatch.'.', 'hint' => null]]]);
        }
        if (isset($this->failDatabase[$action])) {
            return InstanceCommandFixture::oneOffJson(['command' => 'instance:restore-db', 'status' => 'failed', 'errors' => [['code' => 'database_failed', 'message' => "{$action} failed", 'hint' => null]]]);
        }
        $data = match ($action) {
            'create' => $this->create($options),
            'drop' => ['dropped' => $this->forget($options['database'] ?? '')],
            'swap' => $this->swap($options['database'] ?? '', $options['live'] ?? '', $options['aside'] ?? ''),
            'rename' => $this->swap($options['database'] ?? '', $options['to'] ?? '', ''),
            'list' => ['databases' => array_values(array_filter($this->databases, static fn (string $name): bool => str_starts_with($name, $options['prefix'] ?? '')))],
            default => [],
        };

        return $this->ok('instance:restore-db', $data);
    }

    /**
     * @param  array<string, string>  $options
     * @return array<string, mixed>
     */
    private function create(array $options): array
    {
        $this->databases[] = $options['database'];

        return ['database' => $options['database'], 'owner' => $options['restricted-role'] ?? 'form_schema', 'restricted' => isset($options['restricted-role'])];
    }

    private function forget(string $database): bool
    {
        $known = in_array($database, $this->databases, true);
        $this->databases = array_values(array_diff($this->databases, [$database]));

        return $known;
    }

    /** @return array<string, string> */
    private function swap(string $incoming, string $live, string $aside): array
    {
        $this->databases = array_values(array_map(static fn (string $name): string => match ($name) {
            $live => $aside, $incoming => $live, default => $name,
        }, $this->databases));

        return ['live' => $live, 'aside' => $aside, 'incoming' => $incoming];
    }

    /** @param  array<string, mixed>  $data */
    private function ok(string $command, array $data): ProcessOutcome
    {
        return InstanceCommandFixture::oneOffJson(['command' => $command, 'status' => 'ok', 'changed' => true, 'data' => $data]);
    }
}
