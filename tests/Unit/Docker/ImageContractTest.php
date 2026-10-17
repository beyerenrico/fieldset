<?php

declare(strict_types=1);

use Tests\Support\Docker\DockerfileStages;
use Tests\Support\Docker\DockerIgnore;
use Tests\Support\Docker\ScriptHarness;

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
    'php package source' => ['packages/form-schema-php/src/Support/SpecPaths.php', true],
    'js package source' => ['packages/form-schema-js/src/index.ts', true],
    'storage skeleton' => ['storage/framework/views/.gitignore', true],
    'bootstrap cache skeleton' => ['bootstrap/cache/.gitignore', true],
    'image scripts' => ['docker/app/entrypoint.sh', true],
    'manifest schema' => ['distribution/manifest.schema.json', true],
    'fsa shim' => ['distribution/shim.sh', true],
    'install script' => ['distribution/install.sh', false],
    'release inputs' => ['distribution/release.json', false],
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
    'workspace node modules' => ['packages/form-schema-js/node_modules/x/index.js', false],
    'php package tests' => ['packages/form-schema-php/tests/Pest.php', false],
    'js package tests' => ['packages/form-schema-js/test/evaluate.test.ts', false],
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

it('pins every ops binary per architecture by SHA-256 (KTD8)', function (string $arch, array $tokens): void {
    $stage = DockerfileStages::fromFile(base_path('docker/app/Dockerfile'))->stage('downloads-'.$arch);
    $series = [
        'docker' => '#download\.docker\.com/linux/static/stable/'.$tokens[0].'/docker-29\.9\.\d+\.tgz#',
        'compose' => '#docker/compose/releases/download/v5\.6\.\d+/docker-compose-linux-'.$tokens[0].'\s#',
        'cosign' => '#sigstore/cosign/releases/download/v3\.1\.\d+/cosign-linux-'.$tokens[1].'\s#',
        'age' => '#FiloSottile/age/releases/download/v1\.3\.\d+/age-v1\.3\.\d+-linux-'.$tokens[1].'\.tar\.gz#',
    ];

    preg_match_all('/^ADD --checksum=sha256:([0-9a-f]{64}) (\S+)/m', $stage, $adds, PREG_SET_ORDER);

    expect($adds)->toHaveCount(4)
        ->and(array_unique(array_column($adds, 1)))->toHaveCount(4);
    foreach ($series as $tool => $pattern) {
        expect($stage)->toMatch($pattern, "{$tool} for {$arch}");
    }
})->with([
    'amd64' => ['amd64', ['x86_64', 'amd64']],
    'arm64' => ['arm64', ['aarch64', 'arm64']],
]);

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
            'php artisan migrate --force --no-interaction',
            'frankenphp run',
        ])->and($this->harness->run('docker/app/entrypoint.sh', ['php', 'artisan', 'horizon']))->toBe([
            'php artisan optimize --quiet',
            'php artisan horizon',
        ]);
    });

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
    ]);
});
