<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Release\ManifestVerifier;
use Tests\Support\Instance\AdoptFixture;
use Tests\Support\Instance\InstanceCommandFixture;

/*
 * `fsa adopt` when something goes wrong (plan U19, ADR 0087), against the recording Docker gateway: the way back of a
 * failure before 2.0 starts (1.0 runs again, nothing of 2.0 is left, exit 5; exit 9 before 1.0 was touched; exit 8 when
 * 1.0 does not start again), the drain of 1.0's queue, a resume after 2.0 started, and `--abandon`.
 */

beforeEach(function (): void {
    $this->adopt = new AdoptFixture;
});

afterEach(function (): void {
    $this->adopt->cleanup();
});

/** @return list<string> the 1.0-format backups adopt wrote */
function adoptSafetyBackups(AdoptFixture $adopt): array
{
    return array_values(array_map(basename(...), glob($adopt->instance->directory->root.'/backups/safety-adopt-*') ?: []));
}

it('goes back when a step fails before 2.0 starts: 1.0 runs again and nothing of 2.0 is left (exit 5)', function (Closure $arrange, string $error): void {
    $arrange($this->adopt);

    [$code, $json] = $this->adopt->adopt();
    $started = $this->adopt->legacyCalls('startContainers');
    $docker = $this->adopt->instance->docker;
    $removedVolumes = array_column($docker->called('removeVolume'), 'name');

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($json['status'])->toBe('rolled_back')
        ->and($json['errors'][0]['code'])->toBe($error)
        ->and($json['errors'][0]['message'])->toContain('1.0 runs again')
        ->and($started)->toBe(array_map($this->adopt->legacyId(...), ['postgres', 'redis', 'gotenberg', 'app', 'scheduler', 'queue']))
        ->and(array_filter($docker->containers, static fn ($c): bool => str_starts_with($c->id, 'legacy') && ! $c->running()))->toBe([])
        ->and($docker->containers([Labels::INSTANCE => null]))->toBe([])
        ->and($removedVolumes)->not->toContain(AdoptFixture::PROJECT.'_pg-data')->not->toContain(AdoptFixture::PROJECT.'_app-storage')
        ->and($this->adopt->instance->directory->hasState())->toBeFalse()
        ->and($this->adopt->instance->directory->exists('compose.yaml'))->toBeFalse()
        ->and($this->adopt->instance->directory->exists('env'))->toBeFalse()
        ->and(app(OperationJournal::class)->current())->toBeNull()
        ->and($json['data']['rollback']['restarted'])->toBe(['postgres', 'redis', 'gotenberg', 'app', 'scheduler', 'queue']);
})->with([
    'the copy fails' => [fn (AdoptFixture $a) => $a->failing['cp'] = new ProcessOutcome(1, '', 'cp: write error: No space left on device'), 'copy_failed'],
    'the copy differs from the backup' => [fn (AdoptFixture $a) => $a->copySnapshot = [...$a->snapshot, 'counts' => [...$a->snapshot['counts'], 'submissions' => 40]], 'copy_mismatch'],
    'amcheck fails' => [fn (AdoptFixture $a) => $a->amcheck = ['fsa-amcheck ok organizations_slug_unique', 'fsa-amcheck failed users_email_unique item order invariant violated'], 'amcheck_failed'],
    'the cluster was not shut down cleanly' => [fn (AdoptFixture $a) => $a->clusterState = 'in production', 'postgres_not_shut_down'],
    'the dump fails' => [fn (AdoptFixture $a) => $a->failing['file:pg_dump'] = new ProcessOutcome(1, '', 'pg_dump: error: connection failed'), 'legacy_backup_failed'],
    'the migrations fail' => [fn (AdoptFixture $a) => $a->instance->oneOffs['migrate'] = new ProcessOutcome(1, 'Migrating', 'SQLSTATE[42P07]'), 'migrate_failed'],
]);

it('keeps the 1.0-format backup of a failed adopt and leaves no partial one', function (): void {
    $this->adopt->failing['cp'] = new ProcessOutcome(1, '', 'no space');
    [$failed] = $this->adopt->adopt();
    $this->adopt->failing = ['file:tar' => new ProcessOutcome(2, '', 'tar: write error')];
    [$failedEarly, $json] = $this->adopt->adopt();

    expect($failed)->toBe(5)->and($failedEarly)->toBe(5)->and($json['errors'][0]['code'])->toBe('legacy_backup_failed')
        ->and(adoptSafetyBackups($this->adopt))->toHaveCount(1)
        ->and(glob($this->adopt->instance->directory->root.'/backups/*.partial') ?: [])->toBe([]);
});

it('aborts with nothing changed when a step fails before 1.0 is stopped (exit 9)', function (): void {
    $docker = $this->adopt->instance->docker;
    $docker->images = array_intersect_key($docker->images, array_flip([ManifestVerifier::COSIGN_IMAGE, InstanceCommandFixture::appImage()]));
    $docker->failOn('pullImage', new DockerCommandFailed('pull', new ProcessOutcome(1, '', 'network unreachable')));

    [$code, $json] = $this->adopt->adopt();

    expect($code)->toBe(9, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('image_unavailable')
        ->and($docker->called('stopContainers'))->toBe([])
        ->and($this->adopt->instance->directory->hasState())->toBeFalse()
        ->and(app(OperationJournal::class)->current())->toBeNull();
});

it('drains 1.0\'s queue, refuses to go on with jobs left unless --drop-jobs, and reports delayed jobs', function (): void {
    $this->adopt->queue = ["4\n1\n0\n", "2\n0\n0\n", "0\n0\n2\n"];
    [$drained, $drainedJson] = $this->adopt->adopt();

    expect($drained)->toBe(0, (string) json_encode($drainedJson))
        ->and($drainedJson['data']['adopted']['drain'])->toMatchArray(['drained' => true, 'waiting' => 0, 'reserved' => 0, 'delayed' => 2, 'waited_seconds' => 6])
        ->and(collect($drainedJson['warnings'])->pluck('code')->all())->toContain('delayed_jobs_left_in_1_0');
});

it('goes back when the queue does not drain, and goes on with --drop-jobs', function (): void {
    $this->adopt->queue = ["3\n0\n0\n"];
    [$refused, $refusedJson] = $this->adopt->adopt();
    [$dropped, $droppedJson] = $this->adopt->adopt(['--drop-jobs' => true]);

    expect($refused)->toBe(5)->and($refusedJson['errors'][0]['code'])->toBe('drain_timeout')
        ->and($refusedJson['data']['drain'])->toMatchArray(['drained' => false, 'waiting' => 3])
        ->and($dropped)->toBe(0, (string) json_encode($droppedJson))
        ->and(collect($droppedJson['warnings'])->pluck('code')->all())->toContain('jobs_left_in_1_0');
});

it('goes back when Valkey cannot be asked (the queue may not have drained), and goes on with --drop-jobs', function (): void {
    $this->adopt->failing['valkey-cli'] = new ProcessOutcome(0, "NOAUTH Authentication required.\n");

    [$refused, $refusedJson] = $this->adopt->adopt();
    [$dropped, $droppedJson] = $this->adopt->adopt(['--drop-jobs' => true]);

    expect($refused)->toBe(5)->and($refusedJson['errors'][0]['code'])->toBe('drain_unknown')
        ->and($refusedJson['errors'][0]['hint'])->toContain('--drop-jobs')
        ->and($dropped)->toBe(0, (string) json_encode($droppedJson))
        ->and(collect($droppedJson['warnings'])->pluck('code')->all())->toContain('drain_unknown');
});

it('removes the new instance\'s containers before it starts 1.0 again, and names 1.0 services that do not run', function (): void {
    $this->adopt->copySnapshot = [...$this->adopt->snapshot, 'migrations' => 119];
    $docker = $this->adopt->instance->docker;
    $app = $this->adopt->legacyId('app');
    $docker->onCall['inspectContainer'] = static function (array $args) use ($docker, $app): void {
        if ($args['id'] === $app) {
            $docker->stopContainer($app); // exits right after its start
        }
    };

    [$code, $json] = $this->adopt->adopt();
    $methods = $docker->methods();

    expect($code)->toBe(5)->and($json['errors'][0]['code'])->toBe('copy_mismatch')
        ->and($json['errors'][0]['message'])->toContain('1.0 was started again')
        ->and($json['data']['rollback']['not_running'])->toBe(['app'])
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('legacy_not_running')
        ->and(array_filter($json['data']['rollback']['removed'], static fn (string $what): bool => str_starts_with($what, 'container fsa-') && str_ends_with($what, '-postgres-1')))->toHaveCount(1)
        ->and(array_search('removeContainer', $methods, true))->toBeLessThan(array_search('startContainers', $methods, true));
});

it('resumes forward after 2.0 started, and refuses --abandon then (it would delete the new instance\'s data)', function (): void {
    $docker = $this->adopt->instance->docker;
    $start = $docker->onCall['composeUp'];
    $docker->onCall['composeUp'] = static function (array $args) use ($start): void {
        if ($args['services'] === []) {
            throw new DockerCommandFailed('compose up', new ProcessOutcome(1, '', 'app is unhealthy'));
        }
        $start($args);
    };
    [$failed, $failedJson] = $this->adopt->adopt();
    [$abandon, $abandonJson] = $this->adopt->instance->run('fsa:adopt', ['--abandon' => true, '--yes' => true]);
    $docker->onCall['composeUp'] = $start;
    $copies = count($this->adopt->specs('cp'));
    [$resumed, $resumedJson] = $this->adopt->adopt();

    expect($failed)->toBe(4, (string) json_encode($failedJson))->and($failedJson['errors'][0]['code'])->toBe('services_unhealthy')
        ->and($abandon)->toBe(2)->and($abandonJson['errors'][0]['code'])->toBe('adopt_started')
        ->and($resumed)->toBe(0, (string) json_encode($resumedJson))
        ->and(count($this->adopt->specs('cp')))->toBe($copies)
        ->and($this->adopt->legacyCalls('startContainers'))->toBe([])
        ->and(app(OperationJournal::class)->current())->toBeNull();
});

it('finishes going back after a run that could not start 1.0 again (exit 8), and --abandon needs an unfinished adopt', function (): void {
    [$nothing, $nothingJson] = $this->adopt->instance->run('fsa:adopt', ['--abandon' => true, '--yes' => true]);
    $this->adopt->failing['cp'] = new ProcessOutcome(1, '', 'no space');
    $this->adopt->instance->docker->failOn('startContainers', new DockerCommandFailed('container start', new ProcessOutcome(1, '', 'Error response from daemon')));
    [$failed, $failedJson] = $this->adopt->adopt();
    $open = app(OperationJournal::class)->current();
    unset($this->adopt->instance->docker->failures['startContainers']);
    [$resumed, $resumedJson] = $this->adopt->adopt();

    expect($nothing)->toBe(2)->and($nothingJson['errors'][0]['code'])->toBe('nothing_to_abandon')
        ->and($failed)->toBe(8)->and($failedJson['errors'][0]['code'])->toBe('rollback_failed')
        ->and($failedJson['errors'][0]['hint'])->toContain('docker compose up -d')
        ->and($open?->operation())->toBe('adopt')
        ->and($resumed)->toBe(5, (string) json_encode($resumedJson))->and($resumedJson['errors'][0]['code'])->toBe('adopt_interrupted')
        ->and($this->adopt->instance->docker->called('runContainer'))->not->toBeEmpty()
        ->and(app(OperationJournal::class)->current())->toBeNull()
        ->and($this->adopt->instance->directory->hasState())->toBeFalse();
});

it('abandons an adopt that stopped half-way', function (): void {
    $this->adopt->failing['cp'] = new ProcessOutcome(1, '', 'no space');
    $this->adopt->instance->docker->failOn('startContainers', new DockerCommandFailed('container start', new ProcessOutcome(1, '', 'Error response from daemon')));
    $this->adopt->adopt();
    unset($this->adopt->instance->docker->failures['startContainers']);

    [$code, $json] = $this->adopt->instance->run('fsa:adopt', ['--abandon' => true, '--yes' => true]);

    expect($code)->toBe(0, (string) json_encode($json))->and($json['data'])->toMatchArray(['abandoned' => true, 'checkout' => $this->adopt->checkout])
        ->and(app(OperationJournal::class)->current())->toBeNull();
});
