<?php

declare(strict_types=1);

use App\Domain\Instance\Adopt\LegacyRuntime;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Restore\LegacyEnv;
use App\Domain\Instance\State\EnvFiles;
use Tests\Support\Instance\AdoptFixture;

const ADOPT_OTHER_KEY = 'base64:'.'T1RIRVJPVEhFUk9USEVST1RIRVJPVEhFUk9USEVSMTI=';

/*
 * What `fsa adopt` checks of the running 1.0 install and what uninstall makes of an adopt (plan U19, ADR 0087, review S1,
 * S2, S3, A3, A4, A5, A6), against the recording Docker gateway: the `.env` against the app container's environment, the
 * Postgres image, Valkey's database, volume names from the containers' mounts, an adopt that failed after 2.0 started,
 * and the jobs 1.0 runs when it comes back.
 */

beforeEach(function (): void {
    $this->adopt = new AdoptFixture;
});

afterEach(function (): void {
    $this->adopt->cleanup();
});

it('refuses a .env that differs from what 1.0 runs with, naming the keys but no value (review S2)', function (array $env, array $running, string $key): void {
    $this->adopt->runningEnv = [...adoptRunningEnv($this->adopt), ...$running];
    file_put_contents($this->adopt->checkout.'/.env', AdoptFixture::env($env));

    [$code, $json] = $this->adopt->adopt();

    expect($code)->toBe(7, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('env_mismatch')
        ->and($json['errors'][0]['message'])->toContain($key)
        ->and(json_encode($json))->not->toContain(AdoptFixture::APP_KEY)->not->toContain(ADOPT_OTHER_KEY)
        ->and($this->adopt->instance->docker->called('stopContainers'))->toBe([]);
})->with([
    'a planted previous key' => [['APP_PREVIOUS_KEYS' => ADOPT_OTHER_KEY], [], 'APP_PREVIOUS_KEYS'],
    'another APP_KEY' => [[], ['APP_KEY' => ADOPT_OTHER_KEY], 'APP_KEY'],
    'another database' => [[], ['DB_DATABASE' => 'forms_prod'], 'DB_DATABASE'],
    'another port' => [['APP_PORT' => '9090'], [], 'APP_PORT'],
    'another Valkey database' => [['REDIS_DB' => '2'], [], 'REDIS_DB'], // review V5
]);

it('goes back when the APP keys in .env changed before a resumed run (review S2, V5)', function (array $env): void {
    $this->adopt->killAt('copy');
    $this->adopt->adopt();
    $this->adopt->restoreKill();
    file_put_contents($this->adopt->checkout.'/.env', AdoptFixture::env($env));

    [$code, $json] = $this->adopt->adopt();

    expect($code)->toBe(5, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe('env_changed')
        ->and(app(OperationJournal::class)->current())->toBeNull();
})->with([
    'another APP_KEY' => [['APP_KEY' => ADOPT_OTHER_KEY]],
    'a planted previous key' => [['APP_PREVIOUS_KEYS' => ADOPT_OTHER_KEY]],
]);

it('goes back when the checkout changed before a resumed run, instead of leaving 1.0 stopped (review V4)', function (): void {
    $this->adopt->killAt('copy');
    $this->adopt->adopt();
    $this->adopt->restoreKill();
    file_put_contents($this->adopt->checkout.'/.env', AdoptFixture::env(['COMPOSE_FILE' => 'compose.yaml:compose.override.yaml']));

    [$code, $json] = $this->adopt->adopt();
    $docker = $this->adopt->instance->docker;

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('compose_file_override')
        ->and(array_filter($docker->containers, static fn (ContainerInfo $c): bool => str_starts_with($c->id, 'legacy') && ! $c->running()))->toBe([])
        ->and(app(OperationJournal::class)->current())->toBeNull();
});

it('finds 1.0 when Compose started it through a symlink to the checkout (review nit)', function (): void {
    $link = sys_get_temp_dir().'/fsa-test-link-'.bin2hex(random_bytes(4));
    symlink($this->adopt->checkout, $link);
    $this->adopt->withLegacyStack(extraLabels: ['com.docker.compose.project.working_dir' => $link, 'com.docker.compose.project.config_files' => $link.'/compose.yaml']);

    [$code, $json] = $this->adopt->adopt();
    unlink($link);

    expect($code)->toBe(0, (string) json_encode($json));
});

it('refuses a 1.0 Postgres that is not major 16 on Alpine before 1.0 is touched (review A4)', function (Closure $arrange, string $found): void {
    $arrange($this->adopt);

    [$code, $json] = $this->adopt->adopt();

    expect($code)->toBe(7, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('postgres_image_mismatch')
        ->and($json['errors'][0]['message'])->toContain($found)
        ->and($json['errors'][0]['hint'])->toContain('`install.sh --dir <new directory> --from-backup <backup> --accept-unauthenticated`') // gate review DOC14
        ->and($this->adopt->instance->docker->called('stopContainers'))->toBe([]);
})->with([
    'Debian' => [fn (AdoptFixture $a) => $a->osRelease = "PRETTY_NAME=\"Debian GNU/Linux 12 (bookworm)\"\nID=debian\n", 'major 16 on debian'],
    'major 15' => [fn (AdoptFixture $a) => $a->pgMajor = '15', 'major 15 on alpine'],
]);

it('counts the jobs in the Valkey database 1.0 uses (review A5)', function (): void {
    file_put_contents($this->adopt->checkout.'/.env', AdoptFixture::env(['REDIS_DB' => '2']));

    [$code, $json] = $this->adopt->adopt();
    $valkey = $this->adopt->specs('valkey-cli');

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($valkey)->not->toBeEmpty()
        ->and(implode(' ', $valkey[0]->command))->toContain('-n 2');
});

it('copies the volumes 1.0\'s containers mount, whatever they are named (review S3)', function (): void {
    $docker = $this->adopt->instance->docker;
    $postgres = $docker->containers[$this->adopt->legacyId('postgres')];
    $docker->containers[$postgres->id] = new ContainerInfo($postgres->id, $postgres->name, 'running', $postgres->startedAt, null, $postgres->image, $postgres->labels,
        ['/var/lib/postgresql/data' => 'forms-database']);
    $docker->withResource(new DockerResource(DockerResource::VOLUME, 'forms-database', []));

    [$code, $json] = $this->adopt->adopt();
    $mounts = array_merge(...array_map(static fn ($spec): array => $spec->volumes, $this->adopt->specs('cp')));
    $hardening = array_map(static fn ($spec): array => [$spec->capabilities, $spec->readOnlyRoot], [...$this->adopt->specs('cp'), ...$this->adopt->specs('pg_controldata'), ...$this->adopt->specs('du')]);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($mounts)->toContain('forms-database:/from:ro')
        ->and(array_values(array_unique(array_map(static fn (array $h): string => (string) json_encode($h), $hardening))))->toEqualCanonicalizing([ // M12 gate S6
            json_encode([ContainerSpec::OWNER_COPY, true]), json_encode([ContainerSpec::READ_ANY, true]),
        ])
        ->and(implode(' ', $mounts))->not->toContain(AdoptFixture::PROJECT.'_pg-data');
});

it('treats an adopt that failed after 2.0 started as adopted: adopt hints, and uninstall keeps 1.0\'s way back (review A3, A6, S1)', function (): void {
    $this->adopt->queue = ["3\n1\n2\n"];
    $docker = $this->adopt->instance->docker;
    $start = $docker->onCall['composeUp'];
    $docker->onCall['composeUp'] = static function (array $args) use ($start): void {
        if ($args['services'] === []) {
            throw new DockerCommandFailed('compose up', new ProcessOutcome(1, '', 'app is unhealthy'));
        }
        $start($args);
    };

    [$failed, $failedJson] = $this->adopt->adopt(['--drop-jobs' => true]);
    [$prompt, $promptJson] = $this->adopt->instance->run('fsa:uninstall', []);
    $docker->onCall['composeUp'] = $start;
    $state = $this->adopt->instance->state();
    $docker->withResource(new DockerResource(DockerResource::VOLUME, $state->project().'_pg-data', [Labels::INSTANCE => $state->uuid(), 'com.docker.compose.volume' => 'pg-data'])); // Compose made it
    $appKey = new EnvFiles($this->adopt->instance->directory)->read('app')['APP_KEY'];
    [$code, $json] = $this->adopt->instance->run('fsa:uninstall', ['--yes' => true]);
    $keyFile = $this->adopt->instance->directory->root.'/'.$json['data']['final_backup']['key_file'];
    $restart = collect($json['warnings'])->firstWhere('code', 'restart_1_0');

    expect($failed)->toBe(4, (string) json_encode($failedJson))
        ->and($failedJson['errors'][0]['hint'])->toContain('fsa adopt '.$this->adopt->checkout)->toContain('fsa uninstall')->not->toContain('fsa install')
        ->and($prompt)->toBe(6)->and($promptJson['errors'][0]['message'])->toContain('Write a final backup')->toContain($this->adopt->checkout)
        ->and($code)->toBe(0, (string) json_encode($json))
        ->and($json['data']['adopted'])->toMatchArray(['checkout' => $this->adopt->checkout, 'pending' => true, 'jobs_in_1_0' => ['left' => 4, 'delayed' => 2, 'unknown' => false]])
        ->and($restart['message'] ?? '')->toContain('4 job(s) left by --drop-jobs and 2 delayed job(s)')
        ->and($json['data']['final_backup']['dir'])->toStartWith('backups/final-') // a 2.0 backup since the M12 gate
        ->and((string) file_get_contents($keyFile))->toContain($appKey) // review V2: env/app.env goes with the instance; its keys stay beside the backup
        ->and(glob($this->adopt->instance->directory->root.'/backups/safety-adopt-*') ?: [])->toHaveCount(1);
});

/**
 * The values of the checkout's `.env` that 1.0's app container is compared with.
 *
 * @return array<string, string>
 */
function adoptRunningEnv(AdoptFixture $adopt): array
{
    return LegacyEnv::read((string) file_get_contents($adopt->checkout.'/.env'), LegacyRuntime::COMPARED);
}
