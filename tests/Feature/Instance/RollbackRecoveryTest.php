<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\State\EnvFiles;
use Tests\Support\Instance\BackupSetFixture;
use Tests\Support\Instance\TemporaryInstance;
use Tests\Support\Instance\UpgradeFixture;
use Tests\Support\Release\ReleaseFixtures;

/*
 * `fieldset rollback` on its ways back and its edges (plan U18, R37, R55, R57, R64, R69, ADR 0086, review U18 A1, A3, A8, A10,
 * S1): requests in flight end before the security state is exported; a drain timeout is exit 9; going back restores
 * the files the swap replaced exactly, in journaled steps a kill cannot repeat on a live instance; the previous image
 * renders with env files in its own layout; exit 8 names the safety backup and both digest sets; a revert that cannot
 * start the workers is exit 4 with the upgraded app running; every safety backup left behind is sealed; older
 * pre-rollback safety backups go.
 */

beforeEach(function (): void {
    $this->upgrade = new UpgradeFixture;
    $this->dir = $this->upgrade->instance->directory->root;
    $this->config = $this->upgrade->restore->config;
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
});

/** The upgrade alpha.1 → alpha.2 every rollback here starts from; a target that adds a service and changes app.env. */
function upgradedTarget(UpgradeFixture $upgrade, bool $ownLayout = false): void
{
    if ($ownLayout) {
        $upgrade->editStagedCompose(static function (array $document): array {
            $document['services']['indexer'] = [...$document['services']['gotenberg'], 'image' => $document['services']['app']['image'], 'env_file' => ['./env/indexer.env']];

            return $document;
        }, static function (string $root): void {
            file_put_contents($root.'/env/indexer.env', "INDEXER=1\n");
            $app = (string) file_get_contents($root.'/env/app.env');
            file_put_contents($root.'/env/app.env', preg_replace('/^REDIS_PASSWORD=/m', 'VALKEY_PASSWORD=', $app)."FIELDSET_NEW_KEY='from-the-target'\n");
        });
    }
    [$code, $json] = $upgrade->upgrade();
    expect($code)->toBe(0, (string) json_encode($json));
    $upgrade->stage = null;
}

it('exports the security state only after no request holds a database session', function (): void {
    upgradedTarget($this->upgrade);
    $this->upgrade->sessions = [[['pid' => 9, 'application_name' => '', 'user' => 'fieldset', 'state' => 'active']], []];
    $mark = $this->config->mark();

    [$code, $json] = $this->upgrade->rollback();
    $order = array_values(array_filter(array_map(static fn (array $call): ?string => match ($call['command'][2] ?? null) {
        'instance:upgrade-db' => 'db:'.($call['command'][3] ?? ''), 'instance:security-export' => 'export', default => null,
    }, $this->config->callsSince($mark, 'composeRun'))));

    expect($code)->toBe(0, (string) json_encode($json))
        ->and(array_slice($order, 0, 3))->toBe(['db:foreign', 'db:foreign', 'export']);
});

it('stops with exit 9, exports nothing and closes its journal when a session stays, the queue does not drain or sealing fails', function (string $case, string $error): void {
    upgradedTarget($this->upgrade);
    $docker = $this->upgrade->instance->docker;
    $previous = $docker->composeRunResponder;
    match ($case) {
        'session' => $this->upgrade->sessions = [[['pid' => 9, 'application_name' => 'psql', 'user' => 'fieldset', 'state' => 'idle']]],
        'drain' => $this->upgrade->drain = [['pending' => 3, 'reserved' => 1, 'delayed' => 0]],
        default => $docker->respondToComposeRun(static fn (array $command, ?string $input = null): ?ProcessOutcome => ($command[2] ?? null) === 'audit:seal'
            ? new ProcessOutcome(1, '', 'could not connect') : $previous($command, $input)),
    };
    $mark = $this->config->mark();

    [$code, $json] = $this->upgrade->rollback();
    $open = new OperationJournal($this->upgrade->instance->directory)->current();
    $exported = array_filter($this->config->callsSince($mark, 'composeRun'), static fn (array $call): bool => ($call['command'][2] ?? null) === 'instance:security-export');
    $gate = $this->upgrade->gate()['raised'];
    $this->upgrade->sessions = [];
    $this->upgrade->drain = [];
    $docker->respondToComposeRun($previous);
    [$again, $againJson] = $this->upgrade->rollback(); // nothing left open: it starts over and finishes

    expect($code)->toBe(9, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe($error)
        ->and($json['errors'][0]['hint'])->toContain('`fieldset rollback`')->not->toContain('fieldset upgrade') // gate review C6
        ->and($exported)->toBe([])
        ->and($gate)->toBeFalse()
        ->and($open)->toBeNull() // review V1
        ->and($again)->toBe(0, (string) json_encode($againJson))
        ->and($this->upgrade->instance->state()->version())->toBe('2.0.0-alpha.1');
})->with([['session', 'requests_in_flight'], ['drain', 'drain_timeout'], ['seal', 'audit_seal_failed']]);

it('returns to exactly the files the swap replaced, the target\'s own service and env layout included', function (): void {
    upgradedTarget($this->upgrade, ownLayout: true);
    $compose = $this->upgrade->compose();
    $env = $this->upgrade->envFiles();
    $this->config->smokeFails = fn (array $urls): bool => str_contains($this->upgrade->compose(), ReleaseFixtures::APP_DIGEST);

    [$code, $json] = $this->upgrade->rollback();

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($json['data']['returned'])->toBeTrue()
        ->and($this->upgrade->compose())->toBe($compose)
        ->and($this->upgrade->envFiles())->toBe($env)
        ->and($this->upgrade->instance->state()->version())->toBe(UpgradeFixture::TARGET);
});

it('only finishes a return killed after it reopened the upgraded release: no Valkey flush, no gate', function (): void {
    upgradedTarget($this->upgrade);
    $this->config->smokeFails = fn (array $urls): bool => str_contains($this->upgrade->compose(), ReleaseFixtures::APP_DIGEST);
    [$code] = $this->upgrade->rollback();
    expect($code)->toBe(5);
    $this->config->smokeFails = static fn (array $urls): bool => true;
    $this->upgrade->reopen('rollback', 'return_open', completed: true);
    $mark = $this->config->mark();

    [$code, $json] = $this->upgrade->rollback();
    $flushes = array_filter($this->config->callsSince($mark, 'composeRun'), static fn (array $call): bool => ($call['command'][0] ?? null) === 'valkey-cli');

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($flushes)->toBe([])
        ->and($this->upgrade->ups($mark))->toBe([])
        ->and($this->upgrade->gate()['raised'])->toBeFalse()
        ->and(new OperationJournal($this->upgrade->instance->directory)->current())->toBeNull();
});

it('only opens again when a return was killed while opening: no second verification, no exit 8 (review V2)', function (): void {
    upgradedTarget($this->upgrade);
    $this->config->smokeFails = fn (array $urls): bool => str_contains($this->upgrade->compose(), ReleaseFixtures::APP_DIGEST);
    [$code] = $this->upgrade->rollback();
    expect($code)->toBe(5);
    $this->config->smokeFails = static fn (array $urls): bool => true;
    $this->upgrade->reopen('rollback', 'return_open');
    $mark = $this->config->mark();

    [$code, $json] = $this->upgrade->rollback();

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($this->upgrade->ups($mark))->toHaveCount(1)
        ->and($this->upgrade->gate()['raised'])->toBeFalse()
        ->and(new OperationJournal($this->upgrade->instance->directory)->current())->toBeNull();
});

it('lets the previous image render with the env files in its own layout and the current values', function (): void {
    $before = new EnvFiles($this->upgrade->instance->directory)->read('app');
    upgradedTarget($this->upgrade, ownLayout: true);
    $mounted = null;
    $docker = $this->upgrade->instance->docker;
    $previous = $docker->runResponder;
    $docker->respondToRun(function (ContainerSpec $spec) use ($previous, &$mounted): ?ProcessOutcome {
        if (($spec->command[0] ?? null) === 'upgrade-child') {
            foreach ($spec->volumes as $volume) {
                [$source, $target] = explode(':', $volume);
                if ($target === $this->dir.'/env') {
                    $mounted = ['volume' => $volume, 'app' => EnvFiles::decode((string) file_get_contents($source.'/app.env'))];
                }
            }
        }

        return $previous($spec);
    });

    [$code, $json] = $this->upgrade->rollback();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($mounted['volume'] ?? '')->toEndWith(':'.$this->dir.'/env:ro')
        ->and($mounted['app'] ?? [])->toBe($before)
        ->and($mounted['app'] ?? [])->not->toHaveKey('FIELDSET_NEW_KEY');
});

it('removes the merged env directory a killed render left, on the way back and in the cleanup (review V4)', function (string $phase): void {
    upgradedTarget($this->upgrade);
    if ($phase === 'abort') {
        $this->upgrade->restore->pgRestore = new ProcessOutcome(1, '', 'pg_restore: error: out of disk');
    }
    [$code] = $this->upgrade->rollback();
    expect($code)->toBe($phase === 'abort' ? 5 : 0);
    $id = substr($this->config->archivedJournal('rollback')->id(), 0, 12);
    $this->upgrade->reopen('rollback', $phase);
    $left = $this->dir.'/operations/rollback-env-'.$id;
    mkdir($left.'/env', 0700, true);
    file_put_contents($left.'/env/app.env', "APP_KEY='base64:left-behind'\n");

    [$resumed, $resumedJson] = $this->upgrade->rollback();

    expect($resumed)->toBe($phase === 'abort' ? 5 : 0, (string) json_encode($resumedJson))
        ->and(is_dir($left))->toBeFalse()
        ->and(new OperationJournal($this->upgrade->instance->directory)->current())->toBeNull();
})->with(['abort', 'cleanup']);

it('names the safety backup and both digest sets when going back fails too (exit 8)', function (): void {
    upgradedTarget($this->upgrade);
    $digests = $this->upgrade->instance->state()->digests();
    $previous = $this->upgrade->instance->state()->previous()['digests'];
    $this->config->smokeFails = static fn (array $urls): bool => true;

    [$code, $json] = $this->upgrade->rollback();

    expect($code)->toBe(8, (string) json_encode($json))
        ->and($json['data']['safety_backup'])->toStartWith($this->dir.'/backups/safety-rollback-')
        ->and($json['data']['digests'])->toBe(['previous' => $previous, 'current' => $digests]);
});

it('reports exit 4 with the upgraded app still running when going back cannot start the workers again', function (): void {
    upgradedTarget($this->upgrade);
    $this->upgrade->restore->pgRestore = new ProcessOutcome(1, '', 'pg_restore: error: out of disk');
    $this->upgrade->instance->docker->onCall['composeUp'] = static function (array $args): void {
        if ($args['services'] === ['queue', 'scheduler', 'backup']) {
            throw new DockerCommandFailed('compose up', new ProcessOutcome(1, '', 'container queue is unhealthy'));
        }
    };
    $mark = $this->config->mark();

    [$code, $json] = $this->upgrade->rollback();
    $stopped = array_merge(...array_map(static fn (array $stop): array => $stop['services'], $this->config->callsSince($mark, 'composeStop')));

    expect($code)->toBe(4, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('services_unhealthy')
        ->and($stopped)->not->toContain('app')
        ->and($this->upgrade->instance->state()->version())->toBe(UpgradeFixture::TARGET);
});

it('seals the safety backups it leaves behind when it goes back', function (string $case): void {
    upgradedTarget($this->upgrade);
    $this->upgrade->encrypt();
    if ($case === 'abort') {
        $this->upgrade->restore->pgRestore = new ProcessOutcome(1, '', 'pg_restore: error: out of disk');
    } else {
        $this->config->smokeFails = fn (array $urls): bool => str_contains($this->upgrade->compose(), ReleaseFixtures::APP_DIGEST);
    }

    [$code, $json] = $this->upgrade->rollback();

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($this->upgrade->sealed())->toContain($this->upgrade->safetyBackups[0])
        ->and(array_filter($this->upgrade->sealed(), static fn (string $name): bool => str_starts_with($name, 'safety-rollback-')))->toHaveCount(1);
})->with(['abort', 'return']);

it('keeps every pre-rollback safety backup until the operator deletes it, also through a later upgrade (R57)', function (): void {
    upgradedTarget($this->upgrade);
    $older = 'safety-rollback-20261001T100000Z';
    BackupSetFixture::fieldset($this->dir.'/backups/'.$older, ['kind' => 'safety-rollback', ...$this->upgrade->restore->manifest()], appKey: $this->upgrade->restore->appKey());

    [$code, $json] = $this->upgrade->rollback();
    $own = (string) $json['data']['safety_backup'];

    expect($code)->toBe(0, (string) json_encode($json))
        ->and(is_dir($this->dir.'/backups/'.$older))->toBeTrue()
        ->and(is_dir($this->dir.'/backups/'.$own))->toBeTrue();

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data']['removed_safety_backups'])->not->toContain($own)->not->toContain($older)
        ->and(is_dir($this->dir.'/backups/'.$own))->toBeTrue()
        ->and(is_dir($this->dir.'/backups/'.$older))->toBeTrue();
});
