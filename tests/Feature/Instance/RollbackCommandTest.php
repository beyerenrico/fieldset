<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Operations\Ledger;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\Upgrade\StageRenderer;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;
use Tests\Support\Instance\UpgradeFixture;
use Tests\Support\Release\ReleaseFixtures;

/*
 * `fsa rollback` (R37, R43, R57, R64, R68, plan U18, ADR 0086) after an upgrade alpha.1 → alpha.2, against the recording
 * Docker gateway: it asks first and names the loss, refuses without a previous release or after a restore since the
 * upgrade (exit 7), quiesces like an upgrade, records the ledger, exports the security state from the current release,
 * restores the pre-upgrade safety backup atomically, lets the **previous** image render its own files (render-only
 * child) and finish the restored database with the export, swaps, verifies, opens and cleans up. Before the swap a
 * failure goes back (exit 5); after it the upgraded release returns (exit 5); exit 8 when that fails.
 */

beforeEach(function (): void {
    $this->upgrade = new UpgradeFixture;
    $this->dir = $this->upgrade->instance->directory->root;
    $this->config = $this->upgrade->restore->config;
    [$code, $json] = $this->upgrade->upgrade();
    expect($code)->toBe(0, (string) json_encode($json));
    $this->inputs = [];
    $docker = $this->upgrade->instance->docker;
    $previous = $docker->composeRunResponder;
    $docker->respondToComposeRun(function (array $command, ?string $input = null) use ($previous): ?ProcessOutcome {
        $this->inputs[$command[2] ?? $command[0]] = $input;

        return $previous($command, $input);
    });
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
});

it('returns to the previous release from the upgrade\'s safety backup, carrying the security state through the export', function (): void {
    $mark = $this->config->mark();

    [$code, $json] = $this->upgrade->rollback();
    $state = $this->upgrade->instance->state();
    $id = substr($this->config->archivedJournal('rollback')->id(), 0, 12);
    $finish = $this->upgrade->instance->oneOffCalls('instance:restore-finish');
    $finishCommand = $finish[array_key_last($finish)]['command'];
    $render = $this->upgrade->childRequests[array_key_last($this->upgrade->childRequests)];
    $history = $state->history();
    $stops = $this->upgrade->stops($mark);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data'])->toMatchArray(['from' => UpgradeFixture::TARGET, 'to' => InstanceCommandFixture::VERSION, 'backup' => $this->upgrade->safetyBackups[0],
            'loss' => ['submissions' => 12, 'activity_log' => 340, 'users' => 0]])
        ->and($json['data']['safety_backup'])->toStartWith('safety-rollback-')
        ->and(array_map(static fn (array $p): string => $p['name'], $this->config->archivedJournal('rollback')->phases()))->toBe([
            'plan', 'prepare', 'pull', 'space', 'quiesce', 'ledger', 'export', 'safety_backup', 'restore_database', 'restore_storage', 'render', 'finish', 'erasures',
            'swap', 'verify', 'open', 'gate', 'cleanup',
        ])
        ->and($stops[0])->toBe('scheduler,backup')
        ->and($state->version())->toBe(InstanceCommandFixture::VERSION)
        ->and($state->digests()['app'])->toBe(InstanceCommandFixture::appImage())
        ->and($state->previous())->toBeNull()
        ->and(InstanceSettings::of($state)->storageVolume())->toBe($state->project().'_app-storage-r'.$id)
        ->and($this->upgrade->compose())->toContain(ReleaseFixtures::APP_DIGEST)->not->toContain(UpgradeFixture::TARGET_DIGEST)
        ->and($render)->toMatchArray(['version' => InstanceCommandFixture::VERSION, 'migrate' => false, 'settings' => ['storage_volume' => $state->project().'_app-storage-r'.$id]])
        ->and($finishCommand)->toContain('--reason=rolled_back', '--backup='.$this->upgrade->safetyBackups[0])->not->toContain('--no-carry')
        ->and(implode(' ', $finishCommand))->not->toContain('--source=')
        ->and(json_decode((string) $this->inputs['instance:restore-finish'], true)['carry']['format'])->toBe('fsa-security-state')
        ->and($this->upgrade->instance->oneOffCalls('instance:security-export')[0]['command'])->toContain('--json')
        ->and(new Ledger($this->upgrade->instance->directory)->last('rollback'))->toMatchArray(['reason' => 'manual', 'from' => UpgradeFixture::TARGET, 'to' => InstanceCommandFixture::VERSION])
        ->and($history[array_key_last($history)])->toMatchArray(['event' => 'rolled_back', 'from' => UpgradeFixture::TARGET, 'to' => InstanceCommandFixture::VERSION])
        ->and($this->upgrade->gate()['raised'])->toBeFalse()
        ->and(is_dir($this->dir.'/operations/upgrade-'.$id))->toBeFalse()
        ->and(is_file($this->dir.'/staging/restore-'.$id.'/security-state.json'))->toBeFalse()
        ->and(new OperationJournal($this->upgrade->instance->directory)->current())->toBeNull();
});

it('asks first and names what is lost (exit 6 without --yes)', function (): void {
    [$code, $json] = $this->upgrade->instance->run('fsa:rollback');

    expect($code)->toBe(6)
        ->and($json['errors'][0]['message'])->toContain(InstanceCommandFixture::VERSION)->toContain('12 submissions')->toContain('340 audit entries')
        ->and($this->upgrade->instance->state()->version())->toBe(UpgradeFixture::TARGET);
});

it('refuses without a previous release, after a restore since the upgrade, and without its safety backup (exit 7)', function (string $case, string $error): void {
    $directory = $this->upgrade->instance->directory;
    match ($case) {
        'no previous' => $directory->saveState($directory->state()->withoutPreviousRelease()),
        'restored' => $directory->saveState($directory->state()->withHistoryEntry('restored', ['backup' => 'manual-20261020T110000Z'])),
        default => exec('rm -rf '.escapeshellarg($this->dir.'/backups/'.$this->upgrade->safetyBackups[0])),
    };
    $mark = $this->config->mark();

    [$code, $json] = $this->upgrade->rollback();

    expect($code)->toBe(7, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe($error)
        ->and($this->config->callsSince($mark, 'composeStop'))->toBe([]);
})->with([['no previous', 'no_previous_release'], ['restored', 'restored_since_upgrade'], ['missing backup', 'safety_backup_missing']]);

it('goes back before the swap when restoring fails, with the upgraded release running as before (exit 5)', function (): void {
    $this->upgrade->restore->pgRestore = new ProcessOutcome(1, '', 'pg_restore: error: out of disk');
    $mark = $this->config->mark();

    [$code, $json] = $this->upgrade->rollback();
    $ups = $this->upgrade->ups($mark);

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('pg_restore_failed')
        ->and($this->upgrade->instance->state()->version())->toBe(UpgradeFixture::TARGET)
        ->and($this->upgrade->compose())->toContain(UpgradeFixture::TARGET_DIGEST)
        ->and($ups[array_key_last($ups)])->toBe('queue,scheduler,backup')
        ->and($this->upgrade->gate()['raised'])->toBeFalse()
        ->and(new OperationJournal($this->upgrade->instance->directory)->current())->toBeNull();
});

it('returns to the upgraded release when the rolled-back one fails its verification after the swap (exit 5)', function (): void {
    $this->config->smokeFails = fn (array $urls): bool => str_contains($this->upgrade->compose(), ReleaseFixtures::APP_DIGEST);

    [$code, $json] = $this->upgrade->rollback();
    $state = $this->upgrade->instance->state();

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($json['data']['returned'])->toBeTrue()
        ->and($state->version())->toBe(UpgradeFixture::TARGET)
        ->and($state->previous())->not->toBeNull()
        ->and($this->upgrade->compose())->toContain(UpgradeFixture::TARGET_DIGEST)
        ->and($this->upgrade->restore->databases)->toContain('form_schema')
        ->and(array_filter($this->upgrade->restore->databases, static fn (string $name): bool => str_contains($name, 'restore_')))->toBe([])
        ->and($this->upgrade->gate()['raised'])->toBeFalse();
});

it('stops everything and names the way on when going back fails too (exit 8), and continues it on the next run', function (): void {
    $this->config->smokeFails = static fn (array $urls): bool => true;

    [$code, $json] = $this->upgrade->rollback();

    expect($code)->toBe(8, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('rollback_return_failed')
        ->and($json['errors'][0]['hint'])->toContain('fsa rollback')
        ->and($this->upgrade->gate()['raised'])->toBeTrue()
        ->and(new OperationJournal($this->upgrade->instance->directory)->current()?->operation())->toBe('rollback');

    $this->config->smokeFails = null;
    [$code, $json] = $this->upgrade->rollback();

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($this->upgrade->instance->state()->version())->toBe(UpgradeFixture::TARGET)
        ->and($this->upgrade->gate()['raised'])->toBeFalse()
        ->and(new OperationJournal($this->upgrade->instance->directory)->current())->toBeNull();
});

it('lets the previous image only render, without a migration', function (): void {
    [$code] = $this->upgrade->rollback();
    $stage = $this->upgrade->instance->runSpecs('fsa');
    $render = array_values(array_filter($stage, static fn ($spec): bool => ($spec->command[0] ?? null) === 'upgrade-child'));

    expect($code)->toBe(0)
        ->and($render[array_key_last($render)]->image)->toBe(InstanceCommandFixture::appImage())
        ->and($render[array_key_last($render)]->env)->toHaveKey(StageRenderer::STAGE_ENV);
});
