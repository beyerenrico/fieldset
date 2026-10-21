<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\CommandRunner;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\Upgrade\ChildMigration;
use App\Domain\Instance\Upgrade\StageRenderer;
use App\Domain\Instance\Upgrade\UpgradeCompose;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\ScriptedCommandRunner;
use Tests\Support\Instance\TemporaryInstance;
use Tests\Support\Instance\UpgradeFixture;

/*
 * `fsa upgrade-child`, the target image's half of an upgrade (KTD19 step 6, R36, plan U18, ADR 0086): with the instance
 * directory only to read, it renders the target's compose and env files into its stage with its own renderer, then runs
 * `instance:upgrade-migrate` with the staged app environment and libpq's session name and lock_timeout, and leaves its
 * outcome in `result.json` for the supervisor.
 */

beforeEach(function (): void {
    $this->config = new ConfigFixture;
    $this->directory = $this->config->instance->directory;
    $this->stage = $this->directory->ensureDirectory('operations/upgrade-0123456789ab');
    file_put_contents($this->stage.'/'.StageRenderer::REQUEST, json_encode([
        'journal' => '0123456789ab', 'version' => UpgradeFixture::TARGET, 'from' => InstanceCommandFixture::VERSION,
        'digests' => [...$this->config->state()->digests(), 'app' => UpgradeFixture::targetImage()], 'emulated' => false,
    ]));
    putenv(StageRenderer::STAGE_ENV.'='.$this->stage);
    $this->runner = new ScriptedCommandRunner;
    app()->instance(CommandRunner::class, $this->runner);
});

afterEach(function (): void {
    putenv(StageRenderer::STAGE_ENV);
    TemporaryInstance::cleanup();
});

/** @return array{0: int, 1: array<string, mixed>|null} */
function upgradeChild(string $dir): array
{
    $code = Artisan::call('fsa:upgrade-child', ['--dir' => $dir, '--json' => true]);
    $lines = array_values(array_filter(explode("\n", trim(Artisan::output()))));

    return [$code, json_decode((string) end($lines), true)];
}

it('renders the target\'s files into its stage and migrates with the staged environment, alone and with lock_timeout', function (): void {
    $this->runner->push(InstanceCommandFixture::oneOffJson(['command' => 'instance:upgrade-migrate', 'status' => 'ok', 'changed' => true,
        'data' => ['migrated' => ['2099_01_01_000000_x'], 'steps' => [], 'invalid_indexes' => [], 'lock_timeout' => '1min']]));

    [$code, $json] = upgradeChild($this->directory->root);
    $result = json_decode((string) file_get_contents($this->stage.'/'.StageRenderer::RESULT), true);
    $staged = InstanceDirectory::open($this->stage.'/'.StageRenderer::FILES);
    $run = $this->runner->runs[0];

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($result)->toMatchArray(['status' => 'ok', 'migrated' => ['2099_01_01_000000_x']])
        ->and($result['staged'])->toContain('compose.yaml', 'env/app.env', 'env/backup.env')
        ->and(UpgradeCompose::document((string) $staged->read('compose.yaml'))['services']['app']['image'])->toBe(UpgradeFixture::targetImage())
        ->and(new EnvFiles($staged)->read('app')['APP_KEY'])->toBe(new EnvFiles($this->directory)->read('app')['APP_KEY'])
        ->and($run['command'])->toBe(['php', base_path('artisan'), 'instance:upgrade-migrate', '--application-name=fieldset_upgrade_0123456789ab', '--from='.InstanceCommandFixture::VERSION, '--json'])
        ->and($run['env'])->toMatchArray(['PGAPPNAME' => 'fieldset_upgrade_0123456789ab', 'PGOPTIONS' => '-c lock_timeout='.ChildMigration::LOCK_TIMEOUT_SECONDS.'s'])
        ->and(array_keys($run['env']))->toContain('DB_PASSWORD', 'DB_HOST')
        ->and($run['timeout'])->toBe(ChildMigration::TIMEOUT)
        ->and($this->config->state()->version())->toBe(InstanceCommandFixture::VERSION);
});

it('leaves a failed result when the migration fails, with what it staged', function (): void {
    $this->runner->push(InstanceCommandFixture::oneOffJson(['command' => 'instance:upgrade-migrate', 'status' => 'failed',
        'errors' => [['code' => 'foreign_connections', 'message' => '1 other session(s) use the database: psql.', 'hint' => null]]]));

    [$code, $json] = upgradeChild($this->directory->root);
    $result = json_decode((string) file_get_contents($this->stage.'/'.StageRenderer::RESULT), true);

    expect($code)->toBe(1)->and($json['errors'][0]['code'])->toBe('foreign_connections')
        ->and($result['status'])->toBe('failed')->and($result['error']['code'])->toBe('foreign_connections')
        ->and($result['staged'])->toContain('compose.yaml');
});

it('reports a migration that times out', function (): void {
    $this->runner->push(new ProcessOutcome(124, '', '', true));

    upgradeChild($this->directory->root);
    $result = json_decode((string) file_get_contents($this->stage.'/'.StageRenderer::RESULT), true);

    expect($result['status'])->toBe('failed')->and($result['error']['code'])->toBe('one_off_timeout');
});

it('refuses a stage outside the instance\'s operations and a stage without a request', function (string $case, string $error): void {
    if ($case === 'outside') {
        putenv(StageRenderer::STAGE_ENV.'='.sys_get_temp_dir());
    } else {
        unlink($this->stage.'/'.StageRenderer::REQUEST);
    }

    [$code, $json] = upgradeChild($this->directory->root);

    expect($code)->toBe(1)->and($json['errors'][0]['code'])->toBe($error)->and($this->runner->runs)->toBe([]);
})->with([['outside', 'unsafe_path'], ['no request', 'stage_invalid']]);
