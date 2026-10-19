<?php

declare(strict_types=1);

use App\Domain\Instance\Adopt\AdoptData;
use App\Domain\Instance\Adopt\LegacyInstall;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\Ledger;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\State\InstanceState;
use Tests\Support\Instance\AdoptFixture;

/*
 * `fsa adopt` keeps 1.0 stopped while it works on 1.0's data (plan U19, ADR 0087, review A1, A2, A7), against the recording
 * Docker gateway: a run killed in a phase and resumed by a fresh process continues only while 1.0 stayed stopped (else it
 * goes back, exit 5 `legacy_restarted`), every running service that is no infrastructure stops before the queue drains,
 * the baseline of "writes since adopt" is taken before 2.0 starts, and a resumed run appends the ledger line once.
 */

beforeEach(function (): void {
    $this->adopt = new AdoptFixture;
});

afterEach(function (): void {
    $this->adopt->cleanup();
});

/** @return list<string> the instance's backups whose names start with `$prefix` */
function fenceBackups(AdoptFixture $adopt, string $prefix = 'safety-adopt-'): array
{
    return array_values(array_map(basename(...), glob($adopt->instance->directory->root.'/backups/'.$prefix.'*') ?: []));
}

it('resumes a run killed in a data phase when 1.0 stayed stopped, without copying again once the copy finished (both volumes: two cp runs) (review A1)', function (string $phase, int $copies, int $dumps): void {
    $this->adopt->killAt($phase);
    $this->adopt->adopt();
    $this->adopt->restoreKill();
    $cp = count($this->adopt->specs('cp'));
    $dump = count($this->adopt->instance->docker->called('runContainerToFile'));

    [$code, $json] = $this->adopt->adopt();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and(count($this->adopt->specs('cp')) - $cp)->toBe($copies)
        ->and(count($this->adopt->instance->docker->called('runContainerToFile')) - $dump)->toBe($dumps)
        ->and($this->adopt->legacyCalls('startContainers'))->toBe([])
        ->and(fenceBackups($this->adopt))->toHaveCount(1)
        ->and(glob($this->adopt->instance->directory->root.'/backups/*.partial') ?: [])->toBe([]) // review A7
        ->and(app(OperationJournal::class)->current())->toBeNull();
})->with([
    'backup_1_0' => ['backup_1_0', 2, 2],
    'copy' => ['copy', 2, 0],
    'verify' => ['verify', 0, 0],
    'migrate' => ['migrate', 0, 0],
]);

it('goes back on a resumed run when 1.0 was started again after the run was killed (review A1)', function (string $phase): void {
    $this->adopt->killAt($phase);
    $this->adopt->adopt();
    $this->adopt->restoreKill();
    $this->adopt->restartLegacy();
    $copies = count($this->adopt->specs('cp'));
    $migrations = count($this->adopt->instance->oneOffCalls('migrate'));

    [$code, $json] = $this->adopt->adopt();
    $docker = $this->adopt->instance->docker;

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('legacy_restarted')
        ->and($json['errors'][0]['message'])->toContain('app was started again')->toContain('app runs')
        ->and(count($this->adopt->specs('cp')))->toBe($copies)
        ->and(count($this->adopt->instance->oneOffCalls('migrate')))->toBe($migrations)
        ->and(array_filter($docker->containers, static fn (ContainerInfo $c): bool => str_starts_with($c->id, 'legacy') && ! $c->running()))->toBe([])
        ->and($docker->containers([Labels::INSTANCE => null]))->toBe([])
        ->and($this->adopt->instance->directory->hasState())->toBeFalse()
        ->and(app(OperationJournal::class)->current())->toBeNull();
})->with(['backup_1_0', 'stop_postgres_1_0', 'copy', 'verify', 'migrate']);

it('goes back when 1.0 was started and stopped again, or its database files changed, while the run was dead (review A1)', function (Closure $meddle, string $problem): void {
    $this->adopt->killAt('copy');
    $this->adopt->adopt();
    $this->adopt->restoreKill();
    $meddle($this->adopt);

    [$code, $json] = $this->adopt->adopt();

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('legacy_restarted')
        ->and($json['errors'][0]['message'])->toContain($problem);
})->with([
    'started and stopped' => [function (AdoptFixture $a): void {
        $a->restartLegacy();
        foreach (AdoptFixture::SERVICES as $service) {
            $a->instance->docker->stopContainer($a->legacyId($service));
        }
    }, 'postgres was started again'],
    'recreated' => [function (AdoptFixture $a): void {
        $docker = $a->instance->docker;
        $old = $docker->containers[$a->legacyId('app')];
        unset($docker->containers[$old->id]);
        $docker->containers['legacyappnew'] = new ContainerInfo('legacyappnew', $old->name, 'exited', '2026-10-20T11:00:00Z', null, $old->image, $old->labels, $old->mounts);
    }, 'app is a new container'],
    'checkpoint moved' => [fn (AdoptFixture $a) => $a->checkpoint = '0/1F00000', 'its database files changed'],
]);

it('goes back when 1.0\'s Postgres is started while the copy runs (review A1)', function (): void {
    $docker = $this->adopt->instance->docker;
    $postgres = $this->adopt->legacyId('postgres');
    $docker->onCall['runContainer'] = function (array $args) use ($docker, $postgres): void {
        if ($args['spec']->entrypoint === 'cp') {
            $docker->restartContainer($postgres, '2026-10-20T09:00:00Z');
            $this->adopt->checkpoint = '0/2000000';
        }
    };

    [$code, $json] = $this->adopt->adopt();

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('legacy_restarted')
        ->and($json['errors'][0]['message'])->toContain('postgres was started again')
        ->and($this->adopt->instance->oneOffCalls('migrate'))->toBe([]);
});

it('stops every running 1.0 service that is no infrastructure before the queue drains, and starts it again on the way back (review A1)', function (): void {
    $docker = $this->adopt->instance->docker;
    $app = $docker->containers[$this->adopt->legacyId('app')];
    $docker->containers['legacyworker9999'] = new ContainerInfo('legacyworker9999', 'form-schema-app-worker-1', 'running', '2026-10-01T10:00:00Z', null, 'image',
        [...$app->labels, Labels::COMPOSE_SERVICE => 'worker'], $app->mounts);
    $this->adopt->failing['cp'] = new ProcessOutcome(1, '', 'no space');

    [$code, $json] = $this->adopt->adopt();

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($docker->called('stopContainers')[0]['ids'])->toBe([$this->adopt->legacyId('app'), $this->adopt->legacyId('scheduler'), 'legacyworker9999'])
        ->and($json['data']['rollback']['restarted'])->toContain('worker')
        ->and($docker->containers['legacyworker9999']->running())->toBeTrue();
});

it('takes the baseline of the writes since adopt before 2.0 starts, not after (review A2)', function (): void {
    $docker = $this->adopt->instance->docker;
    $start = $docker->onCall['composeUp'];
    $docker->onCall['composeUp'] = function (array $args) use ($start): void {
        $start($args);
        if ($args['services'] === []) {
            $this->adopt->uninstalling = true; // 2.0 serves from here on: a request writes rows
        }
    };
    $this->adopt->laterSnapshot = [...$this->adopt->snapshot, 'counts' => [...$this->adopt->snapshot['counts'], 'submissions' => 43]];

    [$code, $json] = $this->adopt->adopt();
    $entry = app(Ledger::class)->last('adopted');
    [$uninstall, $uninstallJson] = $this->adopt->instance->run('fsa:uninstall', ['--yes' => true]);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($entry['baseline']['counts']['submissions'])->toBe(41)
        ->and($uninstall)->toBe(0)
        ->and($uninstallJson['data']['adopted']['writes_since_adopt']['rows'])->toBe(['submissions' => 2]);
});

it('appends the ledger line and the records once when a run killed after the ledger append is resumed (review A7)', function (): void {
    $this->adopt->killAt('smoke', latest: true);
    $this->adopt->adopt();
    $ledger = (string) file_get_contents($this->adopt->instance->directory->root.'/'.Ledger::FILE);
    $this->adopt->restoreKill();
    file_put_contents($this->adopt->instance->directory->root.'/'.Ledger::FILE, $ledger); // the dead run had appended its line

    [$code, $json] = $this->adopt->adopt();
    $adopted = array_filter(app(Ledger::class)->entries(), static fn (array $entry): bool => ($entry['action'] ?? null) === 'adopted');
    $history = array_column($this->adopt->instance->state()->history(), 'event');

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($adopted)->toHaveCount(1)
        ->and(array_count_values($history))->toMatchArray(['installed' => 1, 'adopted' => 1])
        ->and($this->adopt->instance->state())->toBeInstanceOf(InstanceState::class);
});

it('removes the partial 1.0 backup a killed run left when it goes back (review A7)', function (): void {
    $this->adopt->killAt('backup_1_0');
    $this->adopt->adopt();
    $this->adopt->restoreKill();
    $partial = $this->adopt->instance->directory->root.'/backups/safety-adopt-20261019T000000Z.partial';
    mkdir($partial, 0700, true);
    $this->adopt->restartLegacy();

    [$code] = $this->adopt->adopt();

    expect($code)->toBe(5)->and(file_exists($partial))->toBeFalse()
        ->and(glob($this->adopt->instance->directory->root.'/backups/*.partial') ?: [])->toBe([]);
});

it('reads the fence\'s checkpoint from 1.0\'s volume, never from the copy (review A1)', function (string $moves, int $exit): void {
    $docker = $this->adopt->instance->docker;
    $docker->onCall['runContainer'] = function (array $args) use ($moves): void {
        if ($args['spec']->entrypoint === 'cp') {
            $this->adopt->{$moves} = '0/9000000';
        }
    };

    [$code, $json] = $this->adopt->adopt();

    expect($code)->toBe($exit, (string) json_encode($json))
        ->and($exit === 0 || $json['errors'][0]['code'] === 'legacy_restarted')->toBeTrue();
})->with([
    'the copy\'s checkpoint differs' => ['copyCheckpoint', 0],
    '1.0\'s checkpoint moves' => ['checkpoint', 5],
]);

it('checks the writers\' fence before it records Postgres\'s (review V6)', function (): void {
    $this->adopt->killAt('stop_postgres_1_0');
    $this->adopt->adopt();
    $this->adopt->restoreKill();
    $record = app(OperationJournal::class)->current();
    $legacy = LegacyInstall::fromArray($record->phaseData('doctor')['legacy']);
    $this->adopt->restartLegacy();

    expect(fn () => app(AdoptData::class)->stopData($this->adopt->instance->state(), $legacy, $record->phaseData('stop_1_0')['fence']))
        ->toThrow(fn (InstanceException $e) => expect($e->errorCode)->toBe('legacy_restarted'))
        ->and($this->adopt->instance->docker->containers[$this->adopt->legacyId('postgres')]->running())->toBeTrue();
});

it('leaves one 1.0-format backup when a run killed after the backup was complete is resumed (review A7)', function (): void {
    $this->adopt->killAt('backup_1_0', latest: true);
    $this->adopt->adopt();
    $set = fenceBackups($this->adopt)[0];
    $kept = sys_get_temp_dir().'/fsa-test-set-'.bin2hex(random_bytes(4));
    exec('cp -Rp '.escapeshellarg($this->adopt->instance->directory->root.'/backups/'.$set).' '.escapeshellarg($kept));
    $this->adopt->restoreKill();
    exec('cp -Rp '.escapeshellarg($kept).' '.escapeshellarg($this->adopt->instance->directory->root.'/backups/'.$set).' && rm -rf '.escapeshellarg($kept)); // complete, but not journaled

    [$code, $json] = $this->adopt->adopt();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and(fenceBackups($this->adopt))->toHaveCount(1);
});
