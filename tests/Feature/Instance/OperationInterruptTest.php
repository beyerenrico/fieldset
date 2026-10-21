<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Operations\Interruption;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Restore\RestoreStack;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use Carbon\CarbonImmutable;
use Tests\Support\Instance\AdoptFixture;
use Tests\Support\Instance\BackupSetFixture;
use Tests\Support\Instance\InterruptedRestore;
use Tests\Support\Instance\RecordingDockerGateway;
use Tests\Support\Instance\RestoreFixture;
use Tests\Support\Instance\TemporaryInstance;
use Tests\Support\Instance\UpgradeFixture;

/*
 * Foreground operations under the interruption guard and what a resume notices (M12 gate O8, D7, C3, C5, C2): a signal
 * during restore, rollback or adopt is handled at the next phase boundary like a failure (the way back, exit 5) or, past
 * adopt's start, ends the run with the journal open; a resumed restore that finds writers running takes its heads and its
 * safety backup again; a safety backup a killed run wrote unnamed is sealed too; the quick tunnel never restarts with the
 * stack; a rollback leaves webhooks paused.
 */

afterEach(function (): void {
    TemporaryInstance::cleanup();
});

/** Delivers SIGTERM once, at the first `$method` call while `$phase` is the journal's last started phase (a disarmed guard records it). */
function signalDuring(RecordingDockerGateway $docker, InstanceDirectory $directory, string $phase, string $method): void
{
    $sent = false;
    $previous = $docker->onCall[$method] ?? null;
    $docker->onCall[$method] = static function (array $args) use ($directory, $phase, &$sent, $previous): void {
        if ($previous instanceof Closure) {
            $previous($args);
        }
        if (! $sent && new OperationJournal($directory)->current()?->lastStartedPhase() === $phase) {
            $sent = true;
            app(Interruption::class)->handle(15);
        }
    };
}

it('goes back at the next phase boundary when a signal reaches a restore before the swap (exit 5, journal closed, gate down)', function (): void {
    $restore = new RestoreFixture;
    $restore->backup();
    signalDuring($restore->instance->docker, $restore->instance->directory, 'safety_backup', 'composeRun');

    [$code, $json] = $restore->restore();
    $phases = array_column($restore->config->archivedJournal('restore')->phases(), 'name');

    expect($code)->toBe(5, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe('interrupted')
        ->and($phases)->toContain('safety_backup', 'abort')->not->toContain('restore_database')
        ->and($restore->actions())->not->toContain('swap')
        ->and(InterruptedRestore::gateRaised($restore->instance->directory->root))->toBeFalse()
        ->and(new OperationJournal($restore->instance->directory)->current())->toBeNull()
        ->and(app(Interruption::class)->pending())->toBeNull();
});

it('returns to the previous database when a signal reaches a restore between its swap and the gate (exit 5)', function (): void {
    $restore = new RestoreFixture;
    $restore->backup();
    signalDuring($restore->instance->docker, $restore->instance->directory, 'verify', 'composeUp');

    [$code, $json] = $restore->restore();

    expect($code)->toBe(5, (string) json_encode($json))->and($json['data']['returned_to_previous'] ?? null)->toBeTrue()
        ->and(array_column($restore->config->archivedJournal('restore')->phases(), 'name'))->toContain('return')->not->toContain('open')
        ->and(InstanceSettings::of($restore->config->state())->storageVolume())->toBeNull();
});

it('goes back when a signal reaches a rollback before its swap (exit 9 like any reverted rollback, the upgraded release running)', function (): void {
    $upgrade = new UpgradeFixture;
    $upgrade->upgrade();
    signalDuring($upgrade->instance->docker, $upgrade->instance->directory, 'export', 'composeRun');

    [$code, $json] = $upgrade->rollback();

    expect($code)->toBe(9, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe('interrupted')
        ->and(array_column($upgrade->restore->config->archivedJournal('rollback')->phases(), 'name'))->toContain('export', 'abort')->not->toContain('safety_backup')
        ->and(new OperationJournal($upgrade->instance->directory)->current())->toBeNull()
        ->and($upgrade->instance->state()->version())->toBe(UpgradeFixture::TARGET);
});

it('goes back to 1.0 when a signal reaches an adopt before 2.0 starts, and keeps the journal open after it started', function (): void {
    $adopt = new AdoptFixture;
    signalDuring($adopt->instance->docker, $adopt->instance->directory, 'backup_1_0', 'runContainer');

    [$before, $beforeJson] = $adopt->adopt();
    $adopt->cleanup();

    $adopt = new AdoptFixture;
    signalDuring($adopt->instance->docker, $adopt->instance->directory, 'start', 'composeUp');
    [$after, $afterJson] = $adopt->adopt();
    $open = new OperationJournal($adopt->instance->directory)->current();
    [$resumed, $resumedJson] = $adopt->adopt();
    $adopt->cleanup();

    expect($before)->toBe(5, (string) json_encode($beforeJson))->and($beforeJson['errors'][0]['code'])->toBe('interrupted')
        ->and($after)->toBe(9, (string) json_encode($afterJson))->and($afterJson['errors'][0]['code'])->toBe('interrupted')
        ->and($afterJson['changed'])->toBeTrue()
        ->and($afterJson['errors'][0]['hint'])->toContain(' --yes` again: it resumes here')
        ->and($open?->lastCompletedPhase())->toBe('start')
        ->and($resumed)->toBe(0, (string) json_encode($resumedJson));
});

it('takes the heads and the safety backup again when writers run before a resumed restore (D7)', function (): void {
    $restore = new RestoreFixture;
    $restore->backup();
    InterruptedRestore::journal($restore, 'safety_backup');
    foreach ($restore->instance->docker->containers as $id => $container) { // something started the writers after the kill
        if (in_array($container->service(), ['app', 'scheduler'], true)) {
            $restore->instance->docker->containers[$id] = new ContainerInfo($container->id, $container->name, 'running', $container->startedAt, $container->health, $container->image, $container->labels);
        }
    }

    [$code, $json] = $restore->restore();
    $record = $restore->config->archivedJournal('restore');
    $counts = array_count_values(array_column($record->phases(), 'name'));
    $quiesces = array_values(array_filter($record->phases(), static fn (array $phase): bool => $phase['name'] === 'quiesce'));
    $daemon = array_filter($restore->instance->docker->called('composeRun'), static fn (array $call): bool => ($call['command'][1] ?? null) === 'backup-daemon');

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($counts)->toMatchArray(['quiesce' => 2, 'ledger' => 2, 'safety_backup' => 2])
        ->and($quiesces[array_key_last($quiesces)]['data']['writers_ran'] ?? null)->toBeTrue()
        ->and($daemon)->not->toBe([]);
});

it('seals a restore safety backup a killed run wrote before its journal named it (C3)', function (): void {
    $upgrade = new UpgradeFixture;
    $restore = $upgrade->restore;
    $restore->backup();
    $upgrade->encrypt();
    InterruptedRestore::journal($restore, 'ledger', started: 'safety_backup');
    $orphan = 'safety-restore-'.CarbonImmutable::now('UTC')->addSecond()->format('Ymd\THis\Z');
    BackupSetFixture::fieldset($restore->instance->directory->root.'/backups/'.$orphan, ['kind' => 'safety-restore', ...$restore->manifest()], appKey: $restore->appKey());

    [$code, $json] = $restore->restore();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($upgrade->sealed())->toContain($orphan, (string) $json['data']['safety_backup']);
});

it('starts every service but the quick tunnel when the stack comes back, and passes all of them without a tunnel (C5)', function (): void {
    $restore = new RestoreFixture;
    $directory = $restore->instance->directory;
    app()->instance(InstanceDirectory::class, $directory);
    $project = ComposeProject::forInstance($restore->config->state(), $directory);
    $stack = app(RestoreStack::class);
    $mark = $restore->config->mark();
    $stack->startAll($project);
    $lines = explode("\n", (string) $directory->read('compose.yaml'));
    $document = json_decode(implode("\n", array_filter($lines, static fn (string $line): bool => ! str_starts_with($line, '#'))), true);
    $document['services']['tunnel'] = $document['services']['gotenberg'];
    $directory->write('compose.yaml', json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    $stack->startAll($project);
    $stack->reopenPrevious($restore->config->state(), $project);
    $ups = array_column($restore->config->callsSince($mark, 'composeUp'), 'services');

    expect($ups[0])->toBe([])
        ->and($ups[1])->toContain('app', 'queue', 'scheduler', 'backup')->not->toContain('tunnel')
        ->and($ups[2])->toBe(['app'])
        ->and($ups[3])->toBe($ups[1]);
});

it('leaves webhooks paused after a rollback and says so (C2)', function (): void {
    $upgrade = new UpgradeFixture;
    $upgrade->upgrade();

    [$code, $json] = $upgrade->rollback();
    $runtime = json_decode((string) $upgrade->instance->directory->read('runtime/runtime.json'), true);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data']['webhooks'])->toBe('paused')
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('webhooks_paused')
        ->and(InstanceSettings::of($upgrade->instance->state())->webhooksPaused())->toBeTrue()
        ->and($runtime['webhooks'])->toBe(['paused' => true]);
});
