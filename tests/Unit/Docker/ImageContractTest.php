<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\Support\Docker\DockerfileStages;
use Tests\Support\Docker\DockerIgnore;
use Tests\Support\Docker\ScriptHarness;
use Tests\Support\Instance\FsaCommandFlags;

/*
 * U1 / M9-T02: the app image contract as written in docker/app (R1, R5, R6, E17, KTD8, KTD9, ADR 0065).
 * tests/Install/Journeys/ImageContractJourneyTest.php checks the same contract on the built image.
 */

dataset('build context', [
    'spec meta-schema' => ['spec/form-definition/1.0/meta-schema.json', true],
    'spec roles' => ['spec/form-definition/1.0/roles.json', true],
    'templates' => ['spec/templates/index.json', true],
    'app code' => ['app/Providers/AppServiceProvider.php', true],
    'artisan' => ['artisan', true],
    'composer lock' => ['composer.lock', true],
    'php package source' => ['packages/fieldset-php/src/Support/SpecPaths.php', true],
    'js package source' => ['packages/fieldset-js/src/index.ts', true],
    'storage skeleton' => ['storage/framework/views/.gitignore', true],
    'bootstrap cache skeleton' => ['bootstrap/cache/.gitignore', true],
    'image scripts' => ['docker/app/entrypoint.sh', true],
    'manifest schema' => ['distribution/manifest.schema.json', true],
    'fsa shim' => ['distribution/shim.sh', true],
    'install script' => ['distribution/install.sh', false],
    'release inputs' => ['distribution/release.json', false],
    'trivy exceptions' => ['distribution/trivy/postgres.trivyignore.yaml', false],
    'build flavor file' => ['distribution/.build-flavor', false],
    'tests' => ['tests/Unit/EnvTemplatesTest.php', false],
    'agent docs' => ['docs/agent/STATE.md', false],
    'guides' => ['docs/guide/install.md', false],
    'scripts' => ['scripts/backup.sh', false],
    'ci config' => ['.github/workflows/ci.yml', false],
    'agent instructions' => ['AGENTS.md', false],
    'phpunit config' => ['phpunit.xml', false],
    'env file' => ['.env', false],
    'git' => ['.git/HEAD', false],
    'host vendor' => ['vendor/autoload.php', false],
    'node modules' => ['node_modules/vite/package.json', false],
    'workspace node modules' => ['packages/fieldset-js/node_modules/x/index.js', false],
    'php package tests' => ['packages/fieldset-php/tests/Pest.php', false],
    'js package tests' => ['packages/fieldset-js/test/evaluate.test.ts', false],
    'workspace tests' => ['resources/field-app/test/setup.ts', false],
    'spec conformance' => ['spec/conformance/README.md', false],
    'storage logs' => ['storage/logs/laravel.log', false],
    'playwright browsers' => ['storage/playwright/chromium-1/chrome', false],
    'cached manifests' => ['bootstrap/cache/packages.php', false],
    'host-built assets' => ['public/build/manifest.json', false],
    'other docker images' => ['docker/ci/Dockerfile', false],
]);

it('sends only what the image stages read to the builder (allow-list)', function (string $path, bool $included): void {
    expect(DockerIgnore::fromFile(base_path('.dockerignore'))->includes($path))->toBe($included);
})->with('build context');

it('never copies the whole context or ships composer in the final stage', function (): void {
    $dockerfile = DockerfileStages::fromFile(base_path('docker/app/Dockerfile'));
    $final = $dockerfile->finalStage();

    expect($dockerfile->text)->not->toMatch('/^COPY\s+\.\s+\./m')
        ->and($dockerfile->finalStageName())->toBe('app')
        ->and($final)->not->toContain('composer')
        ->and($final)->toMatch('/^USER\s+www-data$/m')
        ->and($final)->toContain('/usr/local/bin/fsa')
        ->and($final)->toContain('ENTRYPOINT ["app-entrypoint"]');
});

it('ships the manifest schema and the shim, and records a production build flavor unless the harness asks (ADR 0070)', function (): void {
    $dockerfile = DockerfileStages::fromFile(base_path('docker/app/Dockerfile'));

    expect($dockerfile->stage('build'))->toContain('COPY distribution/manifest.schema.json distribution/shim.sh ./distribution/')
        ->and($dockerfile->finalStage())->toMatch('/^ARG FSA_BUILD=production$/m')
        ->and($dockerfile->finalStage())->toContain('production | harness')
        ->and($dockerfile->finalStage())->toContain('> /var/www/html/distribution/.build-flavor')
        ->and((string) file_get_contents(base_path('.github/workflows/release.yml')))->not->toContain('FSA_BUILD');
});

it('puts the XDG config and data homes on writable paths (R6)', function (): void {
    $runtime = DockerfileStages::fromFile(base_path('docker/app/Dockerfile'))->stage('runtime');

    expect($runtime)->toMatch('#XDG_CONFIG_HOME=/tmp/\S+#')
        ->and($runtime)->toMatch('#XDG_DATA_HOME=/tmp/\S+#')
        ->and((string) file_get_contents(base_path('docker/app/Caddyfile')))->toContain('persist_config off');
});

it('labels the image with version, revision, source and license (R1, KTD9)', function (): void {
    $final = DockerfileStages::fromFile(base_path('docker/app/Dockerfile'))->finalStage();
    $license = json_decode((string) file_get_contents(base_path('composer.json')), true)['license'];

    expect($final)->toMatch('/^ARG APP_VERSION=\S+/m')
        ->and($final)->toMatch('/^ARG APP_REVISION/m')
        ->and($final)->toContain('org.opencontainers.image.version="${APP_VERSION}"')
        ->and($final)->toContain('org.opencontainers.image.revision="${APP_REVISION}"')
        ->and($final)->toContain('org.opencontainers.image.source="https://github.com/beyerenrico/form-schema-app"')
        ->and($final)->toContain(sprintf('org.opencontainers.image.licenses="%s"', $license))
        ->and($final)->toContain('APP_VERSION="${APP_VERSION}"');
});

it('reports the image version as config app.version', function (): void {
    $previous = getenv('APP_VERSION');
    $read = function (?string $value): mixed {
        putenv($value === null ? 'APP_VERSION' : 'APP_VERSION='.$value);
        unset($_SERVER['APP_VERSION'], $_ENV['APP_VERSION']);

        return (require base_path('config/app.php'))['version'];
    };

    try {
        expect($read('2.0.0-alpha.1'))->toBe('2.0.0-alpha.1')
            ->and($read(null))->toBe('0.0.0-dev');
    } finally {
        putenv($previous === false ? 'APP_VERSION' : 'APP_VERSION='.$previous);
    }
});

it('pins the Docker CLI release binary per architecture by SHA-256 (KTD8)', function (string $arch, string $token): void {
    $stage = DockerfileStages::fromFile(base_path('docker/app/Dockerfile'))->stage('downloads-'.$arch);

    preg_match_all('/^ADD --checksum=sha256:([0-9a-f]{64}) (\S+)/m', $stage, $adds, PREG_SET_ORDER);

    expect($adds)->toHaveCount(1)
        ->and($adds[0][2])->toMatch('#^https://download\.docker\.com/linux/static/stable/'.$token.'/docker-29\.9\.\d+\.tgz$#');
})->with([
    'amd64' => ['amd64', 'x86_64'],
    'arm64' => ['arm64', 'aarch64'],
]);

it('cross-compiles Compose and age from verified release tags with a pinned Go toolchain (ADR 0071)', function (): void {
    $dockerfile = DockerfileStages::fromFile(base_path('docker/app/Dockerfile'));

    expect($dockerfile->text)->toMatch('#^FROM --platform=\$BUILDPLATFORM golang:1\.27\.\d+-bookworm@sha256:[0-9a-f]{64} AS go-toolchain$#m')
        ->and($dockerfile->stage('go-toolchain'))->toContain('ENV CGO_ENABLED=0 GOTOOLCHAIN=local GOFLAGS=-trimpath')
        ->and($dockerfile->stage('compose-source'))->toContain('--branch v5.6.0 https://github.com/docker/compose.git .')
        ->and($dockerfile->stage('compose-source'))->toContain('go get golang.org/x/net@v0.60.0')
        ->and($dockerfile->stage('compose-source'))->toMatch('/^[0-9a-f]{64}  go\.mod$/m')
        ->and($dockerfile->stage('compose-source'))->toMatch('/^[0-9a-f]{64}  go\.sum$/m')
        ->and($dockerfile->stage('age-source'))->toContain('--branch v1.3.2 https://github.com/FiloSottile/age.git .')
        ->and($dockerfile->stage('tools'))->toStartWith('FROM --platform=$BUILDPLATFORM ');

    foreach (['compose' => 'v5.6.0', 'age' => 'v1.3.2'] as $stage => $version) {
        expect($dockerfile->stage($stage))->toContain('ARG TARGETARCH')
            ->and($dockerfile->stage($stage))->toContain('GOOS=linux GOARCH="${TARGETARCH}" GOPROXY=off go build')
            ->and($dockerfile->stage($stage))->toContain('-s -w -buildid= -X ')
            ->and($dockerfile->stage($stage))->toContain(".Version={$version}\"");
    }
});

it('pins every external image it builds from or copies from as tag@sha256 (multi-arch index digest)', function (): void {
    $dockerfile = DockerfileStages::fromFile(base_path('docker/app/Dockerfile'));
    preg_match_all('/^FROM\s+(?:--\S+\s+)*(\S+)(?:\s+AS\s+(\S+))?/mi', $dockerfile->text, $froms, PREG_SET_ORDER);
    preg_match_all('/^COPY\s+(?:--\S+\s+)*--from=(\S+)/mi', $dockerfile->text, $copies);
    $internal = ['scratch', 'downloads-${TARGETARCH}', ...array_filter(array_column($froms, 2))];
    $external = array_values(array_diff([...array_column($froms, 1), ...$copies[1]], $internal));

    expect($external)->toHaveCount(6);
    foreach ($external as $reference) {
        expect($reference)->toMatch('/^[a-z0-9][a-z0-9._\/-]*:[\w.-]+@sha256:[a-f0-9]{64}$/', $reference);
    }
    expect(array_unique(array_filter($external, fn (string $reference): bool => str_starts_with($reference, 'composer:'))))->toHaveCount(1);
});

it('ships no cosign binary: fsa and install.sh verify with the pinned cosign image (ADR 0070, ADR 0071)', function (): void {
    expect((string) file_get_contents(base_path('docker/app/Dockerfile')))->not->toMatch('/cosign/i');
});

it('installs the Postgres 16 client from PGDG through a deb822 source without a fixed architecture', function (): void {
    $dockerfile = DockerfileStages::fromFile(base_path('docker/app/Dockerfile'))->text;

    expect($dockerfile)->toMatch('#^ADD --checksum=sha256:[0-9a-f]{64} https://www\.postgresql\.org/media/keys/ACCC4CF8\.asc#m')
        ->and($dockerfile)->toContain('/etc/apt/sources.list.d/pgdg.sources')
        ->and($dockerfile)->toContain('Types: deb')
        ->and($dockerfile)->toContain('URIs: https://apt.postgresql.org/pub/repos/apt')
        ->and($dockerfile)->toContain('Suites: %s-pgdg')
        ->and($dockerfile)->toContain('Signed-By: /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc')
        ->and($dockerfile)->not->toContain('Architectures:')
        ->and($dockerfile)->toMatch('/apt-get install [^\n]*postgresql-client-16/');
});

describe('entrypoint roles', function (): void {
    beforeEach(function (): void {
        $this->harness = new ScriptHarness(base_path());
    });

    afterEach(function (): void {
        $this->harness->cleanUp();
    });

    it('caches config and migrates on request for the app roles', function (): void {
        expect($this->harness->run('docker/app/entrypoint.sh', ['frankenphp', 'run'], ['RUN_MIGRATIONS' => 'true']))->toBe([
            'php artisan optimize --quiet',
            'php artisan migrate --force --isolated=75 --no-interaction',
            'frankenphp run',
        ])->and($this->harness->run('docker/app/entrypoint.sh', ['php', 'artisan', 'horizon']))->toBe([
            'php artisan optimize --quiet',
            'php artisan horizon',
        ]);
    });

    it('waits while another container migrates (exit 75), then migrates itself (ADR 0079)', function (): void {
        $this->harness->exitCodes('php', [0, 75, 75, 0]);

        expect($this->harness->run('docker/app/entrypoint.sh', ['frankenphp', 'run'], ['RUN_MIGRATIONS' => 'true']))->toBe([
            'php artisan optimize --quiet',
            'php artisan migrate --force --isolated=75 --no-interaction',
            'sleep 5',
            'php artisan migrate --force --isolated=75 --no-interaction',
            'sleep 5',
            'php artisan migrate --force --isolated=75 --no-interaction',
            'frankenphp run',
        ]);
    });

    it('never starts the app when migrating fails or the other migrator does not finish', function (array $codes, int $exit, int $migrations): void {
        $this->harness->exitCodes('php', [0, ...$codes]);
        $process = $this->harness->attempt('docker/app/entrypoint.sh', ['frankenphp', 'run'], ['RUN_MIGRATIONS' => 'true']);
        $calls = $this->harness->calls();

        expect($process->getExitCode())->toBe($exit)
            ->and($calls)->not->toContain('frankenphp run')
            ->and(array_count_values($calls)['php artisan migrate --force --isolated=75 --no-interaction'] ?? 0)->toBe($migrations);
    })->with([
        'a failing migration' => [[1], 1, 1],
        'still locked after 60 attempts' => [array_fill(0, 60, 75), 75, 60],
    ]);

    it('skips optimize and migrations for fsa and the backup daemon', function (array $command, string $call): void {
        $command = array_map(fn (string $part): string => str_replace('{bin}', $this->harness->bin, $part), $command);

        expect($this->harness->run('docker/app/entrypoint.sh', $command, ['RUN_MIGRATIONS' => 'true']))->toBe([$call]);
    })->with([
        'fsa' => [['fsa', 'status', '--json'], 'php /var/www/html/artisan fsa:status --json'],
        'backup daemon' => [['fsa', 'backup-daemon'], 'php /var/www/html/artisan fsa:backup-daemon'],
        'fsa by path' => [['{bin}/fsa', 'version'], 'php /var/www/html/artisan fsa:version'],
        'artisan fsa command' => [['php', 'artisan', 'fsa:backup-daemon'], 'php artisan fsa:backup-daemon'],
    ]);
});

describe('fsa wrapper', function (): void {
    beforeEach(function (): void {
        $this->harness = new ScriptHarness(base_path());
    });

    afterEach(function (): void {
        $this->harness->cleanUp();
    });

    it('runs php artisan fsa:<command> and lists the commands without one', function (array $arguments, string $call): void {
        expect($this->harness->run('docker/app/fsa', $arguments))->toBe([$call]);
    })->with([
        'command with options' => [['install', '--mode', 'local', '--json'], 'php /var/www/html/artisan fsa:install --mode local --json'],
        'no command' => [[], 'php /var/www/html/artisan list fsa'],
        'list options only' => [['--format=json'], 'php /var/www/html/artisan list fsa --format=json'],
        'list as JSON' => [['--json'], 'php /var/www/html/artisan list fsa --format=json'],
        'flag before the command' => [['--json', 'status'], 'php /var/www/html/artisan fsa:status --json'],
        'flags before and after' => [['--yes', 'uninstall', '--json'], 'php /var/www/html/artisan fsa:uninstall --yes --json'],
        'value flags before the command' => [['--dir', '/srv/fsa', '--secrets-file', 'link.json', 'login-link', 'a@b.test'], 'php /var/www/html/artisan fsa:login-link --dir /srv/fsa --secrets-file link.json a@b.test'],
        'install --version after a leading flag' => [['--json', 'install', '--version', '2.0.0'], 'php /var/www/html/artisan fsa:install --json --release 2.0.0'],
        'value flag before the command' => [['--behind-proxy', 'traefik', 'install', '--json'], 'php /var/www/html/artisan fsa:install --behind-proxy traefik --json'],
        'optional-value flag before the command' => [['--port', '8080', '--url', 'http://x.test', 'install'], 'php /var/www/html/artisan fsa:install --port 8080 --url http://x.test'],
        'flag=value before the command' => [['--behind-proxy=traefik', 'install'], 'php /var/www/html/artisan fsa:install --behind-proxy=traefik'],
        'install --version before the command' => [['--version', '2.0.0', 'install'], 'php /var/www/html/artisan fsa:install --release 2.0.0'],
        'logs value flag before the command' => [['--tail', '50', 'logs', 'app'], 'php /var/www/html/artisan fsa:logs --tail 50 app'],
        'positional after --' => [['login-link', '--', '--json'], 'php /var/www/html/artisan fsa:login-link -- --json'],
        'image role' => [['backup-daemon'], 'php /var/www/html/artisan fsa:backup-daemon'],
    ]);

    it('answers an unknown command with exit 2 and an unknown_command error, never artisan', function (array $arguments, bool $json): void {
        $process = $this->harness->attempt('docker/app/fsa', $arguments);
        $command = array_values(array_filter($arguments, fn (string $arg): bool => ! str_starts_with($arg, '-')))[0];

        expect($process->getExitCode())->toBe(2)
            ->and($this->harness->calls())->toBe([]);
        if (! $json) {
            expect($process->getOutput())->toBe('')
                ->and($process->getErrorOutput())->toContain("fsa: unknown command: {$command}")
                ->and($process->getErrorOutput())->toContain('Available commands: adopt, backup, config, demo, doctor, install, login-link');

            return;
        }
        expect(json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR))->toBe([
            'schema_version' => 1, 'command' => $command, 'status' => 'failed', 'exit_code' => 2, 'changed' => false, 'data' => [], 'secrets' => [], 'warnings' => [],
            'errors' => [[
                'code' => 'unknown_command',
                'message' => "Unknown fsa command: {$command}",
                'hint' => 'Available commands: adopt, backup, config, demo, doctor, install, login-link, logs, reset, restore, rollback, status, tunnel, uninstall, upgrade, version. Run fsa without arguments to list them; put flags after the command or write them as --flag=value.',
                'retryable' => false,
            ]],
        ])->and($process->getErrorOutput())->toBe('');
    })->with([
        'not an fsa command' => [['migrate', '--json'], true], // `upgrade` and `rollback` shipped with U18 (ADR 0086)
        'typo with a leading flag' => [['--json', 'stauts'], true],
        'quote in the name' => [['--json', 'x"y\\z'], true],
        'two words' => [['--json', 'logs status'], true],
        'human' => [['migrate'], false], // `restore` shipped with U17 (ADR 0085), `reset` with U23 (ADR 0088), `upgrade` and `rollback` with U18 (ADR 0086)
        'value of a flag no command knows' => [['--json', '--colour', 'red', 'install'], true],
    ]);

    it('knows exactly the registered fsa commands (users) and the image role entry points', function (): void {
        $wrapper = (string) file_get_contents(base_path('docker/app/fsa'));
        preg_match("/^commands='([a-z -]+)'$/m", $wrapper, $commands);
        preg_match("/^roles='([a-z -]+)'$/m", $wrapper, $roles);
        $registered = collect(Artisan::all())->filter(fn ($command, string $name): bool => str_starts_with($name, 'fsa:'));
        $visible = $registered->reject(fn ($command): bool => $command->isHidden())->keys()->map(fn (string $name): string => substr($name, 4))->sort()->values()->all();
        $hidden = $registered->filter(fn ($command): bool => $command->isHidden())->keys()->map(fn (string $name): string => substr($name, 4))->all();

        expect(explode(' ', $commands[1] ?? ''))->toBe($visible)
            ->and(explode(' ', $roles[1] ?? ''))->toBe(['backup-daemon', 'restore-prepare', 'upgrade-child'])
            ->and(array_diff($hidden, ['backup-daemon', 'restore-prepare', 'upgrade-child']))->toBe([]);
    });

    it('knows every value-taking option of the fsa commands, so a flag before the command keeps its value', function (): void {
        preg_match("/^value_flags='([^']+)'$/m", (string) file_get_contents(base_path('docker/app/fsa')), $listed);

        expect(preg_split('/\\s+/', trim($listed[1] ?? '')))->toBe(FsaCommandFlags::valueFlags());
    });
});
