<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Operations\JournalRecord;
use Closure;

/**
 * An installed, running instance (ConfigFixture) whose Docker answers the steps of `fsa reset` (plan U23, ADR 0088):
 * `instance:reset plan|recreate|epoch` like the app (the epoch begins once: a second call answers `began: false`),
 * `instance:audit-export` as a JSON Lines stream into the file fsa names, `audit:verify --heads`, `audit:seal`, the Valkey
 * flush, a safety backup that leaves a real backup directory behind, and `instance:restore-db list|drop` over
 * `$databases` (the leftovers of earlier restores). `$hooks` run before an artisan one-off is answered (a signal, a
 * failure); `$answers` replace an action's answer.
 */
final class ResetFixture
{
    public const string HEAD = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    public const string SAFETY = 'safety-reset-20261019T120000Z';

    public ConfigFixture $config;

    public InstanceCommandFixture $instance;

    /** @var array<string, mixed> what `instance:reset plan` answers */
    public array $plan = [
        'organizations' => ['total' => 1, 'non_demo' => 1],
        'organization' => ['name' => 'Acme Forms', 'locale' => 'en'],
        'operators' => [['email' => 'owner@example.com', 'name' => 'Olivia Owner', 'locale' => 'en']],
        'sequences' => ['users_id_seq' => 40, 'organization_user_id_seq' => 11, 'activity_log_id_seq' => 900],
    ];

    /** @var list<string> the databases `instance:restore-db list` reports */
    public array $databases = ['form_schema', 'postgres'];

    /** @var list<string> databases `instance:restore-db drop` dropped */
    public array $dropped = [];

    /** @var array<string, string|null> database => the role dropped with it (`--role=`) */
    public array $droppedRoles = [];

    /** @var array<string, ProcessOutcome|list<ProcessOutcome>> instance:reset action => answer (a list is used up one per call) */
    public array $answers = [];

    /** @var array<string, Closure(): void> artisan command (or instance:reset action) => runs before the answer */
    public array $hooks = [];

    /** @var list<array{action: string, command: list<string>, input: string|null}> */
    public array $calls = [];

    private bool $epochBegan = false;

    /** @param  array<string, mixed>  $installFlags */
    public function __construct(array $installFlags = ['--owner-email' => 'owner@example.com'])
    {
        $this->config = new ConfigFixture($installFlags);
        $this->instance = $this->config->instance;
        $this->instance->withRunningStack();
        $state = $this->instance->state(); // Compose's own storage volume, as `docker volume ls` lists it
        $this->instance->docker->withResource(new DockerResource(DockerResource::VOLUME, $state->project().'_app-storage', [Labels::INSTANCE => $state->uuid(), 'com.docker.compose.volume' => 'app-storage']));
        $previous = $this->instance->docker->composeRunResponder;
        $this->instance->docker->respondToComposeRun(fn (array $command, ?string $input = null): ?ProcessOutcome => $this->answer($command, $input) ?? ($previous instanceof Closure ? $previous($command, $input) : null));
        $this->instance->docker->fileRunResponder = fn (?string $entrypoint, array $command, string $file): ?ProcessOutcome => ($command[2] ?? null) === 'instance:audit-export' ? $this->export($file) : null;
    }

    /**
     * `fsa reset --yes --json` with further options.
     *
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function reset(array $options = ['--yes' => true]): array
    {
        return $this->instance->run('fsa:reset', $options);
    }

    /** @return list<string> the instance:reset actions in call order */
    public function actions(): array
    {
        return array_column($this->calls, 'action');
    }

    /** @return list<string> the phase names of a journal in order (attempts included) */
    public static function phases(JournalRecord $record): array
    {
        return array_column($record->phases(), 'name');
    }

    public function archived(): JournalRecord
    {
        return $this->config->archivedJournal('reset');
    }

    /** @param  list<string>  $command */
    private function answer(array $command, ?string $input): ?ProcessOutcome
    {
        if (($command[0] ?? null) === 'fsa' && ($command[1] ?? null) === 'backup-daemon' && ($command[3] ?? null) === '--kind=safety-reset') {
            BackupSetFixture::fsa($this->instance->directory->root.'/backups/'.self::SAFETY, ['kind' => 'safety-reset']);

            return null; // InstanceCommandFixture answers with the recorded run
        }
        $name = $command[2] ?? '';
        $action = $name === 'instance:reset' ? (string) ($command[3] ?? '') : null;
        if (isset($this->hooks[$name])) {
            ($this->hooks[$name])();
        }
        if ($action !== null && isset($this->hooks[$action])) {
            ($this->hooks[$action])();
        }

        return match (true) {
            $action !== null => $this->resetAction($action, $command, $input),
            $name === 'instance:restore-db' => $this->restoreDb($command),
            ($command[0] ?? null) === 'valkey-cli' => new ProcessOutcome(0, "OK\n"),
            $name === 'audit:seal' => new ProcessOutcome(0, "Sealed 0 audit entries.\n"),
            $name === 'audit:verify' => new ProcessOutcome(0, '{"verified_at":"2026-10-19T12:00:00+00:00","epoch":1,"heads":{"0":{"seq":7,"hash":"'.self::HEAD.'"}}}'."\n"),
            default => null,
        };
    }

    /** @param  list<string>  $command */
    private function resetAction(string $action, array $command, ?string $input): ProcessOutcome
    {
        $this->calls[] = ['action' => $action, 'command' => $command, 'input' => $input];
        $answer = $this->answers[$action] ?? null;
        if (is_array($answer) && $answer !== []) {
            $answer = array_shift($this->answers[$action]);
        }
        if ($answer instanceof ProcessOutcome) {
            return $answer;
        }

        return match ($action) {
            'plan' => self::ok($this->plan, false),
            'recreate' => self::ok(['database' => 'form_schema', 'dropped' => true, 'created' => true]),
            'epoch' => $this->epoch(),
            default => self::failed('invalid_input', 'The action must be plan, recreate or epoch.'),
        };
    }

    /** @param  list<string>  $command */
    private function restoreDb(array $command): ProcessOutcome
    {
        $database = '';
        $role = null;
        foreach ($command as $argument) {
            $database = str_starts_with($argument, '--database=') ? substr($argument, 11) : $database;
            $role = str_starts_with($argument, '--role=') ? substr($argument, 7) : $role;
        }
        if (($command[3] ?? null) === 'drop') {
            $this->dropped[] = $database;
            $this->droppedRoles[$database] = $role;
            $this->databases = array_values(array_diff($this->databases, [$database]));

            return InstanceCommandFixture::oneOffJson(['command' => 'instance:restore-db', 'status' => 'ok', 'changed' => true, 'data' => ['database' => $database, 'dropped' => true]]);
        }

        return InstanceCommandFixture::oneOffJson(['command' => 'instance:restore-db', 'status' => 'ok', 'data' => ['databases' => $this->databases]]);
    }

    private function epoch(): ProcessOutcome
    {
        $began = ! $this->epochBegan;
        $this->epochBegan = true;

        return self::ok(['epoch' => 2, 'previous_epoch' => 1, 'activity_id' => 41, 'began' => $began], $began);
    }

    private function export(string $file): ProcessOutcome
    {
        file_put_contents($file, json_encode(['format' => 'fieldset-audit-export', 'version' => '1.0', 'epoch' => 1, 'heads' => ['0' => ['seq' => 7, 'hash' => self::HEAD]], 'entries' => 2])."\n"
            .'{"id":1,"event":"organization.created"}'."\n".'{"id":2,"event":"auth.login"}'."\n");
        chmod($file, 0600);

        return new ProcessOutcome(0);
    }

    /** @param  array<string, mixed>  $data */
    public static function ok(array $data, bool $changed = true): ProcessOutcome
    {
        return InstanceCommandFixture::oneOffJson(['command' => 'instance:reset', 'status' => 'ok', 'changed' => $changed, 'data' => $data]);
    }

    public static function failed(string $code, string $message): ProcessOutcome
    {
        return InstanceCommandFixture::oneOffJson(['command' => 'instance:reset', 'status' => 'failed', 'errors' => [['code' => $code, 'message' => $message, 'hint' => null]]]);
    }
}
