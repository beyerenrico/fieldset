<?php

declare(strict_types=1);

use App\Domain\Instance\Console\FsaCommand;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Upgrade\DetachedSupervisor;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\Instance\TemporaryInstance;
use Tests\Support\Instance\UpgradeFixture;
use Tests\Support\Release\ReleaseFixtures;

/*
 * The upgrade journal after an interruption (plan U18, KTD5, KTD19, R54, ADR 0086): `fsa upgrade --resume` decides from
 * the journal (before the safety backup: the previous services again; from the safety backup until the commit: the
 * rollback; after the commit: only the opening, never a restore), `fsa status` names the phase and the resume command,
 * the foreground `fsa upgrade` starts the detached supervisor and renders its result, and a target release that adds a
 * service and an env key starts from its own staged files.
 */

beforeEach(function (): void {
    $this->upgrade = new UpgradeFixture;
    $this->dir = $this->upgrade->instance->directory->root;
    $this->config = $this->upgrade->restore->config;
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
});

it('reports a failure while opening without restoring anything, and finishes opening on --resume', function (): void {
    $failures = 1;
    $this->upgrade->instance->docker->onCall['composeUp'] = function (array $args) use (&$failures): void {
        if ($args['services'] === ['queue', 'scheduler', 'backup'] && $failures-- > 0) {
            throw new DockerCommandFailed('compose up', new ProcessOutcome(1, '', 'container queue is unhealthy'));
        }
    };

    [$code, $json] = $this->upgrade->upgrade();
    $open = new OperationJournal($this->upgrade->instance->directory)->current();

    expect($code)->toBe(4, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('open_failed')
        ->and($json['errors'][0]['hint'])->toContain('fsa upgrade --resume')
        ->and($this->upgrade->instance->state()->version())->toBe(UpgradeFixture::TARGET)
        ->and($open?->lastStartedPhase())->toBe('open')
        ->and($this->upgrade->restore->actions())->toBe([]);

    $mark = $this->config->mark();
    [$code, $json] = $this->upgrade->upgrade(['--resume' => true, '--to' => null]);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($this->upgrade->ups($mark))->toBe(['queue,scheduler,backup'])
        ->and($this->upgrade->restore->actions())->toBe([])
        ->and($this->upgrade->gate()['raised'])->toBeFalse()
        ->and(new OperationJournal($this->upgrade->instance->directory)->current())->toBeNull();
});

it('finishes opening a committed upgrade whatever the flags, and never restores it', function (): void {
    $this->upgrade->interrupted('commit', completed: true);
    $mark = $this->config->mark();

    [$code, $json] = $this->upgrade->instance->run('fsa:upgrade', ['--supervise' => true]);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($this->upgrade->instance->state()->version())->toBe(UpgradeFixture::TARGET)
        ->and($this->upgrade->ups($mark))->toBe(['queue,scheduler,backup'])
        ->and($this->upgrade->restore->actions())->toBe([])
        ->and($this->upgrade->gate()['raised'])->toBeFalse();
});

it('rolls back an upgrade killed between the safety backup and the commit', function (string $phase): void {
    $this->upgrade->interrupted($phase);

    [$code, $json] = $this->upgrade->upgrade(['--resume' => true, '--to' => null]);

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('upgrade_interrupted')
        ->and($this->upgrade->restore->actions())->toContain('swap')
        ->and($this->upgrade->instance->state()->version())->toBe('2.0.0-alpha.1')
        ->and($this->upgrade->compose())->toContain(ReleaseFixtures::APP_DIGEST)
        ->and($this->upgrade->gate()['raised'])->toBeFalse()
        ->and(new OperationJournal($this->upgrade->instance->directory)->current())->toBeNull();
})->with(['migrate', 'start', 'smoke']);

it('brings the previous services back for an upgrade killed before its safety backup completed (exit 9)', function (): void {
    $this->upgrade->interrupted('safety_backup');
    $mark = $this->config->mark();

    [$code, $json] = $this->upgrade->upgrade(['--resume' => true, '--to' => null]);

    expect($code)->toBe(9, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('upgrade_interrupted')
        ->and($this->upgrade->ups($mark)[array_key_last($this->upgrade->ups($mark))])->toBe('queue,scheduler,backup')
        ->and($this->upgrade->restore->actions())->toBe([])
        ->and($this->upgrade->gate()['raised'])->toBeFalse()
        ->and(new OperationJournal($this->upgrade->instance->directory)->current())->toBeNull();
});

it('continues a rollback that stopped with exit 8 on --resume', function (): void {
    $this->upgrade->child = 'fail';
    $this->upgrade->restore->pgRestore = new ProcessOutcome(1, '', 'pg_restore: error: could not connect');
    [$code] = $this->upgrade->upgrade();
    expect($code)->toBe(8);

    $this->upgrade->restore->pgRestore = null;
    [$code, $json] = $this->upgrade->upgrade(['--resume' => true, '--to' => null]);

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($this->upgrade->instance->state()->version())->toBe('2.0.0-alpha.1')
        ->and($this->upgrade->gate()['raised'])->toBeFalse()
        ->and(new OperationJournal($this->upgrade->instance->directory)->current())->toBeNull();
});

it('finishes a rollback swap that stopped half-way without restoring over the swapped database again', function (): void {
    $this->upgrade->child = 'fail';
    $failures = 1;
    $this->upgrade->instance->docker->onCall['composeRun'] = function (array $args) use (&$failures): void {
        if (($args['command'][0] ?? null) === 'valkey-cli' && $failures-- > 0) {
            throw new RuntimeException('killed while flushing');
        }
    };
    [$code] = $this->upgrade->upgrade();
    expect($code)->toBe(8)->and(array_count_values($this->upgrade->restore->actions())['swap'] ?? 0)->toBe(1);
    $before = count($this->upgrade->restore->actions());

    [$code, $json] = $this->upgrade->upgrade(['--resume' => true, '--to' => null]);
    $resumed = array_slice($this->upgrade->restore->actions(), $before);

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($resumed)->not->toContain('create')->not->toContain('swap')
        ->and($resumed)->toContain('list')
        ->and($this->upgrade->instance->state()->version())->toBe('2.0.0-alpha.1')
        ->and($this->upgrade->gate()['raised'])->toBeFalse();
});

it('refuses a new upgrade while an interrupted one is open, naming the resume command', function (): void {
    $this->upgrade->interrupted('migrate');

    [$code, $json] = $this->upgrade->upgrade();
    [, $status] = $this->upgrade->instance->run('fsa:status');
    $warning = array_values(array_filter($status['warnings'], static fn (array $w): bool => $w['code'] === 'operation_unfinished'))[0];

    expect($code)->toBe(9)->and($json['errors'][0]['code'])->toBe('operation_unfinished')
        ->and($json['errors'][0]['hint'])->toContain('fsa upgrade --resume')
        ->and($status['data']['operation'])->toMatchArray(['operation' => 'upgrade', 'last_phase' => 'migrate', 'command' => 'fsa upgrade --resume'])
        ->and($warning['hint'])->toContain('fsa upgrade --resume')->toContain('rolls back');
});

it('starts the detached supervisor from the installed image with the socket and the instance at the same path, and renders its result', function (): void {
    $facts = $this->upgrade->instance->facts(['releaseUrl' => UpgradeFixture::RELEASE_URL, 'socket' => '/var/run/docker.sock', 'mounts' => ['/home/ops/keys' => false]]);
    $launched = null;
    $docker = $this->upgrade->instance->docker;
    $previous = $docker->runResponder;
    $docker->respondToRun(function (ContainerSpec $spec) use (&$launched, $previous): ?ProcessOutcome {
        if (in_array('--supervise', $spec->command, true)) {
            $launched = $spec;
            $file = $this->dir.'/'.$spec->env[DetachedSupervisor::RESULT_ENV];
            file_put_contents($file, json_encode(['exit_code' => 0, 'envelope' => ['schema_version' => 1, 'command' => 'upgrade', 'status' => 'ok', 'changed' => true,
                'data' => ['from' => '2.0.0-alpha.1', 'to' => UpgradeFixture::TARGET], 'secrets' => [], 'warnings' => [], 'errors' => []]]));

            return new ProcessOutcome(0, str_repeat('e', 64)."\n");
        }

        return $previous($spec);
    });

    [$code, $json] = $this->upgrade->instance->run('fsa:upgrade', ['--to' => UpgradeFixture::TARGET]);
    $state = $this->upgrade->instance->state();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data'])->toMatchArray(['from' => '2.0.0-alpha.1', 'to' => UpgradeFixture::TARGET])
        ->and($launched)->toBeInstanceOf(ContainerSpec::class)
        ->and($launched->image)->toBe($state->digests()['app'])
        ->and($launched->entrypoint)->toBe('fsa')
        ->and($launched->command)->toBe(['upgrade', '--supervise', '--dir', $this->dir, '--to', UpgradeFixture::TARGET, '--json'])
        ->and($launched->volumes)->toContain('/var/run/docker.sock:/var/run/docker.sock', $this->dir.':'.$this->dir, '/home/ops/keys:/home/ops/keys:ro')
        ->and($launched->detach)->toBeTrue()->and($launched->remove)->toBeTrue()->and($launched->restart)->toBe('no')
        ->and($launched->name)->toBe($state->project().'-supervisor')
        ->and($launched->labels)->toMatchArray($this->upgrade->supervisorLabels())
        ->and($launched->env)->toMatchArray(['FSA_HOST_UID' => (string) $facts->uid, 'FSA_RELEASE_URL' => UpgradeFixture::RELEASE_URL, 'FSA_INSTANCE_DIR' => $this->dir])
        ->and(array_keys($launched->env))->not->toContain('FSA_NONCE')
        ->and(glob($this->dir.'/operations/supervisor-*.json'))->toHaveCount(1); // M12 gate O3: the result stays for `fsa status` and `--follow`
});

it('follows the running supervisor through its journal, and a journal it cannot read for a moment does not stop it', function (): void {
    $docker = $this->upgrade->instance->docker;
    $previous = $docker->runResponder;
    $supervisor = str_repeat('e', 64);
    $polls = 0;
    $journal = new OperationJournal($this->upgrade->instance->directory);
    $docker->respondToRun(function (ContainerSpec $spec) use ($previous, $supervisor, $journal): ?ProcessOutcome {
        if (! in_array('--supervise', $spec->command, true)) {
            return $previous($spec);
        }
        $this->upgrade->instance->docker->withRunningContainer($supervisor, '2026-10-20T10:00:00Z');
        $journal->begin('upgrade', posix_getuid());
        $journal->startPhase('release', [], false);
        file_put_contents($this->dir.'/'.$spec->env[DetachedSupervisor::RESULT_ENV], json_encode(['exit_code' => 5, 'envelope' => ['command' => 'upgrade', 'status' => 'rolled_back',
            'changed' => true, 'data' => ['running' => '2.0.0-alpha.1'], 'errors' => [['code' => 'target_unhealthy', 'message' => 'x', 'hint' => null, 'retryable' => true]]]]));

        return new ProcessOutcome(0, $supervisor."\n");
    });
    $docker->onCall['inspectContainer'] = function (array $args) use (&$polls, $supervisor, $journal): void {
        if ($args['id'] !== $supervisor) {
            return;
        }
        $polls++;
        match ($polls) {
            2 => chmod($this->dir.'/operations/current.json', 0000), // unreadable for one poll
            3 => chmod($this->dir.'/operations/current.json', 0600),
            4 => $journal->completePhase('release'),
            6 => $this->upgrade->instance->docker->forgetContainer($supervisor),
            default => null,
        };
    };
    $events = new BufferedOutput;
    app()->instance(FsaCommand::PROGRESS_OUTPUT, $events);

    [$code, $json] = $this->upgrade->instance->run('fsa:upgrade', ['--to' => UpgradeFixture::TARGET]);
    $phases = array_map(static fn (string $line): string => (string) (json_decode($line, true)['message'] ?? ''), array_filter(explode("\n", $events->fetch())));

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('target_unhealthy')
        ->and($phases)->toContain('Starting release', 'Completed release');
});

it('says where to look when the supervisor stops without a result', function (): void {
    $docker = $this->upgrade->instance->docker;
    $previous = $docker->runResponder;
    $docker->respondToRun(fn (ContainerSpec $spec): ?ProcessOutcome => in_array('--supervise', $spec->command, true) ? new ProcessOutcome(0, str_repeat('e', 64)."\n") : $previous($spec));

    [$code, $json] = $this->upgrade->instance->run('fsa:upgrade', ['--to' => UpgradeFixture::TARGET]);

    expect($code)->toBe(1)->and($json['errors'][0]['code'])->toBe('supervisor_lost')
        ->and($json['errors'][0]['hint'])->toContain('fsa status');
});

it('refuses to start a second supervisor while one runs (exit 9)', function (): void {
    $state = $this->upgrade->instance->state();
    $this->upgrade->instance->docker->withRunningContainer(str_repeat('f', 64), '2026-10-20T10:00:00Z', $this->upgrade->supervisorLabels(), $state->project().'-supervisor');

    [$code, $json] = $this->upgrade->instance->run('fsa:upgrade', ['--to' => UpgradeFixture::TARGET]);

    expect($code)->toBe(9)->and($json['errors'][0]['code'])->toBe('supervisor_running');
});

it('starts a target that adds a service and an env key from its own staged files, not from the installed renderer', function (): void {
    $this->upgrade->stage = static function (string $files): void {
        $compose = (string) file_get_contents($files.'/compose.yaml');
        $lines = explode("\n", $compose);
        $header = implode("\n", array_filter($lines, static fn (string $line): bool => str_starts_with($line, '#')));
        $document = json_decode(implode("\n", array_filter($lines, static fn (string $line): bool => ! str_starts_with($line, '#'))), true);
        $document['services']['indexer'] = [...$document['services']['gotenberg'], 'image' => $document['services']['app']['image'], 'env_file' => ['./env/indexer.env']];
        file_put_contents($files.'/compose.yaml', $header."\n".json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        file_put_contents($files.'/env/indexer.env', "INDEXER=1\n");
        file_put_contents($files.'/env/app.env', file_get_contents($files.'/env/app.env')."FSA_NEW_KEY='from-the-target'\n");
    };
    $mark = $this->config->mark();

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($this->upgrade->compose())->toContain('"indexer"')
        ->and((string) file_get_contents($this->dir.'/env/app.env'))->toContain("FSA_NEW_KEY='from-the-target'")
        ->and(is_file($this->dir.'/env/indexer.env'))->toBeTrue()
        ->and($this->upgrade->ups($mark)[0])->toContain('indexer')
        ->and($this->config->callsSince($mark, 'composeConfig'))->not->toBeEmpty();
});

it('refuses staged files that would mount the Docker socket or run privileged, and rolls back', function (string $change): void {
    $this->upgrade->stage = static function (string $files) use ($change): void {
        $compose = (string) file_get_contents($files.'/compose.yaml');
        $lines = explode("\n", $compose);
        $header = implode("\n", array_filter($lines, static fn (string $line): bool => str_starts_with($line, '#')));
        $document = json_decode(implode("\n", array_filter($lines, static fn (string $line): bool => ! str_starts_with($line, '#'))), true);
        if ($change === 'socket') {
            $document['services']['gotenberg']['volumes'][] = ['type' => 'bind', 'source' => '/var/run/docker.sock', 'target' => '/var/run/docker.sock'];
        } else {
            $document['services']['gotenberg']['privileged'] = true;
        }
        file_put_contents($files.'/compose.yaml', $header."\n".json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    };

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(5, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe('staged_invalid')
        ->and($this->upgrade->compose())->not->toContain('docker.sock')->not->toContain('privileged');
})->with(['socket', 'privileged']);

it('refuses staged files that do not pass docker compose config, and rolls back', function (): void {
    $this->upgrade->instance->docker->onCall['composeConfig'] = function (array $args): void {
        if (str_contains((string) $args['file'], '/operations/upgrade-')) {
            throw new DockerCommandFailed('compose config', new ProcessOutcome(15, '', 'services.app.image must be a string'));
        }
    };

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(5, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe('staged_invalid')
        ->and($this->upgrade->compose())->toContain(ReleaseFixtures::APP_DIGEST);
});
