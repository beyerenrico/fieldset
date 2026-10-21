<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\LockHolder;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Upgrade\DetachedSupervisor;
use Tests\Support\Instance\TemporaryInstance;
use Tests\Support\Instance\UpgradeFixture;

/*
 * Following an upgrade whose foreground went away (M12 gate O3, O4): the supervisor's result stays until the next launch
 * replaces it; `fieldset upgrade --follow` attaches to a running supervisor or returns the last result; `fieldset status` shows it as
 * `last_upgrade`, tells a running operation from an interrupted one and names a stale lock; `--resume` with nothing open
 * starts nothing and a resumed run aims at the release its journal began with.
 */

beforeEach(function (): void {
    $this->upgrade = new UpgradeFixture;
    $this->dir = $this->upgrade->instance->directory->root;
    $this->launched = 0;
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
});

/** The supervisor container answers: it leaves `$exit` with `$codes` as its result and is gone at once. */
function supervisorLeaves(object $test, int $exit, array $codes = []): void
{
    $docker = $test->upgrade->instance->docker;
    $previous = $docker->runResponder;
    $docker->respondToRun(function (ContainerSpec $spec) use ($test, $exit, $codes, $previous): ?ProcessOutcome {
        if (! in_array('--supervise', $spec->command, true)) {
            return $previous($spec);
        }
        $test->launched++;
        file_put_contents($test->dir.'/'.$spec->env[DetachedSupervisor::RESULT_ENV], json_encode(['exit_code' => $exit, 'finished_at' => '2026-10-20T10:30:00Z', 'envelope' => [
            'command' => 'upgrade', 'status' => $exit === 0 ? 'ok' : 'rolled_back', 'changed' => true, 'data' => ['from' => '2.0.0-alpha.1', 'to' => UpgradeFixture::TARGET],
            'errors' => array_map(static fn (string $code): array => ['code' => $code, 'message' => 'x', 'hint' => null, 'retryable' => true], $codes)]]));

        return new ProcessOutcome(0, str_repeat((string) $test->launched, 64)."\n");
    });
}

it('refuses --resume with nothing to resume and starts no supervisor, in the foreground and in the supervisor (exit 2)', function (): void {
    supervisorLeaves($this, 0);

    [$code, $json] = $this->upgrade->instance->run('fieldset:upgrade', ['--resume' => true]);
    [$supervised, $supervisedJson] = $this->upgrade->upgrade(['--resume' => true, '--to' => null]);

    expect($code)->toBe(2, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe('nothing_to_resume')
        ->and($this->launched)->toBe(0)
        ->and($supervised)->toBe(2)->and($supervisedJson['errors'][0]['code'])->toBe('nothing_to_resume')
        ->and($this->upgrade->instance->state()->version())->toBe('2.0.0-alpha.1')
        ->and(new OperationJournal($this->upgrade->instance->directory)->current())->toBeNull();
});

it('resumes towards the release the interrupted run aimed at, not the latest one', function (): void {
    $journal = new OperationJournal($this->upgrade->instance->directory);
    $journal->begin('upgrade', posix_getuid(), ['to' => UpgradeFixture::TARGET, 'major' => false, 'drop_jobs' => false]);
    $this->upgrade->latest = null; // `latest/download/` answers 404

    [$code, $json] = $this->upgrade->upgrade(['--resume' => true, '--to' => null]);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data']['to'])->toBe(UpgradeFixture::TARGET)
        ->and($this->upgrade->instance->state()->version())->toBe(UpgradeFixture::TARGET);
});

it('keeps the result for --follow and fieldset status after the foreground is gone, until the next launch replaces it', function (): void {
    supervisorLeaves($this, 5, ['target_unhealthy']);

    [$code] = $this->upgrade->instance->run('fieldset:upgrade', ['--to' => UpgradeFixture::TARGET]);
    $first = glob($this->dir.'/operations/supervisor-*.json');
    [$followed, $followedJson] = $this->upgrade->instance->run('fieldset:upgrade', ['--follow' => true]);
    [, $status] = $this->upgrade->instance->run('fieldset:status');
    [$again] = $this->upgrade->instance->run('fieldset:upgrade', ['--to' => UpgradeFixture::TARGET]);
    $second = glob($this->dir.'/operations/supervisor-*.json');

    expect($code)->toBe(5)->and($first)->toHaveCount(1)
        ->and($followed)->toBe(5, (string) json_encode($followedJson))->and($followedJson['errors'][0]['code'])->toBe('target_unhealthy')
        ->and($status['data']['last_upgrade'])->toMatchArray(['exit_code' => 5, 'status' => 'rolled_back', 'codes' => ['target_unhealthy'], 'to' => UpgradeFixture::TARGET,
            'finished_at' => '2026-10-20T10:30:00Z', 'result_file' => substr((string) $first[0], strlen($this->dir) + 1)])
        ->and($again)->toBe(5)->and($second)->toHaveCount(1)->and($second)->not->toBe($first);
});

it('attaches --follow to a running supervisor, follows its journal and returns its envelope', function (): void {
    $state = $this->upgrade->instance->state();
    $id = str_repeat('a', 64);
    $result = 'operations/supervisor-'.str_repeat('b', 16).'.json';
    $this->upgrade->instance->docker->withRunningContainer($id, '2026-10-20T10:00:00Z', $this->upgrade->supervisorLabels(), $state->project().'-supervisor');
    $this->upgrade->instance->directory->write(DetachedSupervisor::POINTER, json_encode(['container' => $id, 'result' => $result]));
    $polls = 0;
    $this->upgrade->instance->docker->onCall['inspectContainer'] = function (array $args) use (&$polls, $id, $result): void {
        if ($args['id'] === $id && ++$polls === 2) {
            file_put_contents($this->dir.'/'.$result, json_encode(['exit_code' => 0, 'envelope' => ['command' => 'upgrade', 'status' => 'ok', 'changed' => true, 'data' => ['to' => UpgradeFixture::TARGET]]]));
            $this->upgrade->instance->docker->forgetContainer($id);
        }
    };

    [$code, $json] = $this->upgrade->instance->run('fieldset:upgrade', ['--follow' => true]);

    expect($code)->toBe(0, (string) json_encode($json))->and($json['data']['to'])->toBe(UpgradeFixture::TARGET)
        ->and($polls)->toBeGreaterThanOrEqual(2)
        ->and(file_exists($this->dir.'/'.$result))->toBeTrue();
});

it('says there is nothing to follow, refuses --follow with other flags, and names --follow while a supervisor runs', function (): void {
    [$nothing, $nothingJson] = $this->upgrade->instance->run('fieldset:upgrade', ['--follow' => true]);
    [$mixed, $mixedJson] = $this->upgrade->instance->run('fieldset:upgrade', ['--follow' => true, '--to' => UpgradeFixture::TARGET]);
    $this->upgrade->instance->docker->withRunningContainer(str_repeat('f', 64), '2026-10-20T10:00:00Z', $this->upgrade->supervisorLabels(), $this->upgrade->instance->state()->project().'-supervisor');
    [$busy, $busyJson] = $this->upgrade->instance->run('fieldset:upgrade', ['--to' => UpgradeFixture::TARGET]);

    expect($nothing)->toBe(2)->and($nothingJson['errors'][0]['code'])->toBe('nothing_to_follow')
        ->and($mixed)->toBe(2)->and($mixedJson['errors'][0]['code'])->toBe('invalid_flag')
        ->and($busy)->toBe(9)->and($busyJson['errors'][0]['hint'])->toContain('fieldset upgrade --follow')
        ->and($busyJson['data']['container'])->toEndWith('-supervisor');
});

it('tells a running operation from an interrupted one, names the supervisor and a stale lock (O4)', function (): void {
    $this->upgrade->interrupted('migrate');
    [, $interrupted] = $this->upgrade->instance->run('fieldset:status');
    $state = $this->upgrade->instance->state();
    $this->upgrade->instance->docker->withRunningContainer(str_repeat('f', 64), '2026-10-20T10:00:00Z', $this->upgrade->supervisorLabels(), $state->project().'-supervisor');
    [, $running] = $this->upgrade->instance->run('fieldset:status');
    $this->upgrade->instance->docker->forgetContainer(str_repeat('f', 64));
    $lock = app(InstanceLock::class);
    $handle = $lock->acquire(new LockHolder('fieldset', str_repeat('9', 64), '2026-10-20T09:00:00Z', 7, 'gone', posix_getuid()), 'upgrade');
    [, $stale] = $this->upgrade->instance->run('fieldset:status');
    $lock->release($handle);

    expect($interrupted['data']['operation'])->toMatchArray(['operation' => 'upgrade', 'state' => 'interrupted'])
        ->and($interrupted['data']['supervisor'])->toBe(['running' => false, 'container' => null])
        ->and($interrupted['data']['lock'])->toBeNull()
        ->and($running['data']['operation']['state'])->toBe('running')
        ->and($running['data']['supervisor'])->toBe(['running' => true, 'container' => $state->project().'-supervisor'])
        ->and($stale['data']['operation']['state'])->toBe('interrupted')
        ->and($stale['data']['lock'])->toMatchArray(['operation' => 'upgrade', 'stale' => true]);
});
